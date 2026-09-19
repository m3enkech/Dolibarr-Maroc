<?php

namespace App\Modules\Compta\Services;

use App\Core\Tenancy\TenantScope;
use App\Modules\Compta\Models\Ecriture;
use Illuminate\Support\Facades\DB;

/**
 * Remet les journaux comptables en numérotation chronologique et continue.
 *
 * POURQUOI. Une série de journal appartient à son EXERCICE : une pièce de 2022
 * se numérote dans la série 2022. `ComptaService` ne passait pas l'année à la
 * séquence, qui retombait sur l'année courante — la reprise Zoho a donc écrit
 * « VT-2026-01195 » sur une facture du 28 avril 2022. La date était juste, le
 * numéro non. Deux mille cinquante-neuf écritures dans ce cas.
 *
 * Et une fois les années sorties de la série 2026, celle-ci reste trouée et
 * dans le désordre. Or l'administration fiscale marocaine attend une
 * numérotation **chronologique et continue** par journal et par exercice : des
 * trous ne se justifient pas, un ordre qui ne suit pas les dates non plus.
 * On renumérote donc TOUT ce qui est demandé, pas seulement ce qui saute aux
 * yeux — à moitié fait, le journal reste irrégulier.
 *
 * DEUX PASSES, ET C'EST OBLIGATOIRE. `ecritures` porte un index unique sur
 * (tenant_id, numero). Renuméroter en place ferait entrer en collision une
 * écriture avec le numéro qu'une autre n'a pas encore quitté. On déplace donc
 * d'abord tout le lot vers des valeurs temporaires — dérivées de
 * l'identifiant, donc uniques par construction — puis on pose les définitifs.
 *
 * LES COMPTEURS SUIVENT. Sans remettre `sequences` au niveau atteint, la
 * prochaine écriture repartirait à un numéro déjà pris et la transaction
 * échouerait sur l'index unique.
 */
class RenumerotationService
{
    /** Assez court pour tenir dans `numero` (20 caractères), assez rare pour ne heurter personne. */
    private const PREFIXE_TEMPORAIRE = '~';

    /**
     * @param  list<string>  $journaux  vide = tous
     * @return array{total: int, series: list<array<string, mixed>>}
     */
    public function renumeroter(array $journaux = [], bool $simulation = false): array
    {
        $tenantId = TenantScope::currentTenantId();

        if ($tenantId === null) {
            throw new \RuntimeException('La renumérotation requiert un tenant courant.');
        }

        $rapport = ['total' => 0, 'series' => []];

        $travail = function () use ($journaux, $simulation, $tenantId, &$rapport): void {
            $series = [];
            $total = 0;

            foreach ($this->lots($journaux) as [$journal, $annee, $ecritures]) {
                $plan = $this->planifier($journal, $annee, $ecritures);

                if ($plan['changements'] === 0) {
                    continue;
                }

                $total += $plan['changements'];
                $series[] = [
                    'journal' => $journal,
                    'annee' => $annee,
                    'pieces' => $ecritures->count(),
                    'changements' => $plan['changements'],
                    'premier' => $plan['premier'],
                    'dernier' => $plan['dernier'],
                ];

                // Même en simulation on ÉCRIT, puis on annule : c'est le seul
                // moyen d'éprouver pour de vrai l'index unique et la double
                // passe, plutôt que de promettre un résultat qu'on n'a pas
                // essayé.
                $this->appliquer($plan['attributions']);
                $this->recalerCompteur($tenantId, $journal, $annee, $ecritures->count());
            }

            $rapport = ['total' => $total, 'series' => $series];

            if ($simulation) {
                throw new SimulationDeRenumerotation;
            }
        };

        try {
            DB::transaction($travail);
        } catch (SimulationDeRenumerotation) {
            // Annulation voulue : le rapport, lui, a survécu par référence.
        }

        return $rapport;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Les écritures groupées par journal puis par année de PIÈCE, chacune dans
     * l'ordre où le journal doit les porter : la date, puis l'ordre de saisie
     * pour départager une même journée.
     *
     * @param  list<string>  $journaux
     * @return list<array{0: string, 1: int, 2: \Illuminate\Support\Collection}>
     */
    private function lots(array $journaux): array
    {
        $ecritures = Ecriture::query()
            ->when($journaux !== [], fn ($q) => $q->whereIn('journal', $journaux))
            ->orderBy('date_ecriture')
            ->orderBy('id')
            ->get(['id', 'journal', 'numero', 'date_ecriture']);

        $lots = [];

        foreach ($ecritures as $ecriture) {
            $annee = (int) $ecriture->date_ecriture->format('Y');
            $lots["{$ecriture->journal}|{$annee}"][] = $ecriture;
        }

        return collect($lots)
            ->map(function ($groupe, $cle) {
                [$journal, $annee] = explode('|', $cle);

                return [$journal, (int) $annee, collect($groupe)];
            })
            ->values()
            ->all();
    }

    /**
     * Ce que deviendrait chaque écriture de la série, sans rien écrire.
     *
     * @return array{attributions: list<array{id: int, numero: string}>, changements: int, premier: string, dernier: string}
     */
    private function planifier(string $journal, int $annee, $ecritures): array
    {
        $attributions = [];
        $changements = 0;
        $rang = 0;

        foreach ($ecritures as $ecriture) {
            $numero = sprintf('%s-%d-%05d', $journal, $annee, ++$rang);

            $attributions[] = ['id' => $ecriture->id, 'numero' => $numero];

            if ($ecriture->numero !== $numero) {
                $changements++;
            }
        }

        return [
            'attributions' => $attributions,
            'changements' => $changements,
            'premier' => $attributions[0]['numero'] ?? '',
            'dernier' => end($attributions)['numero'] ?? '',
        ];
    }

    /** @param  list<array{id: int, numero: string}>  $attributions */
    private function appliquer(array $attributions): void
    {
        // Passe 1 : tout le lot s'écarte vers des valeurs que personne ne peut
        // porter — sans quoi la première écriture réclamerait un numéro que la
        // seconde n'a pas encore libéré, et l'index unique la refuserait.
        foreach ($attributions as $attribution) {
            Ecriture::whereKey($attribution['id'])
                ->update(['numero' => self::PREFIXE_TEMPORAIRE.$attribution['id']]);
        }

        // Passe 2 : les numéros définitifs, sur un terrain dégagé.
        foreach ($attributions as $attribution) {
            Ecriture::whereKey($attribution['id'])->update(['numero' => $attribution['numero']]);
        }
    }

    /**
     * Le compteur de la série rejoint le dernier numéro posé.
     *
     * Sans cela la prochaine écriture de l'année repartirait d'un numéro déjà
     * pris, et échouerait sur l'index unique — au pire moment, celui d'une
     * validation de facture.
     */
    private function recalerCompteur(int $tenantId, string $journal, int $annee, int $dernier): void
    {
        $existe = DB::table('sequences')
            ->where('tenant_id', $tenantId)
            ->where('code', $journal)
            ->where('year', $annee)
            ->exists();

        if ($existe) {
            DB::table('sequences')
                ->where('tenant_id', $tenantId)
                ->where('code', $journal)
                ->where('year', $annee)
                ->update(['counter' => $dernier, 'updated_at' => now()]);

            return;
        }

        DB::table('sequences')->insert([
            'tenant_id' => $tenantId,
            'code' => $journal,
            'year' => $annee,
            'counter' => $dernier,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
