<?php

namespace App\Modules\Compta\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Compta\Models\ComptaMapping;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\Exercice;
use App\Modules\Compta\PlanComptableMarocain;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ComptaService
{
    /** Mode de paiement → clé de mapping (compte de trésorerie). */
    private const MODE_VERS_MAPPING = [
        'especes' => 'caisse',
        'cheque' => 'cheques',
        'virement' => 'banque',
        'carte' => 'banque',
        'autre' => 'banque',
    ];

    public function __construct(private SequenceService $sequences) {}

    /**
     * Charge le plan comptable PCGM (sous-ensemble PME) et les comptes par
     * défaut pour le tenant courant. Idempotent, appelé à la volée : aucune
     * configuration n'est demandée à l'entreprise.
     */
    public function initialiserPlanComptable(): void
    {
        // Rattrapage inclus : un tenant existant dont le plan est déjà seedé
        // reçoit les comptes système ET les mappings ajoutés par les versions
        // suivantes (achats, immobilisations…).
        $comptesComplets = Compte::where('is_system', true)->count() >= count(PlanComptableMarocain::COMPTES);
        $mappingsComplets = ComptaMapping::count() >= count(PlanComptableMarocain::MAPPINGS_DEFAUT);

        if ($comptesComplets && $mappingsComplets) {
            return;
        }

        DB::transaction(function () {
            foreach (PlanComptableMarocain::COMPTES as [$code, $label]) {
                if (! Compte::where('code', $code)->exists()) {
                    Compte::create([
                        'code' => $code,
                        'label' => $label,
                        'classe' => (int) $code[0],
                        'is_system' => true,
                    ]);
                }
            }

            foreach (PlanComptableMarocain::MAPPINGS_DEFAUT as $cle => $code) {
                if (! ComptaMapping::where('cle', $cle)->exists()) {
                    ComptaMapping::create([
                        'cle' => $cle,
                        'compte_id' => Compte::where('code', $code)->firstOrFail()->id,
                    ]);
                }
            }
        });
    }

    public function compteParCode(string $code): Compte
    {
        $this->initialiserPlanComptable();

        return Compte::where('code', $code)->firstOrFail();
    }

    public function compteParDefaut(string $cle): Compte
    {
        $this->initialiserPlanComptable();

        $mapping = ComptaMapping::with('compte')->where('cle', $cle)->first();

        if ($mapping === null) {
            throw new \RuntimeException("Compte par défaut introuvable pour la clé [{$cle}].");
        }

        return $mapping->compte;
    }

    /**
     * Écriture de vente (journal VT) générée à la validation d'une facture :
     *   Débit  3411 Clients (TTC)
     *   Crédit 7111 Ventes de marchandises (HT lignes produits)
     *   Crédit 7114 Ventes de services (HT lignes services et lignes libres)
     *   Crédit 4441 TVA facturée (total TVA)
     */
    public function ecrireVente(DocumentVente $document): Ecriture
    {
        $document->loadMissing(['lignes.produit.categorieProduit', 'tiers']);

        // Compte de vente résolu ligne par ligne : compte de la catégorie du
        // produit, sinon repli sur le mapping global (marchandises/services).
        $htParCompte = [];
        foreach ($document->lignes as $ligne) {
            $compte = $this->compteVenteLigne($ligne);
            $htParCompte[$compte->id] = round(($htParCompte[$compte->id] ?? 0) + (float) $ligne->montant_ht, 2);
        }

        $lignes = [
            ['compte' => $this->compteParDefaut('clients'), 'debit' => (float) $document->total_ttc, 'credit' => 0, 'tiers_id' => $document->tiers_id],
        ];

        foreach ($htParCompte as $compteId => $ht) {
            if ($ht > 0) {
                $lignes[] = ['compte' => Compte::find($compteId), 'debit' => 0, 'credit' => $ht];
            }
        }
        if ((float) $document->total_tva > 0) {
            $lignes[] = ['compte' => $this->compteParDefaut('tva_facturee'), 'debit' => 0, 'credit' => (float) $document->total_tva];
        }

        return $this->creerEcriture(
            journal: Ecriture::JOURNAL_VENTES,
            date: $document->date_document->format('Y-m-d'),
            libelle: "Facture {$document->code} — {$document->tiers->name}",
            lignes: $lignes,
            reference: $document->code,
            documentVenteId: $document->id,
            isAuto: true,
        );
    }

    /**
     * Écriture d'avoir (journal VT) : contrepassation exacte de la facture.
     *   Débit  7111/7124 Ventes (HT, ligne par ligne comme la facture)
     *   Débit  4441 TVA facturée
     *   Crédit 3411 Clients (TTC)
     */
    public function ecrireAvoir(DocumentVente $document): Ecriture
    {
        $document->loadMissing(['lignes.produit.categorieProduit', 'tiers']);

        $htParCompte = [];
        foreach ($document->lignes as $ligne) {
            $compte = $this->compteVenteLigne($ligne);
            $htParCompte[$compte->id] = round(($htParCompte[$compte->id] ?? 0) + (float) $ligne->montant_ht, 2);
        }

        $lignes = [];
        foreach ($htParCompte as $compteId => $ht) {
            if ($ht > 0) {
                $lignes[] = ['compte' => Compte::find($compteId), 'debit' => $ht, 'credit' => 0];
            }
        }
        if ((float) $document->total_tva > 0) {
            $lignes[] = ['compte' => $this->compteParDefaut('tva_facturee'), 'debit' => (float) $document->total_tva, 'credit' => 0];
        }
        $lignes[] = ['compte' => $this->compteParDefaut('clients'), 'debit' => 0, 'credit' => (float) $document->total_ttc, 'tiers_id' => $document->tiers_id];

        return $this->creerEcriture(
            journal: Ecriture::JOURNAL_VENTES,
            date: $document->date_document->format('Y-m-d'),
            libelle: "Avoir {$document->code} — {$document->tiers->name}",
            lignes: $lignes,
            reference: $document->code,
            documentVenteId: $document->id,
            isAuto: true,
        );
    }

    /**
     * Écriture d'encaissement (journal BQ) :
     *   Débit  5141/5161/5111 selon le mode de paiement
     *   Crédit 3411 Clients
     * Pour un avoir, le paiement est un remboursement : écriture inversée
     * (débit clients, crédit trésorerie).
     */
    public function ecrireEncaissement(Paiement $paiement, DocumentVente $document): Ecriture
    {
        $cle = self::MODE_VERS_MAPPING[$paiement->mode] ?? 'banque';
        $montant = (float) $paiement->montant;
        $estAvoir = $document->type === DocumentVente::TYPE_AVOIR;

        $tresorerie = ['compte' => $this->compteParDefaut($cle), 'debit' => $estAvoir ? 0 : $montant, 'credit' => $estAvoir ? $montant : 0];
        $clients = ['compte' => $this->compteParDefaut('clients'), 'debit' => $estAvoir ? $montant : 0, 'credit' => $estAvoir ? 0 : $montant, 'tiers_id' => $document->tiers_id];

        return $this->creerEcriture(
            journal: Ecriture::JOURNAL_TRESORERIE,
            date: $paiement->date_paiement->format('Y-m-d'),
            libelle: ($estAvoir ? 'Remboursement ' : 'Encaissement ')."{$document->code} — ".($paiement->reference ?: $paiement->mode),
            lignes: [$tresorerie, $clients],
            reference: $document->code,
            documentVenteId: $document->id,
            isAuto: true,
        );
    }

    /**
     * Écriture d'achat (journal AC) à la validation d'une facture fournisseur :
     *   Débit  6111 Achats de marchandises (HT lignes produits)
     *   Débit  6117 Achats de services (HT lignes services et libres)
     *   Débit  3442 TVA récupérable sur charges
     *   Crédit 4411 Fournisseurs (TTC)
     */
    public function ecrireAchat(\App\Modules\Achats\Models\DocumentAchat $document): Ecriture
    {
        $document->loadMissing(['lignes.produit.categorieProduit', 'tiers']);

        // Compte de charge/immobilisation résolu par ligne (catégorie → mapping).
        // La TVA d'une ligne d'immobilisation va en 3441, celle des charges en 3442.
        $htParCompte = [];
        $tvaCharges = 0.0;
        $tvaImmos = 0.0;

        foreach ($document->lignes as $ligne) {
            [$compte, $estImmo] = $this->compteAchatLigne($ligne);
            $htParCompte[$compte->id] = round(($htParCompte[$compte->id] ?? 0) + (float) $ligne->montant_ht, 2);

            if ($estImmo) {
                $tvaImmos = round($tvaImmos + (float) $ligne->montant_tva, 2);
            } else {
                $tvaCharges = round($tvaCharges + (float) $ligne->montant_tva, 2);
            }
        }

        $lignes = [];
        foreach ($htParCompte as $compteId => $ht) {
            if ($ht > 0) {
                $lignes[] = ['compte' => Compte::find($compteId), 'debit' => $ht, 'credit' => 0];
            }
        }
        if ($tvaCharges > 0) {
            $lignes[] = ['compte' => $this->compteParDefaut('tva_recuperable'), 'debit' => $tvaCharges, 'credit' => 0];
        }
        if ($tvaImmos > 0) {
            $lignes[] = ['compte' => $this->compteParCode('3441'), 'debit' => $tvaImmos, 'credit' => 0];
        }

        $lignes[] = ['compte' => $this->compteParDefaut('fournisseurs'), 'debit' => 0, 'credit' => (float) $document->total_ttc, 'tiers_id' => $document->tiers_id];

        $reference = $document->ref_fournisseur
            ? "{$document->code} / {$document->ref_fournisseur}"
            : $document->code;

        return $this->creerEcriture(
            journal: Ecriture::JOURNAL_ACHATS,
            date: $document->date_document->format('Y-m-d'),
            libelle: "Facture fournisseur {$reference} — {$document->tiers->name}",
            lignes: $lignes,
            reference: $document->code,
            isAuto: true,
        );
    }

    /**
     * Écriture de décaissement (journal BQ) :
     *   Débit  4411 Fournisseurs
     *   Crédit 5141/5161/5111 selon le mode de paiement
     */
    public function ecrireDecaissement(
        \App\Modules\Achats\Models\PaiementFournisseur $paiement,
        \App\Modules\Achats\Models\DocumentAchat $document,
    ): Ecriture {
        $cle = self::MODE_VERS_MAPPING[$paiement->mode] ?? 'banque';

        return $this->creerEcriture(
            journal: Ecriture::JOURNAL_TRESORERIE,
            date: $paiement->date_paiement->format('Y-m-d'),
            libelle: "Règlement fournisseur {$document->code} — ".($paiement->reference ?: $paiement->mode),
            lignes: [
                ['compte' => $this->compteParDefaut('fournisseurs'), 'debit' => (float) $paiement->montant, 'credit' => 0, 'tiers_id' => $document->tiers_id],
                ['compte' => $this->compteParDefaut($cle), 'debit' => 0, 'credit' => (float) $paiement->montant],
            ],
            reference: $document->code,
            isAuto: true,
        );
    }

    /** Écriture manuelle (journal OD) — pour le comptable. */
    public function ecritureManuelle(array $data): Ecriture
    {
        $this->initialiserPlanComptable();

        $lignes = collect($data['lignes'])->map(fn (array $ligne) => [
            'compte' => Compte::findOrFail($ligne['compte_id']),
            'tiers_id' => $ligne['tiers_id'] ?? null,
            'libelle' => $ligne['libelle'] ?? null,
            'debit' => (float) ($ligne['debit'] ?? 0),
            'credit' => (float) ($ligne['credit'] ?? 0),
        ])->all();

        return $this->creerEcriture(
            journal: Ecriture::JOURNAL_DIVERS,
            date: $data['date_ecriture'] ?? now()->toDateString(),
            libelle: $data['libelle'],
            lignes: $lignes,
            reference: $data['reference'] ?? null,
        );
    }

    /** Compte de vente d'une ligne : catégorie du produit, sinon mapping global. */
    private function compteVenteLigne($ligne): Compte
    {
        $categorie = $ligne->produit?->categorieProduit;

        if ($categorie && $categorie->compte_vente_id) {
            return Compte::find($categorie->compte_vente_id);
        }

        $estProduit = $ligne->produit && $ligne->produit->type === 'product';

        return $this->compteParDefaut($estProduit ? 'ventes_marchandises' : 'ventes_services');
    }

    /** Compte d'achat d'une ligne + indicateur immobilisation : [Compte, bool]. */
    private function compteAchatLigne($ligne): array
    {
        $categorie = $ligne->produit?->categorieProduit;

        if ($categorie && $categorie->compte_achat_id) {
            return [Compte::find($categorie->compte_achat_id), (bool) $categorie->is_immobilisation];
        }

        $estProduit = $ligne->produit && $ligne->produit->type === 'product';

        return [$this->compteParDefaut($estProduit ? 'achats_marchandises' : 'achats_services'), false];
    }

    /**
     * Point d'entrée réservé aux services du module (clôture d'exercice…).
     * Le format des lignes est celui de creerEcriture : ['compte' => Compte, 'debit', 'credit', …].
     */
    public function ecrire(string $journal, string $date, string $libelle, array $lignes, ?string $reference = null): Ecriture
    {
        return $this->creerEcriture($journal, $date, $libelle, $lignes, $reference, null, true);
    }

    /**
     * Création d'une écriture — partie double garantie ici et nulle part
     * ailleurs : toute écriture déséquilibrée est rejetée, et aucun exercice
     * clôturé ne peut recevoir d'écriture.
     */
    private function creerEcriture(
        string $journal,
        string $date,
        string $libelle,
        array $lignes,
        ?string $reference = null,
        ?int $documentVenteId = null,
        bool $isAuto = false,
    ): Ecriture {
        // Verrou de clôture : la dernière année clôturée (clôture chronologique)
        // définit l'horizon en dessous duquel plus rien ne peut être écrit.
        $derniereAnneeCloturee = Exercice::max('annee');

        if ($derniereAnneeCloturee !== null && (int) substr($date, 0, 4) <= (int) $derniereAnneeCloturee) {
            throw ValidationException::withMessages([
                'date_ecriture' => sprintf(
                    'L\'exercice %s est clôturé : aucune écriture ne peut y être ajoutée (datez au plus tôt du 01/01/%d).',
                    substr($date, 0, 4),
                    (int) $derniereAnneeCloturee + 1,
                ),
            ]);
        }

        $totalDebit = round(array_sum(array_map(fn ($l) => $l['debit'], $lignes)), 2);
        $totalCredit = round(array_sum(array_map(fn ($l) => $l['credit'], $lignes)), 2);

        if (abs($totalDebit - $totalCredit) > 0.005 || $totalDebit <= 0) {
            throw ValidationException::withMessages([
                'lignes' => sprintf(
                    'Écriture déséquilibrée : débit %.2f ≠ crédit %.2f.',
                    $totalDebit,
                    $totalCredit,
                ),
            ]);
        }

        foreach ($lignes as $ligne) {
            if ($ligne['debit'] > 0 && $ligne['credit'] > 0) {
                throw ValidationException::withMessages([
                    'lignes' => 'Une ligne ne peut pas être à la fois au débit et au crédit.',
                ]);
            }
        }

        return DB::transaction(function () use ($journal, $date, $libelle, $lignes, $reference, $documentVenteId, $isAuto) {
            $this->initialiserPlanComptable();

            $ecriture = Ecriture::create([
                'journal' => $journal,
                'numero' => $this->sequences->next($journal),
                'date_ecriture' => $date,
                'libelle' => $libelle,
                'reference' => $reference,
                'document_vente_id' => $documentVenteId,
                'is_auto' => $isAuto,
            ]);

            foreach ($lignes as $ligne) {
                $ecriture->lignes()->create([
                    'compte_id' => $ligne['compte']->id,
                    'tiers_id' => $ligne['tiers_id'] ?? null,
                    'libelle' => $ligne['libelle'] ?? null,
                    'debit' => $ligne['debit'],
                    'credit' => $ligne['credit'],
                ]);
            }

            return $ecriture->load('lignes.compte');
        });
    }
}
