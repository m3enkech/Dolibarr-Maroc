<?php

namespace App\Modules\Compta\Services;

use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Models\Exercice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clôture d'exercice (année civile, norme marocaine) :
 *   1. Écriture de détermination du résultat (OD, 31/12) : les classes 6 et 7
 *      sont soldées vers 1161 (bénéfice) ou 1162 (perte).
 *   2. À-nouveaux (AN, 01/01 suivant) : report des soldes de bilan (classes 1-5).
 *   3. L'exercice est verrouillé — irréversible, comme l'exige la piste d'audit.
 */
class ClotureService
{
    private const EPSILON = 0.005;

    public function __construct(private ComptaService $compta) {}

    /** Vue d'ensemble des exercices : clôturés (figés) et ouverts (calculés). */
    public function exercices(): Collection
    {
        $this->compta->initialiserPlanComptable();

        $clotures = Exercice::with(['ecritureResultat:id,numero', 'ecritureAn:id,numero'])
            ->get()
            ->keyBy('annee');

        $minDate = Ecriture::min('date_ecriture');
        $premiereAnnee = $minDate ? (int) substr($minDate, 0, 4) : now()->year;
        $annees = collect(range(min($premiereAnnee, ...$clotures->keys()->push(now()->year)->all()), now()->year))
            ->sort()
            ->values();

        return $annees->map(function (int $annee) use ($clotures) {
            $cloture = $clotures->get($annee);

            if ($cloture) {
                return [
                    'annee' => $annee,
                    'statut' => 'cloture',
                    'resultat' => (string) $cloture->resultat,
                    'cloture_at' => $cloture->cloture_at?->format('Y-m-d H:i'),
                    'ecriture_resultat' => $cloture->ecritureResultat?->numero,
                    'ecriture_an' => $cloture->ecritureAn?->numero,
                ];
            }

            [$produits, $charges] = $this->mouvementsExploitation($annee);

            return [
                'annee' => $annee,
                'statut' => 'ouvert',
                'produits' => number_format($produits, 2, '.', ''),
                'charges' => number_format($charges, 2, '.', ''),
                'resultat' => number_format(round($produits - $charges, 2), 2, '.', ''),
            ];
        });
    }

    public function cloturer(int $annee): Exercice
    {
        $this->compta->initialiserPlanComptable();

        if ($annee > now()->year) {
            throw ValidationException::withMessages([
                'annee' => 'Impossible de clôturer un exercice futur.',
            ]);
        }

        if (Exercice::where('annee', $annee)->exists()) {
            throw ValidationException::withMessages([
                'annee' => "L'exercice {$annee} est déjà clôturé.",
            ]);
        }

        // Clôture chronologique : toute année antérieure mouvementée doit être close.
        $minDate = Ecriture::min('date_ecriture');
        if ($minDate !== null) {
            for ($precedente = (int) substr($minDate, 0, 4); $precedente < $annee; $precedente++) {
                $mouvementee = Ecriture::whereBetween('date_ecriture', ["{$precedente}-01-01", "{$precedente}-12-31"])->exists();

                if ($mouvementee && ! Exercice::where('annee', $precedente)->exists()) {
                    throw ValidationException::withMessages([
                        'annee' => "Clôturez d'abord l'exercice {$precedente} (des écritures y figurent encore).",
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($annee) {
            $finExercice = "{$annee}-12-31";
            $ouvertureSuivant = ($annee + 1).'-01-01';

            // 1. Solder les classes 6 et 7 vers le résultat.
            $soldes = $this->soldesParCompte($finExercice);
            $lignesResultat = [];
            $resultat = 0.0;

            foreach ($soldes as $solde) {
                if (! in_array($solde['compte']->classe, [6, 7], true) || abs($solde['solde']) < self::EPSILON) {
                    continue;
                }

                // Écriture inverse du solde pour ramener le compte à zéro.
                $lignesResultat[] = [
                    'compte' => $solde['compte'],
                    'debit' => $solde['solde'] < 0 ? -$solde['solde'] : 0,
                    'credit' => $solde['solde'] > 0 ? $solde['solde'] : 0,
                ];
                $resultat = round($resultat - $solde['solde'], 2); // produits − charges
            }

            $ecritureResultat = null;

            if ($lignesResultat !== []) {
                if (abs($resultat) >= self::EPSILON) {
                    $lignesResultat[] = [
                        'compte' => $this->compte($resultat > 0 ? '1161' : '1162'),
                        'debit' => $resultat < 0 ? -$resultat : 0,
                        'credit' => $resultat > 0 ? $resultat : 0,
                    ];
                }

                $ecritureResultat = $this->compta->ecrire(
                    Ecriture::JOURNAL_DIVERS,
                    $finExercice,
                    "Détermination du résultat — exercice {$annee}",
                    $lignesResultat,
                    "CLOTURE-{$annee}",
                );
            }

            // 2. À-nouveaux : report des soldes de bilan (les 6/7 sont désormais à zéro).
            $lignesAn = [];

            foreach ($this->soldesParCompte($finExercice) as $solde) {
                if (abs($solde['solde']) < self::EPSILON) {
                    continue;
                }

                $lignesAn[] = [
                    'compte' => $solde['compte'],
                    'debit' => $solde['solde'] > 0 ? $solde['solde'] : 0,
                    'credit' => $solde['solde'] < 0 ? -$solde['solde'] : 0,
                ];
            }

            $ecritureAn = null;

            if ($lignesAn !== []) {
                $ecritureAn = $this->compta->ecrire(
                    Ecriture::JOURNAL_A_NOUVEAUX,
                    $ouvertureSuivant,
                    'À-nouveaux — ouverture '.($annee + 1),
                    $lignesAn,
                    "AN-{$annee}",
                );
            }

            return Exercice::create([
                'annee' => $annee,
                'resultat' => $resultat,
                'ecriture_resultat_id' => $ecritureResultat?->id,
                'ecriture_an_id' => $ecritureAn?->id,
                'cloture_at' => now(),
            ]);
        });
    }

    /**
     * Soldes (débit − crédit) par compte sur la période OUVERTE uniquement :
     * depuis le 01/01 suivant la dernière clôture (les à-nouveaux y reportent
     * déjà tout l'historique — compter les années closes doublerait les soldes).
     */
    private function soldesParCompte(string $jusquA): Collection
    {
        $derniereCloture = Exercice::max('annee');
        $depuis = $derniereCloture !== null ? (((int) $derniereCloture + 1).'-01-01') : null;

        $totaux = EcritureLigne::query()
            ->selectRaw('compte_id, SUM(debit) - SUM(credit) as solde')
            ->whereHas('ecriture', fn ($q) => $q
                ->whereDate('date_ecriture', '<=', $jusquA)
                ->when($depuis, fn ($qq) => $qq->whereDate('date_ecriture', '>=', $depuis)))
            ->groupBy('compte_id')
            ->get();

        $comptes = Compte::whereIn('id', $totaux->pluck('compte_id'))->get()->keyBy('id');

        return $totaux
            ->map(fn ($row) => [
                'compte' => $comptes[$row->compte_id],
                'solde' => round((float) $row->solde, 2),
            ])
            ->sortBy(fn ($s) => $s['compte']->code)
            ->values();
    }

    /** Mouvements de l'année sur les classes 6 et 7 → [produits, charges]. */
    private function mouvementsExploitation(int $annee): array
    {
        $rows = EcritureLigne::query()
            ->selectRaw('comptes.classe, SUM(ecriture_lignes.credit) - SUM(ecriture_lignes.debit) as net')
            ->join('comptes', 'comptes.id', '=', 'ecriture_lignes.compte_id')
            ->whereIn('comptes.classe', [6, 7])
            ->whereHas('ecriture', fn ($q) => $q->whereBetween('date_ecriture', ["{$annee}-01-01", "{$annee}-12-31"]))
            ->groupBy('comptes.classe')
            ->pluck('net', 'classe');

        $produits = round((float) ($rows[7] ?? 0), 2);
        $charges = round(-(float) ($rows[6] ?? 0), 2);

        return [$produits, $charges];
    }

    private function compte(string $code): Compte
    {
        return Compte::where('code', $code)->firstOrFail();
    }
}
