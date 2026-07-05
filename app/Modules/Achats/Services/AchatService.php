<?php

namespace App\Modules\Achats\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Achats\Events\CommandeFournisseurValidee;
use App\Modules\Achats\Events\FactureAchatValidee;
use App\Modules\Achats\Events\PaiementFournisseurEnregistre;
use App\Modules\Achats\Events\ReceptionValidee;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Achats\Models\PaiementFournisseur;
use App\Modules\Catalogue\Models\Produit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AchatService
{
    private const PREFIXES = [
        DocumentAchat::TYPE_COMMANDE => 'CF',
        DocumentAchat::TYPE_RECEPTION => 'RE',
        DocumentAchat::TYPE_FACTURE => 'FF',
    ];

    private const EPSILON = 0.0005;

    public function __construct(private SequenceService $sequences) {}

    public function create(array $data): DocumentAchat
    {
        return DB::transaction(function () use ($data) {
            $document = DocumentAchat::create([
                'type' => $data['type'],
                'code' => $this->sequences->next(self::PREFIXES[$data['type']]),
                'statut' => DocumentAchat::STATUT_BROUILLON,
                'tiers_id' => $data['tiers_id'],
                'entrepot_id' => $data['entrepot_id'] ?? null,
                'ref_fournisseur' => $data['ref_fournisseur'] ?? null,
                'date_document' => $data['date_document'] ?? now()->toDateString(),
                'date_echeance' => $data['date_echeance'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLignes($document, $data['lignes']);

            return $document->load(['lignes', 'tiers', 'entrepot']);
        });
    }

    public function update(DocumentAchat $document, array $data): DocumentAchat
    {
        $this->assertBrouillon($document);

        return DB::transaction(function () use ($document, $data) {
            $document->update(collect($data)->only([
                'tiers_id', 'entrepot_id', 'ref_fournisseur',
                'date_document', 'date_echeance', 'notes',
            ])->all());

            if (array_key_exists('lignes', $data)) {
                $document->lignes()->delete();
                $this->syncLignes($document, $data['lignes']);
            }

            return $document->fresh(['lignes', 'tiers', 'entrepot']);
        });
    }

    public function delete(DocumentAchat $document): void
    {
        $this->assertBrouillon($document);
        $document->delete();
    }

    public function valider(DocumentAchat $document): DocumentAchat
    {
        $this->assertBrouillon($document);

        if ($document->lignes()->count() === 0) {
            throw ValidationException::withMessages([
                'lignes' => 'Impossible de valider un document sans ligne.',
            ]);
        }

        return DB::transaction(function () use ($document) {
            if ($document->type === DocumentAchat::TYPE_RECEPTION) {
                $this->appliquerReception($document);
            }

            $document->update([
                'statut' => DocumentAchat::STATUT_VALIDE,
                'validated_at' => now(),
            ]);

            // La facture fournisseur fige les prix d'achat : dernier prix connu.
            if ($document->type === DocumentAchat::TYPE_FACTURE) {
                $this->mettreAJourPrixAchat($document);
            }

            match ($document->type) {
                DocumentAchat::TYPE_COMMANDE => event(new CommandeFournisseurValidee($document)),
                DocumentAchat::TYPE_RECEPTION => event(new ReceptionValidee($document)),
                DocumentAchat::TYPE_FACTURE => event(new FactureAchatValidee($document)),
            };

            return $document->fresh(['lignes', 'tiers', 'entrepot', 'source']);
        });
    }

    /**
     * Transformations autorisées :
     *   commande (validée / reçue partiellement) → réception du RESTE à recevoir
     *   commande (validée / reçue*)              → facture (toutes les lignes)
     *   réception (validée)                      → facture
     */
    public function transformer(DocumentAchat $source, string $targetType): DocumentAchat
    {
        $statutsCommandeOuverts = [
            DocumentAchat::STATUT_VALIDE,
            DocumentAchat::STATUT_RECUE_PARTIELLE,
        ];

        $allowed = match ([$source->type, $targetType]) {
            [DocumentAchat::TYPE_COMMANDE, DocumentAchat::TYPE_RECEPTION] => in_array($source->statut, $statutsCommandeOuverts, true),
            [DocumentAchat::TYPE_COMMANDE, DocumentAchat::TYPE_FACTURE] => in_array($source->statut, [...$statutsCommandeOuverts, DocumentAchat::STATUT_RECUE], true),
            [DocumentAchat::TYPE_RECEPTION, DocumentAchat::TYPE_FACTURE] => $source->statut === DocumentAchat::STATUT_VALIDE,
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages([
                'type' => "Transformation impossible : {$source->type} ({$source->statut}) → {$targetType}.",
            ]);
        }

        return DB::transaction(function () use ($source, $targetType) {
            $document = DocumentAchat::create([
                'type' => $targetType,
                'code' => $this->sequences->next(self::PREFIXES[$targetType]),
                'statut' => DocumentAchat::STATUT_BROUILLON,
                'tiers_id' => $source->tiers_id,
                'source_document_id' => $source->id,
                'entrepot_id' => $source->entrepot_id,
                'date_document' => now()->toDateString(),
                'notes' => $source->notes,
            ]);

            $verselaReception = $targetType === DocumentAchat::TYPE_RECEPTION;
            $position = 1;
            $totalHt = 0.0;
            $totalTva = 0.0;

            foreach ($source->lignes as $ligne) {
                // Réception : on ne propose que le reste à recevoir.
                $quantite = $verselaReception ? $ligne->resteARecevoir() : (float) $ligne->quantite;

                if ($quantite <= self::EPSILON) {
                    continue;
                }

                $montantHt = $verselaReception
                    ? round($quantite * (float) $ligne->prix_unitaire * (1 - (float) $ligne->remise_percent / 100), 2)
                    : (float) $ligne->montant_ht;
                $montantTva = $verselaReception
                    ? round($montantHt * (float) $ligne->tva_rate / 100, 2)
                    : (float) $ligne->montant_tva;

                $document->lignes()->create([
                    'produit_id' => $ligne->produit_id,
                    'source_ligne_id' => $verselaReception ? $ligne->id : null,
                    'designation' => $ligne->designation,
                    'quantite' => $quantite,
                    'prix_unitaire' => $ligne->prix_unitaire,
                    'remise_percent' => $ligne->remise_percent,
                    'tva_rate' => $ligne->tva_rate,
                    'montant_ht' => $montantHt,
                    'montant_tva' => $montantTva,
                    'montant_ttc' => round($montantHt + $montantTva, 2),
                    'position' => $position++,
                ]);

                $totalHt = round($totalHt + $montantHt, 2);
                $totalTva = round($totalTva + $montantTva, 2);
            }

            if ($position === 1) {
                throw ValidationException::withMessages([
                    'type' => 'Tout est déjà réceptionné sur cette commande.',
                ]);
            }

            $document->update([
                'total_ht' => $totalHt,
                'total_tva' => $totalTva,
                'total_ttc' => round($totalHt + $totalTva, 2),
            ]);

            return $document->fresh(['lignes', 'tiers', 'entrepot', 'source']);
        });
    }

    public function ajouterPaiement(DocumentAchat $document, array $data): PaiementFournisseur
    {
        if ($document->type !== DocumentAchat::TYPE_FACTURE) {
            throw ValidationException::withMessages([
                'montant' => 'Seule une facture fournisseur peut être payée.',
            ]);
        }

        if ($document->statut === DocumentAchat::STATUT_BROUILLON) {
            throw ValidationException::withMessages([
                'montant' => 'Validez la facture avant d\'enregistrer un paiement.',
            ]);
        }

        if ($document->statut === DocumentAchat::STATUT_PAYE) {
            throw ValidationException::withMessages([
                'montant' => 'Cette facture est déjà entièrement payée.',
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
                $document->update(['statut' => DocumentAchat::STATUT_PAYE]);
            }

            event(new PaiementFournisseurEnregistre($paiement, $document));

            return $paiement;
        });
    }

    /**
     * À la validation d'une réception : contrôle de sur-réception, cumul des
     * quantités reçues sur les lignes de commande source, statut de la commande.
     */
    private function appliquerReception(DocumentAchat $reception): void
    {
        if ($reception->entrepot_id === null) {
            throw ValidationException::withMessages([
                'entrepot_id' => 'Choisissez l\'entrepôt de réception avant de valider.',
            ]);
        }

        $commande = $reception->source;

        foreach ($reception->lignes as $ligne) {
            if ($ligne->source_ligne_id === null) {
                continue; // réception directe, sans commande
            }

            $sourceLigne = DocumentAchatLigne::whereKey($ligne->source_ligne_id)->lockForUpdate()->first();

            if ($sourceLigne === null) {
                continue;
            }

            if ((float) $ligne->quantite > $sourceLigne->resteARecevoir() + self::EPSILON) {
                throw ValidationException::withMessages([
                    'lignes' => sprintf(
                        'Sur-réception refusée pour « %s » : %s commandé, %s déjà reçu, %s demandé.',
                        $sourceLigne->designation,
                        rtrim(rtrim((string) $sourceLigne->quantite, '0'), '.'),
                        rtrim(rtrim((string) $sourceLigne->quantite_recue, '0'), '.'),
                        rtrim(rtrim((string) $ligne->quantite, '0'), '.'),
                    ),
                ]);
            }

            $sourceLigne->update([
                'quantite_recue' => round((float) $sourceLigne->quantite_recue + (float) $ligne->quantite, 3),
            ]);
        }

        if ($commande !== null && $commande->type === DocumentAchat::TYPE_COMMANDE) {
            $this->recalculerStatutCommande($commande);
        }
    }

    private function recalculerStatutCommande(DocumentAchat $commande): void
    {
        $lignes = $commande->lignes()->get();
        $toutRecu = $lignes->every(fn ($l) => $l->resteARecevoir() <= self::EPSILON);
        $rienRecu = $lignes->every(fn ($l) => (float) $l->quantite_recue <= self::EPSILON);

        $commande->update([
            'statut' => $toutRecu
                ? DocumentAchat::STATUT_RECUE
                : ($rienRecu ? DocumentAchat::STATUT_VALIDE : DocumentAchat::STATUT_RECUE_PARTIELLE),
        ]);
    }

    private function mettreAJourPrixAchat(DocumentAchat $facture): void
    {
        foreach ($facture->lignes as $ligne) {
            if ($ligne->produit_id !== null) {
                Produit::whereKey($ligne->produit_id)
                    ->where('type', 'product')
                    ->update(['buy_price' => $ligne->prix_unitaire]);
            }
        }
    }

    private function syncLignes(DocumentAchat $document, array $lignes): void
    {
        $totalHt = 0.0;
        $totalTva = 0.0;
        $position = 1;

        foreach ($lignes as $data) {
            $sourceLigne = null;

            if (! empty($data['source_ligne_id'])) {
                if ($document->type !== DocumentAchat::TYPE_RECEPTION || $document->source_document_id === null) {
                    throw ValidationException::withMessages([
                        'lignes' => 'source_ligne_id n\'est valable que sur une réception issue d\'une commande.',
                    ]);
                }

                $sourceLigne = DocumentAchatLigne::whereKey($data['source_ligne_id'])
                    ->where('document_achat_id', $document->source_document_id)
                    ->first();

                if ($sourceLigne === null) {
                    throw ValidationException::withMessages([
                        'lignes' => 'Ligne de commande source introuvable.',
                    ]);
                }
            }

            $produit = ! empty($data['produit_id'])
                ? Produit::find($data['produit_id'])
                : ($sourceLigne?->produit_id ? Produit::find($sourceLigne->produit_id) : null);

            $designation = $data['designation'] ?? $sourceLigne?->designation ?? $produit?->name;
            $prixUnitaire = isset($data['prix_unitaire']) && $data['prix_unitaire'] !== null
                ? (float) $data['prix_unitaire']
                : (float) ($sourceLigne?->prix_unitaire ?? $produit?->buy_price ?? 0);
            $tvaRate = isset($data['tva_rate']) && $data['tva_rate'] !== null
                ? (float) $data['tva_rate']
                : (float) ($sourceLigne?->tva_rate ?? $produit?->tva_rate ?? 20);
            $quantite = (float) $data['quantite'];
            $remise = (float) ($data['remise_percent'] ?? ($sourceLigne?->remise_percent ?? 0));

            // Sur-réception bloquée dès la saisie (revérifiée à la validation).
            if ($sourceLigne !== null && $quantite > $sourceLigne->resteARecevoir() + self::EPSILON) {
                throw ValidationException::withMessages([
                    'lignes' => sprintf(
                        '« %s » : quantité demandée supérieure au reste à recevoir (%.3f).',
                        $designation,
                        $sourceLigne->resteARecevoir(),
                    ),
                ]);
            }

            $montantHt = round($quantite * $prixUnitaire * (1 - $remise / 100), 2);
            $montantTva = round($montantHt * $tvaRate / 100, 2);

            $document->lignes()->create([
                'produit_id' => $produit?->id,
                'source_ligne_id' => $sourceLigne?->id,
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

    private function assertBrouillon(DocumentAchat $document): void
    {
        if (! $document->isBrouillon()) {
            throw ValidationException::withMessages([
                'statut' => 'Seul un document en brouillon peut être modifié ou supprimé.',
            ]);
        }
    }
}
