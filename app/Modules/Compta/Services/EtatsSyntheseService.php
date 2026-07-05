<?php

namespace App\Modules\Compta\Services;

use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Models\Exercice;
use Illuminate\Support\Collection;

/**
 * États de synthèse CGNC (version simplifiée) :
 *   - CPC : Compte de Produits et Charges (résultat par niveau) ;
 *   - Bilan : Actif / Passif équilibrés.
 * Calculés depuis les soldes de comptes de la période ouverte (les à-nouveaux
 * portent l'historique après clôture).
 */
class EtatsSyntheseService
{
    public function __construct(private ComptaService $compta) {}

    public function calculer(?string $au = null): array
    {
        $this->compta->initialiserPlanComptable();
        $soldes = $this->soldesParCompte($au);

        $cpc = $this->cpc($soldes);
        $bilan = $this->bilan($soldes, (float) $cpc['resultat_net']);

        return ['cpc' => $cpc, 'bilan' => $bilan];
    }

    /** Soldes (débit − crédit) par compte sur la période ouverte. */
    private function soldesParCompte(?string $au): Collection
    {
        $derniereCloture = Exercice::max('annee');
        $du = $derniereCloture !== null ? (((int) $derniereCloture + 1).'-01-01') : null;

        $rows = EcritureLigne::query()
            ->selectRaw('compte_id, SUM(debit) - SUM(credit) as solde')
            ->whereHas('ecriture', fn ($q) => $q
                ->when($du, fn ($q) => $q->whereDate('date_ecriture', '>=', $du))
                ->when($au, fn ($q) => $q->whereDate('date_ecriture', '<=', $au)))
            ->groupBy('compte_id')
            ->get();

        $comptes = Compte::whereIn('id', $rows->pluck('compte_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'code' => $comptes[$r->compte_id]->code,
            'classe' => $comptes[$r->compte_id]->classe,
            'solde' => round((float) $r->solde, 2), // débit − crédit
        ]);
    }

    /** Somme (débit − crédit) des comptes dont le code commence par $prefix. */
    private function parPrefixe(Collection $soldes, string $prefix): float
    {
        return round($soldes->filter(fn ($s) => str_starts_with($s['code'], $prefix))->sum('solde'), 2);
    }

    private function cpc(Collection $soldes): array
    {
        // Produits = soldes créditeurs (−(d−c)) ; charges = soldes débiteurs (d−c).
        $produitsExploitation = -$this->parPrefixe($soldes, '71');
        $chargesExploitation = $this->parPrefixe($soldes, '61');
        $resultatExploitation = round($produitsExploitation - $chargesExploitation, 2);

        $produitsFinanciers = -$this->parPrefixe($soldes, '73');
        $chargesFinancieres = $this->parPrefixe($soldes, '63');
        $resultatFinancier = round($produitsFinanciers - $chargesFinancieres, 2);

        $resultatCourant = round($resultatExploitation + $resultatFinancier, 2);

        $produitsNonCourants = -$this->parPrefixe($soldes, '75');
        $chargesNonCourantes = $this->parPrefixe($soldes, '65');
        $resultatNonCourant = round($produitsNonCourants - $chargesNonCourantes, 2);

        $resultatAvantImpot = round($resultatCourant + $resultatNonCourant, 2);
        $impot = $this->parPrefixe($soldes, '67');
        $resultatNet = round($resultatAvantImpot - $impot, 2);

        return array_map(fn ($v) => is_float($v) ? number_format($v, 2, '.', '') : $v, [
            'produits_exploitation' => $produitsExploitation,
            'charges_exploitation' => $chargesExploitation,
            'resultat_exploitation' => $resultatExploitation,
            'produits_financiers' => $produitsFinanciers,
            'charges_financieres' => $chargesFinancieres,
            'resultat_financier' => $resultatFinancier,
            'resultat_courant' => $resultatCourant,
            'produits_non_courants' => $produitsNonCourants,
            'charges_non_courantes' => $chargesNonCourantes,
            'resultat_non_courant' => $resultatNonCourant,
            'resultat_avant_impot' => $resultatAvantImpot,
            'impot_resultat' => $impot,
            'resultat_net' => $resultatNet,
        ]);
    }

    private function bilan(Collection $soldes, float $resultatNet): array
    {
        // ACTIF : soldes débiteurs. Classe 2 nette (immobilisations − amortissements).
        $actifImmobilise = $this->parPrefixe($soldes, '2');
        $actifCirculant = $this->parPrefixe($soldes, '3');

        // Trésorerie : classe 5 ventilée selon le sens du solde de chaque compte.
        $tresorerieActif = 0.0;
        $tresoreriePassif = 0.0;
        foreach ($soldes->where('classe', 5) as $s) {
            if ($s['solde'] >= 0) {
                $tresorerieActif = round($tresorerieActif + $s['solde'], 2);
            } else {
                $tresoreriePassif = round($tresoreriePassif - $s['solde'], 2);
            }
        }

        $totalActif = round($actifImmobilise + $actifCirculant + $tresorerieActif, 2);

        // PASSIF : soldes créditeurs. Le résultat de l'exercice (non encore soldé
        // en 1161/1162) est ajouté au financement permanent.
        $financementPermanent = round(-$this->parPrefixe($soldes, '1') + $resultatNet, 2);
        $passifCirculant = round(-$this->parPrefixe($soldes, '4'), 2);

        $totalPassif = round($financementPermanent + $passifCirculant + $tresoreriePassif, 2);

        return array_map(fn ($v) => is_float($v) ? number_format($v, 2, '.', '') : $v, [
            'actif_immobilise' => $actifImmobilise,
            'actif_circulant' => $actifCirculant,
            'tresorerie_actif' => $tresorerieActif,
            'total_actif' => $totalActif,
            'financement_permanent' => $financementPermanent,
            'dont_resultat_net' => round($resultatNet, 2),
            'passif_circulant' => $passifCirculant,
            'tresorerie_passif' => $tresoreriePassif,
            'total_passif' => $totalPassif,
            'equilibre' => abs($totalActif - $totalPassif) < 0.01,
        ]);
    }
}
