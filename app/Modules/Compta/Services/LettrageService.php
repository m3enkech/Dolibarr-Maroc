<?php

namespace App\Modules\Compta\Services;

use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lettrage : rapprocher les débits et crédits d'un compte de tiers
 * (3421 clients, 4411 fournisseurs…). Un groupe lettré porte un code
 * (AAA, AAB…) et doit être parfaitement équilibré.
 *
 * ÉQUILIBRÉ PAR TIERS, PAS SEULEMENT PAR COMPTE. Un groupe ne réunit que des
 * lignes d'UN même tiers (ou, sur un compte sans tiers, que des lignes sans
 * tiers). EncoursService lit le solde d'un client sur ses lignes NON lettrées,
 * le relevé sur toutes : les deux ne disent la même chose que si ce qu'on
 * lettre s'annule chez CE client. Lettrer la facture de E avec le règlement
 * de F soldait E et F dans la liste quand leurs relevés disaient +1 200 et
 * −1 200 ; lettrer le règlement de D avec une ligne d'à-nouveau sans tiers
 * effaçait sa dette de la liste quand son relevé imprimait « Solde en votre
 * faveur 5 000,00 DH ».
 */
class LettrageService
{
    private const EPSILON = 0.005;

    /** Lettrage manuel d'une sélection de lignes. */
    public function lettrer(array $ligneIds): array
    {
        return DB::transaction(function () use ($ligneIds) {
            // whereHas('ecriture') applique le scope tenant : les lignes d'un
            // autre tenant sont invisibles, donc introuvables.
            $lignes = EcritureLigne::whereIn('id', $ligneIds)
                ->whereHas('ecriture')
                ->lockForUpdate()
                ->get();

            if ($lignes->count() !== count(array_unique($ligneIds))) {
                throw ValidationException::withMessages([
                    'lignes' => 'Une ou plusieurs lignes sont introuvables.',
                ]);
            }

            if ($lignes->count() < 2) {
                throw ValidationException::withMessages([
                    'lignes' => 'Le lettrage demande au moins deux lignes (une facture et son règlement).',
                ]);
            }

            if ($lignes->pluck('compte_id')->unique()->count() > 1) {
                throw ValidationException::withMessages([
                    'lignes' => 'Toutes les lignes doivent appartenir au même compte.',
                ]);
            }

            // NULL compris dans la comparaison : une ligne sans tiers face à
            // celle d'un client est un mélange, pas un « même tiers ». Des
            // messages en français, comme le reste de l'écran de lettrage : la
            // comptabilité ne se traduit pas, par décision.
            if ($lignes->map(fn ($l) => $l->tiers_id === null ? null : (int) $l->tiers_id)->unique()->count() > 1) {
                throw ValidationException::withMessages([
                    'lignes' => $lignes->contains(fn ($l) => $l->tiers_id === null)
                        ? 'Une ligne sans tiers (un à-nouveau global, par exemple) ne se lettre pas avec celle d\'un tiers : '
                            .'saisissez d\'abord le solde d\'ouverture du tiers depuis sa fiche, puis lettrez-le.'
                        : 'Toutes les lignes doivent concerner le même tiers.',
                ]);
            }

            if ($lignes->contains(fn ($l) => $l->lettrage !== null)) {
                throw ValidationException::withMessages([
                    'lignes' => 'Certaines lignes sont déjà lettrées — délettrez-les d\'abord.',
                ]);
            }

            $totalDebit = round($lignes->sum(fn ($l) => (float) $l->debit), 2);
            $totalCredit = round($lignes->sum(fn ($l) => (float) $l->credit), 2);

            if (abs($totalDebit - $totalCredit) > self::EPSILON) {
                throw ValidationException::withMessages([
                    'lignes' => sprintf(
                        'Sélection non équilibrée : débit %.2f ≠ crédit %.2f (écart %.2f).',
                        $totalDebit,
                        $totalCredit,
                        $totalDebit - $totalCredit,
                    ),
                ]);
            }

            $code = $this->prochaineLettre($lignes->first()->compte_id);

            EcritureLigne::whereIn('id', $lignes->pluck('id'))->update(['lettrage' => $code]);

            return ['code' => $code, 'lignes' => $lignes->count()];
        });
    }

    /**
     * Lettrage automatique : regroupe les lignes non lettrées du compte par
     * référence d'écriture (FA-…, FF-…) ET par tiers, et lettre chaque groupe
     * équilibré. Nos écritures automatiques partagent la référence entre la
     * facture et ses règlements — le matching est donc exact. Le tiers dans la
     * clé : une référence saisie à la main (OD, reprise) peut se retrouver
     * chez deux tiers, et la même règle que le lettrage manuel s'applique.
     */
    public function lettrageAuto(int $compteId): array
    {
        return DB::transaction(function () use ($compteId) {
            $lignes = EcritureLigne::query()
                ->where('compte_id', $compteId)
                ->whereNull('lettrage')
                ->whereHas('ecriture', fn ($q) => $q->whereNotNull('reference'))
                ->with('ecriture:id,reference')
                ->get();

            $groupes = $lignes->groupBy(fn ($l) => $l->ecriture->reference.'|'.($l->tiers_id ?? ''));
            $lettres = 0;
            $lignesLettrees = 0;

            foreach ($groupes as $groupe) {
                if ($groupe->count() < 2) {
                    continue;
                }

                $debit = round($groupe->sum(fn ($l) => (float) $l->debit), 2);
                $credit = round($groupe->sum(fn ($l) => (float) $l->credit), 2);

                if (abs($debit - $credit) > self::EPSILON || $debit <= 0) {
                    continue; // facture pas encore soldée : on attend le solde
                }

                $code = $this->prochaineLettre($compteId);
                EcritureLigne::whereIn('id', $groupe->pluck('id'))->update(['lettrage' => $code]);

                $lettres++;
                $lignesLettrees += $groupe->count();
            }

            return ['groupes' => $lettres, 'lignes' => $lignesLettrees];
        });
    }

    /** Supprime un lettrage (le groupe redevient rapprochable). */
    public function delettrer(int $compteId, string $code): int
    {
        $ids = EcritureLigne::where('compte_id', $compteId)
            ->where('lettrage', $code)
            ->whereHas('ecriture')
            ->pluck('id');

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'code' => "Aucune ligne lettrée « {$code} » sur ce compte.",
            ]);
        }

        return EcritureLigne::whereIn('id', $ids)->update(['lettrage' => null]);
    }

    /**
     * Les groupes lettrés qui mêlent plusieurs tiers — une ligne sans tiers
     * comptant pour un tiers à part —, posés avant que `lettrer()` ne les
     * refuse. Pour chacun, l'écart que porte chaque tiers : ce dont la liste
     * (lignes non lettrées) et le relevé (toutes) divergent chez lui.
     *
     * Lecture seule, sous le scope d'entreprise du modèle Ecriture : les
     * lignes n'ont pas d'entreprise à elles.
     *
     * @return list<array{compte: string, code: string, lignes: int, tiers: list<array{tiers_id: ?int, nom: ?string, ecart: float}>}>
     */
    public function groupesMelanges(): array
    {
        $groupes = Ecriture::query()
            ->join('ecriture_lignes', 'ecriture_lignes.ecriture_id', '=', 'ecritures.id')
            ->whereNotNull('ecriture_lignes.lettrage')
            ->toBase()
            ->select(['ecriture_lignes.compte_id', 'ecriture_lignes.lettrage'])
            ->selectRaw('COUNT(*) AS lignes')
            ->groupBy('ecriture_lignes.compte_id', 'ecriture_lignes.lettrage')
            ->havingRaw('COUNT(DISTINCT COALESCE(ecriture_lignes.tiers_id, 0)) > 1')
            ->get();

        $codes = Compte::whereIn('id', $groupes->pluck('compte_id')->unique())->pluck('code', 'id');

        return $groupes
            ->map(function ($groupe) use ($codes) {
                $parTiers = EcritureLigne::query()
                    ->where('compte_id', $groupe->compte_id)
                    ->where('lettrage', $groupe->lettrage)
                    ->whereHas('ecriture')
                    ->with('tiers:id,name')
                    ->get()
                    ->groupBy(fn ($l) => $l->tiers_id ?? 0)
                    ->map(fn ($lignes) => [
                        'tiers_id' => $lignes->first()->tiers_id,
                        'nom' => $lignes->first()->tiers?->name,
                        'ecart' => round($lignes->sum(fn ($l) => (float) $l->debit - (float) $l->credit), 2),
                    ])
                    ->values()
                    ->all();

                return [
                    'compte' => (string) ($codes[$groupe->compte_id] ?? $groupe->compte_id),
                    'code' => $groupe->lettrage,
                    'lignes' => (int) $groupe->lignes,
                    'tiers' => $parTiers,
                ];
            })
            ->sortBy(fn ($g) => $g['compte'].'|'.str_pad($g['code'], 8, ' ', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    /** Lignes lettrables d'un compte, avec leur contexte d'écriture. */
    public function lignes(int $compteId, ?int $tiersId, string $statut): Collection
    {
        return EcritureLigne::query()
            ->where('compte_id', $compteId)
            ->when($tiersId, fn ($q) => $q->where('tiers_id', $tiersId))
            ->when($statut === 'non_lettres', fn ($q) => $q->whereNull('lettrage'))
            ->when($statut === 'lettres', fn ($q) => $q->whereNotNull('lettrage'))
            ->whereHas('ecriture')
            ->with(['ecriture:id,numero,journal,date_ecriture,libelle,reference', 'tiers:id,name'])
            ->get()
            ->sortBy([['ecriture.date_ecriture', 'asc'], ['id', 'asc']])
            ->values();
    }

    /** Prochaine lettre libre du compte : AAA, AAB… puis AAAA après ZZZ. */
    private function prochaineLettre(int $compteId): string
    {
        $max = EcritureLigne::where('compte_id', $compteId)
            ->whereNotNull('lettrage')
            ->whereHas('ecriture')
            ->orderByRaw('LENGTH(lettrage) DESC')
            ->orderByDesc('lettrage')
            ->value('lettrage');

        return $max === null ? 'AAA' : self::incrementer($max);
    }

    private static function incrementer(string $code): string
    {
        $lettres = str_split($code);

        for ($i = count($lettres) - 1; $i >= 0; $i--) {
            if ($lettres[$i] !== 'Z') {
                $lettres[$i] = chr(ord($lettres[$i]) + 1);

                return implode('', $lettres);
            }
            $lettres[$i] = 'A';
        }

        return str_repeat('A', count($lettres) + 1);
    }
}
