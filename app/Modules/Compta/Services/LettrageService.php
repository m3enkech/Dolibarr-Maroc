<?php

namespace App\Modules\Compta\Services;

use App\Modules\Compta\Models\EcritureLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lettrage : rapprocher les débits et crédits d'un compte de tiers
 * (3411 clients, 4411 fournisseurs…). Un groupe lettré porte un code
 * (AAA, AAB…) et doit être parfaitement équilibré.
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
     * référence d'écriture (FA-…, FF-…) et lettre chaque groupe équilibré.
     * Nos écritures automatiques partagent la référence entre la facture et
     * ses règlements — le matching est donc exact.
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

            $groupes = $lignes->groupBy(fn ($l) => $l->ecriture->reference);
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
