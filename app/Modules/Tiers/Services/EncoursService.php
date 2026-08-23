<?php

namespace App\Modules\Tiers\Services;

use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Tiers\Models\Tiers;

/**
 * Encours client : ce qu'un client doit réellement à l'entreprise.
 *
 * DÉFINITION RETENUE — solde non lettré du client sur les comptes de créance
 * (3421 Clients + 3425 Effets à recevoir), hors journal des à-nouveaux.
 *
 * Pourquoi le grand livre plutôt que la somme des « restes à payer » des
 * factures : quand une traite est tirée, la créance quitte 3421 pour 3425 et la
 * facture est lettrée — mais elle garde un reste à payer positif à vie, puisque
 * l'encaissement de l'effet ne crée pas de ligne de paiement. Compter les
 * documents produirait donc un encours fantôme permanent. Le grand livre, lui,
 * retombe seul à zéro quand l'effet est encaissé.
 *
 * Le journal AN est exclu : la clôture reporte le solde clients en une ligne
 * agrégée sans tiers_id, qui ne doit pas être comptée deux fois.
 */
class EncoursService
{
    /**
     * Effets à recevoir (CGNC 3425). Le compte clients, lui, n'est PAS codé en
     * dur : il est lu dans le mapping comptable du tenant, que l'entreprise
     * peut remapper. S'en tenir à « 3421 » rendrait le plafond silencieusement
     * inopérant pour toute entreprise ayant changé son compte collectif.
     */
    private const CODE_EFFETS_A_RECEVOIR = '3425';

    public function __construct(private \App\Modules\Compta\Services\ComptaService $compta) {}

    /**
     * Comptes portant la créance client : le compte collectif tel qu'il est
     * réellement mappé (c'est là que ComptaService écrit), plus les effets à
     * recevoir quand ils existent.
     *
     * @return array<int, int>
     */
    private function comptesCreance(): array
    {
        $ids = [$this->compta->compteParDefaut('clients')->id];

        $effets = Compte::where('code', self::CODE_EFFETS_A_RECEVOIR)->first();
        if ($effets !== null) {
            $ids[] = $effets->id;
        }

        return $ids;
    }

    /** Encours d'un client, en dirhams. */
    public function pour(Tiers $tiers): float
    {
        return $this->parTiers([$tiers->id])[$tiers->id] ?? 0.0;
    }

    /**
     * Encours de plusieurs clients en une seule requête.
     *
     * @param  array<int, int>  $tiersIds
     * @return array<int, float>  encours indexé par tiers_id
     */
    public function parTiers(array $tiersIds): array
    {
        if ($tiersIds === []) {
            return [];
        }

        $comptes = $this->comptesCreance();

        if ($comptes === []) {
            return [];
        }

        // whereHas('ecriture') applique le scope tenant : ecriture_lignes n'est
        // pas scopée elle-même, l'omettre agrégerait les clients d'autres
        // entreprises.
        $lignes = EcritureLigne::query()
            ->whereIn('tiers_id', $tiersIds)
            ->whereIn('compte_id', $comptes)
            ->whereNull('lettrage')
            ->whereHas('ecriture', fn ($q) => $q->where('journal', '!=', Ecriture::JOURNAL_A_NOUVEAUX))
            ->selectRaw('tiers_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('tiers_id')
            ->get();

        $encours = [];
        foreach ($lignes as $ligne) {
            $solde = round((float) $ligne->total_debit - (float) $ligne->total_credit, 2);
            $encours[(int) $ligne->tiers_id] = max(0.0, $solde);
        }

        return $encours;
    }

    /**
     * Contrôle du plafond avant d'accorder un crédit.
     *
     * @return array{autorise: bool, encours: float, plafond: ?float, disponible: ?float, depassement: float}
     */
    public function verifier(Tiers $tiers, float $montantCredit): array
    {
        $encours = $this->pour($tiers);
        $plafond = $tiers->plafond_credit !== null ? (float) $tiers->plafond_credit : null;

        if ($plafond === null) {
            return [
                'autorise' => true, 'encours' => $encours, 'plafond' => null,
                'disponible' => null, 'depassement' => 0.0,
            ];
        }

        $apres = round($encours + $montantCredit, 2);
        $depassement = round(max(0, $apres - $plafond), 2);

        return [
            'autorise' => $depassement <= 0.009,
            'encours' => $encours,
            'plafond' => $plafond,
            'disponible' => round(max(0, $plafond - $encours), 2),
            'depassement' => $depassement,
        ];
    }
}
