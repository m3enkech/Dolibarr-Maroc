<?php

namespace App\Modules\Compta\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Compta\CategoriesImmobilisation;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\Immobilisation;
use App\Modules\Compta\Models\ImmobilisationDotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immobilisations : registre des biens durables et amortissement linéaire
 * aux durées fiscales marocaines. Le module ne comptabilise PAS l'acquisition
 * (déjà faite via la facture fournisseur ou une OD au débit du 23xx) ; il gère
 * l'amortissement (dotations 6161 / 28xx) et la cession.
 */
class ImmobilisationService
{
    private const EPSILON = 0.005;

    public function __construct(
        private SequenceService $sequences,
        private ComptaService $compta,
    ) {}

    public function create(array $data): Immobilisation
    {
        $this->compta->initialiserPlanComptable();
        $defaut = CategoriesImmobilisation::get($data['category']);

        $compteImmo = isset($data['compte_immo_id'])
            ? Compte::findOrFail($data['compte_immo_id'])
            : $this->compta->compteParCode($defaut['compteImmo']);
        $compteAmort = isset($data['compte_amort_id'])
            ? Compte::findOrFail($data['compte_amort_id'])
            : $this->compta->compteParCode($defaut['compteAmort']);

        return Immobilisation::create([
            'code' => $this->sequences->next('IM'),
            'label' => $data['label'],
            'category' => $data['category'],
            'date_acquisition' => $data['date_acquisition'],
            'valeur_acquisition' => $data['valeur_acquisition'],
            'duree_annees' => $data['duree_annees'] ?? $defaut['duree'],
            'compte_immo_id' => $compteImmo->id,
            'compte_amort_id' => $compteAmort->id,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Plan d'amortissement linéaire avec prorata temporis (mensuel) la première
     * année. La dernière ligne absorbe l'arrondi pour que le cumul = valeur brute.
     */
    public function plan(Immobilisation $immo): array
    {
        $base = (float) $immo->valeur_acquisition;
        $duree = (int) $immo->duree_annees;

        if ($duree <= 0 || $base <= 0) {
            return [];
        }

        $totalMois = $duree * 12;
        $mensualite = $base / $totalMois;

        $annee = (int) $immo->date_acquisition->format('Y');
        $moisAcquisition = (int) $immo->date_acquisition->format('n');
        $moisCetteAnnee = 13 - $moisAcquisition; // mois en service l'année d'acquisition

        $rows = [];
        $moisRestants = $totalMois;
        $cumul = 0.0;

        while ($moisRestants > 0) {
            $mois = min($moisCetteAnnee, $moisRestants);
            $moisRestants -= $mois;

            $dotation = $moisRestants <= 0
                ? round($base - $cumul, 2)          // dernière année : solde exact
                : round($mensualite * $mois, 2);

            $cumul = round($cumul + $dotation, 2);

            $rows[] = [
                'annee' => $annee,
                'mois' => $mois,
                'dotation' => number_format($dotation, 2, '.', ''),
                'cumul' => number_format($cumul, 2, '.', ''),
                'vna' => number_format(round($base - $cumul, 2), 2, '.', ''),
            ];

            $annee++;
            $moisCetteAnnee = 12;
        }

        return $rows;
    }

    /**
     * Génère la dotation d'une année pour toutes les immobilisations en service :
     * une écriture OD au 31/12 (débit 6161, crédit ventilé par compte
     * d'amortissement). Idempotent par année et par immobilisation.
     */
    public function genererDotations(int $annee): array
    {
        $this->compta->initialiserPlanComptable();

        $immos = Immobilisation::where('statut', Immobilisation::STATUT_EN_SERVICE)
            ->with('compteAmort')
            ->get();

        $aComptabiliser = [];

        foreach ($immos as $immo) {
            if ($immo->dotations()->where('annee', $annee)->exists()) {
                continue; // déjà dotée cette année
            }

            $ligneplan = collect($this->plan($immo))->firstWhere('annee', $annee);

            if ($ligneplan === null) {
                continue; // hors plan (pas encore acquise, ou entièrement amortie)
            }

            $montant = (float) $ligneplan['dotation'];

            if ($montant > self::EPSILON) {
                $aComptabiliser[] = ['immo' => $immo, 'montant' => $montant];
            }
        }

        if ($aComptabiliser === []) {
            return ['immobilisations' => 0, 'total' => '0.00'];
        }

        return DB::transaction(function () use ($annee, $aComptabiliser) {
            $total = round(array_sum(array_column($aComptabiliser, 'montant')), 2);

            // Débit 6161 (total), crédits regroupés par compte d'amortissement.
            $lignes = [['compte' => $this->compta->compteParCode('6161'), 'debit' => $total, 'credit' => 0]];

            $parAmort = [];
            foreach ($aComptabiliser as $item) {
                $id = $item['immo']->compte_amort_id;
                $parAmort[$id] = round(($parAmort[$id] ?? 0) + $item['montant'], 2);
            }
            foreach ($parAmort as $compteId => $montant) {
                $lignes[] = ['compte' => Compte::find($compteId), 'debit' => 0, 'credit' => $montant];
            }

            $ecriture = $this->compta->ecrire(
                Ecriture::JOURNAL_DIVERS,
                "{$annee}-12-31",
                "Dotations aux amortissements — exercice {$annee}",
                $lignes,
                "DOT-{$annee}",
            );

            foreach ($aComptabiliser as $item) {
                ImmobilisationDotation::create([
                    'immobilisation_id' => $item['immo']->id,
                    'annee' => $annee,
                    'montant' => $item['montant'],
                    'ecriture_id' => $ecriture->id,
                ]);
            }

            return [
                'immobilisations' => count($aComptabiliser),
                'total' => number_format($total, 2, '.', ''),
                'ecriture' => $ecriture->numero,
            ];
        });
    }

    /**
     * Cession : sortie de l'actif (reprise des amortissements + VNA en charge,
     * annulation de la valeur brute) et, si prix > 0, produit de cession.
     * La VNA repose sur les dotations réellement comptabilisées.
     */
    public function ceder(Immobilisation $immo, array $data): Immobilisation
    {
        if ($immo->statut !== Immobilisation::STATUT_EN_SERVICE) {
            throw ValidationException::withMessages([
                'statut' => 'Cette immobilisation est déjà cédée.',
            ]);
        }

        $immo->load(['compteImmo', 'compteAmort']);
        $base = (float) $immo->valeur_acquisition;
        $cumul = $immo->cumulAmortissement();
        $vna = round($base - $cumul, 2);
        $date = $data['date_cession'];
        $prix = (float) ($data['valeur_cession'] ?? 0);

        return DB::transaction(function () use ($immo, $base, $cumul, $vna, $date, $prix) {
            // 1. Sortie de l'actif : 28xx (débit cumul) + 6511 (débit VNA) / 23xx (crédit brut).
            $lignesSortie = [];
            if ($cumul > self::EPSILON) {
                $lignesSortie[] = ['compte' => $immo->compteAmort, 'debit' => $cumul, 'credit' => 0];
            }
            if ($vna > self::EPSILON) {
                $lignesSortie[] = ['compte' => $this->compta->compteParCode('6511'), 'debit' => $vna, 'credit' => 0];
            }
            $lignesSortie[] = ['compte' => $immo->compteImmo, 'debit' => 0, 'credit' => $base];

            $this->compta->ecrire(
                Ecriture::JOURNAL_DIVERS,
                $date,
                "Sortie immobilisation {$immo->code} — {$immo->label}",
                $lignesSortie,
                "CESSION-{$immo->code}",
            );

            // 2. Produit de cession (encaissé en banque) : 5141 / 7511.
            if ($prix > self::EPSILON) {
                $this->compta->ecrire(
                    Ecriture::JOURNAL_TRESORERIE,
                    $date,
                    "Cession immobilisation {$immo->code} — {$immo->label}",
                    [
                        ['compte' => $this->compta->compteParCode('5141'), 'debit' => $prix, 'credit' => 0],
                        ['compte' => $this->compta->compteParCode('7511'), 'debit' => 0, 'credit' => $prix],
                    ],
                    "CESSION-{$immo->code}",
                );
            }

            $immo->update([
                'statut' => Immobilisation::STATUT_CEDE,
                'date_cession' => $date,
                'valeur_cession' => $prix,
            ]);

            return $immo->fresh(['compteImmo', 'compteAmort', 'dotations']);
        });
    }
}
