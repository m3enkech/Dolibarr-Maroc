<?php

namespace App\Modules\Ventes\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Ventes\Events\AvoirValide;
use App\Modules\Ventes\Events\BonLivraisonValide;
use App\Modules\Ventes\Events\CommandeValidee;
use App\Modules\Ventes\Events\DevisValide;
use App\Modules\Ventes\Events\FactureValidee;
use App\Modules\Ventes\Events\PaiementEnregistre;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VenteService
{
    private const PREFIXES = [
        DocumentVente::TYPE_DEVIS => 'DE',
        DocumentVente::TYPE_COMMANDE => 'CO',
        DocumentVente::TYPE_BON_LIVRAISON => 'BL',
        DocumentVente::TYPE_FACTURE => 'FA',
        DocumentVente::TYPE_AVOIR => 'AV',
    ];

    public function __construct(private SequenceService $sequences) {}

    public function create(array $data): DocumentVente
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'];

            // Factures et avoirs ne reçoivent leur numéro définitif qu'à la
            // validation : en brouillon ils portent un numéro provisoire.
            $code = in_array($type, [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR], true)
                ? $this->sequences->next('PROV')
                : $this->sequences->next(self::PREFIXES[$type]);

            $document = DocumentVente::create([
                'type' => $type,
                'code' => $code,
                'statut' => DocumentVente::STATUT_BROUILLON,
                'tiers_id' => $data['tiers_id'],
                'date_document' => $data['date_document'] ?? now()->toDateString(),
                'date_echeance' => $data['date_echeance'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLignes($document, $data['lignes']);

            // load() plutôt que fresh() : on garde l'instance créée
            // (wasRecentlyCreated) pour que l'API réponde 201.
            return $document->load(['lignes', 'tiers']);
        });
    }

    public function update(DocumentVente $document, array $data): DocumentVente
    {
        $this->assertBrouillon($document);

        return DB::transaction(function () use ($document, $data) {
            $document->update(collect($data)->only([
                'tiers_id', 'date_document', 'date_echeance', 'notes',
            ])->all());

            if (array_key_exists('lignes', $data)) {
                $document->lignes()->delete();
                $this->syncLignes($document, $data['lignes']);
            }

            return $document->fresh(['lignes', 'tiers']);
        });
    }

    public function delete(DocumentVente $document): void
    {
        $this->assertBrouillon($document);
        $document->delete();
    }

    public function valider(DocumentVente $document): DocumentVente
    {
        $this->assertBrouillon($document);

        if ($document->lignes()->count() === 0) {
            throw ValidationException::withMessages([
                'lignes' => 'Impossible de valider un document sans ligne.',
            ]);
        }

        return DB::transaction(function () use ($document) {
            $updates = [
                'statut' => DocumentVente::STATUT_VALIDE,
                'validated_at' => now(),
            ];

            if (in_array($document->type, [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR], true)) {
                $updates['code'] = $this->sequences->next(self::PREFIXES[$document->type]);
            }

            $document->update($updates);

            match ($document->type) {
                DocumentVente::TYPE_DEVIS => event(new DevisValide($document)),
                DocumentVente::TYPE_COMMANDE => event(new CommandeValidee($document)),
                DocumentVente::TYPE_BON_LIVRAISON => event(new BonLivraisonValide($document)),
                DocumentVente::TYPE_FACTURE => event(new FactureValidee($document)),
                DocumentVente::TYPE_AVOIR => event(new AvoirValide($document)),
            };

            return $document->fresh(['lignes', 'tiers']);
        });
    }

    /** Accepter ou refuser un devis validé. */
    public function changerStatutDevis(DocumentVente $document, string $statut): DocumentVente
    {
        if ($document->type !== DocumentVente::TYPE_DEVIS) {
            throw ValidationException::withMessages([
                'statut' => 'Seul un devis peut être accepté ou refusé.',
            ]);
        }

        if ($document->statut !== DocumentVente::STATUT_VALIDE) {
            throw ValidationException::withMessages([
                'statut' => 'Le devis doit être validé avant d\'être accepté ou refusé.',
            ]);
        }

        $document->update(['statut' => $statut]);

        return $document->fresh(['lignes', 'tiers']);
    }

    /**
     * Transforme un document en aval de la chaîne : devis → commande / BL /
     * facture, commande → BL / facture, bon de livraison → facture, facture →
     * avoir. Le nouveau document est un brouillon lié à sa source.
     */
    public function transformer(DocumentVente $source, string $targetType): DocumentVente
    {
        $allowed = match ($source->type) {
            DocumentVente::TYPE_DEVIS => in_array($source->statut, [DocumentVente::STATUT_VALIDE, DocumentVente::STATUT_ACCEPTE], true)
                && in_array($targetType, [DocumentVente::TYPE_COMMANDE, DocumentVente::TYPE_BON_LIVRAISON, DocumentVente::TYPE_FACTURE], true),
            DocumentVente::TYPE_COMMANDE => $source->statut === DocumentVente::STATUT_VALIDE
                && in_array($targetType, [DocumentVente::TYPE_BON_LIVRAISON, DocumentVente::TYPE_FACTURE], true),
            // BL livré → facture (le stock est déjà sorti à la livraison).
            DocumentVente::TYPE_BON_LIVRAISON => $source->statut === DocumentVente::STATUT_VALIDE
                && $targetType === DocumentVente::TYPE_FACTURE,
            // Avoir : uniquement depuis une facture émise (validée ou payée).
            DocumentVente::TYPE_FACTURE => in_array($source->statut, [DocumentVente::STATUT_VALIDE, DocumentVente::STATUT_PAYE], true)
                && $targetType === DocumentVente::TYPE_AVOIR,
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages([
                'type' => "Transformation impossible : {$source->type} ({$source->statut}) → {$targetType}.",
            ]);
        }

        return DB::transaction(function () use ($source, $targetType) {
            $code = in_array($targetType, [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR], true)
                ? $this->sequences->next('PROV')
                : $this->sequences->next(self::PREFIXES[$targetType]);

            $document = DocumentVente::create([
                'type' => $targetType,
                'code' => $code,
                'statut' => DocumentVente::STATUT_BROUILLON,
                'tiers_id' => $source->tiers_id,
                'source_document_id' => $source->id,
                // L'avoir/BL/facture issu d'une source hérite de son entrepôt :
                // le retour de stock d'un avoir vise le même entrepôt que la vente.
                'entrepot_id' => $source->entrepot_id,
                'date_document' => now()->toDateString(),
                'notes' => $source->notes,
                'total_ht' => $source->total_ht,
                'total_tva' => $source->total_tva,
                'total_ttc' => $source->total_ttc,
            ]);

            foreach ($source->lignes as $ligne) {
                $document->lignes()->create($ligne->only([
                    'produit_id', 'designation', 'quantite', 'prix_unitaire',
                    'remise_percent', 'tva_rate', 'montant_ht', 'montant_tva',
                    'montant_ttc', 'position',
                ]));
            }

            // Transformer un devis validé vaut acceptation.
            if ($source->type === DocumentVente::TYPE_DEVIS && $source->statut === DocumentVente::STATUT_VALIDE) {
                $source->update(['statut' => DocumentVente::STATUT_ACCEPTE]);
            }

            return $document->fresh(['lignes', 'tiers', 'source']);
        });
    }

    /** Encaissement d'une facture, ou remboursement d'un avoir (même mécanique). */
    public function ajouterPaiement(DocumentVente $document, array $data): Paiement
    {
        if (! in_array($document->type, [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR], true)) {
            throw ValidationException::withMessages([
                'montant' => 'Seule une facture ou un avoir peut recevoir un paiement.',
            ]);
        }

        if ($document->statut === DocumentVente::STATUT_BROUILLON) {
            throw ValidationException::withMessages([
                'montant' => 'Validez le document avant d\'enregistrer un paiement.',
            ]);
        }

        if ($document->statut === DocumentVente::STATUT_PAYE) {
            throw ValidationException::withMessages([
                'montant' => $document->type === DocumentVente::TYPE_AVOIR
                    ? 'Cet avoir est déjà entièrement remboursé.'
                    : 'Cette facture est déjà entièrement payée.',
            ]);
        }

        return DB::transaction(function () use ($document, $data) {
            $reste = $document->resteAPayer();

            if ((float) $data['montant'] > $reste + 0.001) {
                throw ValidationException::withMessages([
                    'montant' => sprintf('Le montant dépasse le reste à payer (%.2f MAD).', $reste),
                ]);
            }

            $paiement = $document->paiements()->create([
                'date_paiement' => $data['date_paiement'] ?? now()->toDateString(),
                'montant' => $data['montant'],
                'mode' => $data['mode'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            if ($document->resteAPayer() <= 0.009) {
                $document->update(['statut' => DocumentVente::STATUT_PAYE]);
            }

            event(new PaiementEnregistre($paiement, $document));

            return $paiement;
        });
    }

    private function syncLignes(DocumentVente $document, array $lignes): void
    {
        $totalHt = 0.0;
        $totalTva = 0.0;
        $position = 1;

        foreach ($lignes as $data) {
            $produit = ! empty($data['produit_id']) ? Produit::find($data['produit_id']) : null;

            $designation = $data['designation'] ?? $produit?->name;
            $prixUnitaire = isset($data['prix_unitaire']) && $data['prix_unitaire'] !== null
                ? (float) $data['prix_unitaire']
                : (float) ($produit?->sell_price ?? 0);
            $tvaRate = isset($data['tva_rate']) && $data['tva_rate'] !== null
                ? (float) $data['tva_rate']
                : (float) ($produit?->tva_rate ?? 20);
            $quantite = (float) $data['quantite'];
            $remise = (float) ($data['remise_percent'] ?? 0);

            $montantHt = round($quantite * $prixUnitaire * (1 - $remise / 100), 2);
            $montantTva = round($montantHt * $tvaRate / 100, 2);

            $document->lignes()->create([
                'produit_id' => $produit?->id,
                'designation' => $designation,
                'quantite' => $quantite,
                'prix_unitaire' => $prixUnitaire,
                'remise_percent' => $remise,
                'tva_rate' => $tvaRate,
                'montant_ht' => $montantHt,
                'montant_tva' => $montantTva,
                'montant_ttc' => round($montantHt + $montantTva, 2),
                'position' => $position++,
            ]);

            $totalHt = round($totalHt + $montantHt, 2);
            $totalTva = round($totalTva + $montantTva, 2);
        }

        $document->update([
            'total_ht' => $totalHt,
            'total_tva' => $totalTva,
            'total_ttc' => round($totalHt + $totalTva, 2),
        ]);
    }

    private function assertBrouillon(DocumentVente $document): void
    {
        if (! $document->isBrouillon()) {
            throw ValidationException::withMessages([
                'statut' => 'Seul un document en brouillon peut être modifié ou supprimé.',
            ]);
        }
    }
}
