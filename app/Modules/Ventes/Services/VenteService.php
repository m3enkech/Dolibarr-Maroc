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
use App\Modules\Ventes\Models\DocumentVenteLigne;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Support\Collection;
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

    public function __construct(
        private SequenceService $sequences,
        private \App\Modules\Catalogue\Services\TarifService $tarifs,
    ) {}

    public function create(array $data): DocumentVente
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'];

            // Un document REPRIS d'un autre logiciel garde le numéro qu'il y
            // portait : c'est celui qui figure sur le papier détenu par le
            // client, et le renuméroter rendrait l'archive introuvable. Ce
            // chemin n'est ouvert qu'aux imports — aucune requête HTTP ne
            // valide de champ `code`.
            //
            // Sinon : factures et avoirs ne reçoivent leur numéro définitif
            // qu'à la validation, et portent un numéro provisoire en brouillon.
            $code = $data['code']
                ?? (in_array($type, [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR], true)
                    ? $this->sequences->next('PROV')
                    : $this->sequences->next(self::PREFIXES[$type]));

            $document = DocumentVente::create([
                'type' => $type,
                'code' => $code,
                'statut' => DocumentVente::STATUT_BROUILLON,
                'tiers_id' => $data['tiers_id'],
                'date_document' => $data['date_document'] ?? now()->toDateString(),
                'date_echeance' => $data['date_echeance'] ?? null,
                // Le bon de commande du CLIENT, celui que son service
                // comptable cite pour rapprocher la facture.
                'reference_client' => $data['reference_client'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source_systeme' => $data['source_systeme'] ?? null,
                'source_id' => $data['source_id'] ?? null,
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

        // Une pièce issue d'une autre décrit la marchandise de SON client : un
        // bon de livraison passé à un autre client solderait quand même la
        // commande du premier (lien ligne à ligne), et la facture de cette
        // commande ne sortirait plus rien (famille de documents) — le premier
        // client serait facturé de ce qu'il n'a jamais reçu. Contrôlé ici et
        // non dans syncLignes : un enregistrement sans « lignes » garde aussi
        // ses liens.
        if (array_key_exists('tiers_id', $data)
            && $document->source_document_id !== null
            && (int) $data['tiers_id'] !== (int) $document->tiers_id) {
            throw ValidationException::withMessages([
                'tiers_id' => 'Une pièce issue d\'une autre garde le client de sa pièce d\'origine. Pour un autre client, créez une nouvelle pièce.',
            ]);
        }

        return DB::transaction(function () use ($document, $data) {
            $document->update(collect($data)->only([
                'tiers_id', 'date_document', 'date_echeance', 'notes',
            ])->all());

            if (array_key_exists('lignes', $data)) {
                // Lues AVANT la suppression : les lignes sont recréées à chaque
                // enregistrement, et ce qu'un écran ne sait pas afficher (le
                // lien vers la ligne de commande, le colis) n'existerait plus
                // nulle part une fois le DELETE passé.
                $anciennes = $document->lignes()->get()->keyBy('id');
                $lignes = array_map(
                    fn (array $ligne) => $this->heriterDeLAncienneLigne($ligne, $anciennes),
                    $data['lignes'],
                );

                $document->lignes()->delete();
                $this->syncLignes($document, $lignes);
            }

            return $document->fresh(['lignes', 'tiers']);
        });
    }

    /**
     * Ce qu'une ligne existante portait et que la requête n'a pas REDIT.
     *
     * Le formulaire renvoie désormais tout ce qu'il a reçu ; ceci est le filet
     * pour le client qui oublierait un champ. Sans lui, un bon de livraison
     * retouché perdait son lien vers la ligne de commande : sa validation ne
     * soldait plus le reliquat, et la commande réclamait une seconde livraison
     * de ce qui était déjà parti.
     *
     * Seule une ligne de CE document peut léguer quoi que ce soit : un `id`
     * inconnu (ligne d'une autre pièce, d'une autre société, ou brouillon
     * réenregistré entre-temps par un autre onglet) n'hérite de rien. Un champ
     * envoyé, même à null, est une décision du client et prime.
     *
     * @param  array<string, mixed>  $ligne
     * @param  Collection<int, DocumentVenteLigne>  $anciennes
     * @return array<string, mixed>
     */
    private function heriterDeLAncienneLigne(array $ligne, Collection $anciennes): array
    {
        $ancienne = isset($ligne['id']) ? $anciennes->get((int) $ligne['id']) : null;

        if ($ancienne === null) {
            return $ligne;
        }

        $memeArticle = (int) ($ligne['produit_id'] ?? 0) === (int) ($ancienne->produit_id ?? 0);

        // Le lien ne suit que l'article commandé : une ligne passée à un autre
        // article (ou en ligne libre) ne solde plus la commande — sinon la
        // commande se dirait livrée d'une marchandise qui n'est jamais partie.
        // Revérifié par syncLignes comme s'il avait été envoyé : l'héritage
        // n'ouvre aucun chemin que la saisie directe n'ouvrirait pas.
        if (! array_key_exists('source_ligne_id', $ligne) && $memeArticle) {
            $ligne['source_ligne_id'] = $ancienne->source_ligne_id;
        }

        // Le colis, lui, n'est repris que si la ligne vend TOUJOURS la même
        // chose : syncLignes recalcule la quantité à partir du colis, donc
        // hériter d'un colis sous une quantité retouchée écraserait en silence
        // ce que le vendeur vient de taper. Et seulement un colis COMPTÉ : un
        // conditionnement sans nombre (bon de livraison partiel d'avant la
        // correction) donnerait 0 colis, donc une quantité de 0.
        $memeQuantite = isset($ligne['quantite'])
            && abs((float) $ligne['quantite'] - (float) $ancienne->quantite) < 0.0005;

        if ($ancienne->conditionnement_id !== null
            && $ancienne->quantite_colis !== null
            && (float) $ancienne->quantite_colis > 0
            && ! array_key_exists('conditionnement_id', $ligne)
            && ! array_key_exists('quantite_colis', $ligne)
            && $memeArticle
            && $memeQuantite) {
            $ligne['conditionnement_id'] = $ancienne->conditionnement_id;
            $ligne['quantite_colis'] = (float) $ancienne->quantite_colis;
        }

        return $ligne;
    }

    public function delete(DocumentVente $document): void
    {
        $this->assertBrouillon($document);
        $document->delete();
    }

    /**
     * Bon de livraison partiel : on ne livre qu'une partie des lignes d'une
     * commande, le reste demeure en reliquat sur la commande.
     *
     * @param  array<int, array{source_ligne_id: int, quantite: float}>  $lignes
     */
    public function livrerPartiellement(DocumentVente $commande, array $lignes): DocumentVente
    {
        if ($commande->type !== DocumentVente::TYPE_COMMANDE) {
            throw ValidationException::withMessages([
                'type' => 'Seule une commande peut faire l\'objet d\'une livraison partielle.',
            ]);
        }

        if ($commande->statut !== DocumentVente::STATUT_VALIDE) {
            throw ValidationException::withMessages([
                'statut' => 'La commande doit être validée avant d\'être livrée.',
            ]);
        }

        return DB::transaction(function () use ($commande, $lignes) {
            $aLivrer = [];

            foreach ($lignes as $demande) {
                $source = DocumentVenteLigne::whereKey($demande['source_ligne_id'])
                    ->where('document_vente_id', $commande->id)
                    ->lockForUpdate()
                    ->first();

                if ($source === null) {
                    throw ValidationException::withMessages([
                        'lignes' => 'Ligne de commande introuvable.',
                    ]);
                }

                $quantite = round((float) $demande['quantite'], 3);

                if ($quantite <= 0) {
                    continue; // ligne non livrée cette fois
                }

                if ($quantite > $source->resteALivrer() + 0.0009) {
                    throw ValidationException::withMessages([
                        'lignes' => sprintf(
                            'Sur-livraison refusée pour « %s » : %s commandé, %s déjà livré, %s demandé.',
                            $source->designation,
                            rtrim(rtrim((string) $source->quantite, '0'), '.'),
                            rtrim(rtrim((string) $source->quantite_livree, '0'), '.'),
                            rtrim(rtrim((string) $quantite, '0'), '.'),
                        ),
                    ]);
                }

                $aLivrer[] = ['source' => $source, 'quantite' => $quantite];
            }

            if ($aLivrer === []) {
                throw ValidationException::withMessages([
                    'lignes' => 'Aucune quantité à livrer.',
                ]);
            }

            $bl = DocumentVente::create([
                'type' => DocumentVente::TYPE_BON_LIVRAISON,
                'code' => $this->sequences->next(self::PREFIXES[DocumentVente::TYPE_BON_LIVRAISON]),
                'statut' => DocumentVente::STATUT_BROUILLON,
                'tiers_id' => $commande->tiers_id,
                'source_document_id' => $commande->id,
                'entrepot_id' => $commande->entrepot_id,
                'opportunite_id' => $commande->opportunite_id,
                'date_document' => now()->toDateString(),
            ]);

            $position = 1;
            $totalHt = 0.0;
            $totalTva = 0.0;

            foreach ($aLivrer as $item) {
                /** @var DocumentVenteLigne $source */
                $source = $item['source'];
                $quantite = $item['quantite'];

                $montantHt = round($quantite * (float) $source->prix_unitaire * (1 - (float) $source->remise_percent / 100), 2);
                $montantTva = round($montantHt * (float) $source->tva_rate / 100, 2);
                [$conditionnementId, $quantiteColis] = $this->colisLivre($source, $quantite);

                $bl->lignes()->create([
                    'produit_id' => $source->produit_id,
                    'source_ligne_id' => $source->id,
                    'conditionnement_id' => $conditionnementId,
                    'quantite_colis' => $quantiteColis,
                    'designation' => $source->designation,
                    'quantite' => $quantite,
                    'prix_unitaire' => $source->prix_unitaire,
                    'remise_percent' => $source->remise_percent,
                    'tva_rate' => $source->tva_rate,
                    'montant_ht' => $montantHt,
                    'montant_tva' => $montantTva,
                    'montant_ttc' => round($montantHt + $montantTva, 2),
                    'position' => $position++,
                ]);

                $totalHt = round($totalHt + $montantHt, 2);
                $totalTva = round($totalTva + $montantTva, 2);
            }

            $bl->update([
                'total_ht' => $totalHt,
                'total_tva' => $totalTva,
                'total_ttc' => round($totalHt + $totalTva, 2),
            ]);

            return $bl->fresh(['lignes', 'tiers']);
        });
    }

    /**
     * Colis sous lequel part une livraison partielle d'une ligne au colis.
     *
     * Le colis ne se recopie pas tel quel : « 5 × Carton de 12 » livré à 24
     * donnerait un bon portant le carton SANS nombre — rien à imprimer, et un
     * nombre de 0 dès qu'on en déduit la quantité. Trois cas, comme le
     * formulaire (colisDeLigne) :
     * - tout le reliquat d'une ligne jamais entamée : le colis d'origine, même 2,5 ;
     * - un nombre ENTIER de colis : 24 = 2 cartons de 12 ;
     * - sinon : à l'unité, la quantité livrée fait foi.
     *
     * @return array{0: ?int, 1: ?float} conditionnement et nombre de colis
     */
    private function colisLivre(DocumentVenteLigne $source, float $quantite): array
    {
        $base = (float) ($source->conditionnement?->quantite_base ?? 0);

        if ($source->conditionnement_id === null || $base <= 0) {
            return [null, null];
        }

        if ($source->quantite_colis !== null
            && (float) $source->quantite_colis > 0
            && abs($quantite - (float) $source->quantite) < 0.0005) {
            return [$source->conditionnement_id, (float) $source->quantite_colis];
        }

        $nombre = round($quantite / $base);

        return $nombre >= 1 && abs($nombre * $base - $quantite) < 0.0005
            ? [$source->conditionnement_id, $nombre]
            : [null, null];
    }

    /**
     * Reporte sur la commande ce qui vient d'être livré. Appelé à la validation
     * du bon de livraison, c'est-à-dire au moment où la marchandise part.
     */
    private function reporterLivraison(DocumentVente $bl): void
    {
        foreach ($bl->lignes as $ligne) {
            if ($ligne->source_ligne_id === null) {
                continue; // BL direct, sans commande
            }

            $source = DocumentVenteLigne::whereKey($ligne->source_ligne_id)->lockForUpdate()->first();

            if ($source === null) {
                continue;
            }

            if ((float) $ligne->quantite > $source->resteALivrer() + 0.0009) {
                throw ValidationException::withMessages([
                    'lignes' => sprintf(
                        'Sur-livraison refusée pour « %s » : reste %s à livrer.',
                        $source->designation,
                        rtrim(rtrim((string) $source->resteALivrer(), '0'), '.'),
                    ),
                ]);
            }

            $source->update([
                'quantite_livree' => round((float) $source->quantite_livree + (float) $ligne->quantite, 3),
            ]);
        }
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

            // La marchandise part : on impute les quantités sur la commande.
            if ($document->type === DocumentVente::TYPE_BON_LIVRAISON) {
                $this->reporterLivraison($document->fresh(['lignes']));
            }

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
                // …et de son opportunité, pour que l'affaire suive toute la chaîne
                // devis → commande → BL → facture (entonnoir CRM et documents liés).
                'opportunite_id' => $source->opportunite_id,
                'date_document' => now()->toDateString(),
                'notes' => $source->notes,
                'total_ht' => $source->total_ht,
                'total_tva' => $source->total_tva,
                'total_ttc' => $source->total_ttc,
            ]);

            // Une commande livrée en totalité garde le lien ligne à ligne, pour
            // que le reliquat se solde comme lors d'une livraison partielle.
            $tracerLivraison = $source->type === DocumentVente::TYPE_COMMANDE
                && $targetType === DocumentVente::TYPE_BON_LIVRAISON;

            foreach ($source->lignes as $ligne) {
                $document->lignes()->create($ligne->only([
                    'produit_id', 'conditionnement_id', 'quantite_colis',
                    'designation', 'quantite', 'prix_unitaire',
                    'remise_percent', 'tva_rate', 'montant_ht', 'montant_tva',
                    'montant_ttc', 'position',
                ]) + ['source_ligne_id' => $tracerLivraison ? $ligne->id : null]);
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
                // Session de caisse qui encaisse réellement l'argent : c'est
                // elle, et non celle qui a émis le ticket, que crédite le Z.
                'pos_session_id' => $data['pos_session_id'] ?? null,
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
        $client = $document->tiers;

        foreach ($lignes as $data) {
            $produit = ! empty($data['produit_id']) ? Produit::find($data['produit_id']) : null;
            $sourceLigneId = $this->sourceLigneValide($document, $data['source_ligne_id'] ?? null, $data['produit_id'] ?? null);

            // Vente au colis : le carton est converti en unité de stock, car
            // c'est en unité de stock que raisonnent le stock et la compta.
            $conditionnement = ! empty($data['conditionnement_id'])
                ? \App\Modules\Catalogue\Models\ProduitConditionnement::find($data['conditionnement_id'])
                : null;
            // Un nombre de colis nul ou absent n'est pas un colis : on garde la
            // quantité envoyée plutôt que d'en déduire 0 × 12 = 0 en silence.
            $quantiteColis = $conditionnement !== null
                && isset($data['quantite_colis'])
                && (float) $data['quantite_colis'] > 0
                ? (float) $data['quantite_colis']
                : null;

            $quantite = $quantiteColis !== null
                ? round($quantiteColis * (float) $conditionnement->quantite_base, 3)
                : (float) $data['quantite'];

            $designation = $data['designation'] ?? $produit?->name;
            // Prix explicite = décision du vendeur (négociation ponctuelle).
            // Sinon le tarif du client s'applique, paliers de quantité compris.
            $prixUnitaire = isset($data['prix_unitaire']) && $data['prix_unitaire'] !== null
                ? (float) $data['prix_unitaire']
                : ($produit !== null ? $this->tarifs->prixPour($produit, $client, $quantite) : 0.0);
            $tvaRate = isset($data['tva_rate']) && $data['tva_rate'] !== null
                ? (float) $data['tva_rate']
                : (float) ($produit?->tva_rate ?? 20);
            $remise = (float) ($data['remise_percent'] ?? 0);

            $montantHt = round($quantite * $prixUnitaire * (1 - $remise / 100), 2);
            $montantTva = round($montantHt * $tvaRate / 100, 2);

            $document->lignes()->create([
                'produit_id' => $produit?->id,
                'conditionnement_id' => $conditionnement?->id,
                'quantite_colis' => $quantiteColis,
                'source_ligne_id' => $sourceLigneId,
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

    /**
     * Lien vers la ligne de commande qu'une ligne de bon de livraison solde.
     *
     * C'est lui qui, à la validation du bon, impute la quantité livrée sur la
     * commande (reporterLivraison). Le forger permettrait de solder le reliquat
     * d'une autre commande — voire d'une autre société : les lignes n'ont pas
     * de tenant propre, un `whereKey` nu les verrait toutes. On n'accepte donc
     * qu'une ligne de LA commande dont ce bon est issu, seul cas où
     * transformer() et livrerPartiellement() en posent un. Miroir d'AchatService.
     *
     * Et seulement pour l'article commandé (transformer() et
     * livrerPartiellement() le recopient toujours) : la quantité livrée est
     * imputée telle quelle sur la ligne de commande. Solder dix sacs de ciment
     * par dix sacs de chaux — ou par une ligne libre, qui ne sort rien du
     * stock — marquerait la commande livrée sans que le ciment soit parti. Un
     * article de remplacement se livre sur une ligne non liée : le reliquat
     * reste alors ouvert, et visible, sur la commande.
     */
    private function sourceLigneValide(DocumentVente $document, mixed $sourceLigneId, mixed $produitId): ?int
    {
        if (empty($sourceLigneId)) {
            return null;
        }

        // `source` et non un find() : la relation reste en mémoire d'une ligne
        // à l'autre, un bon de cinquante lignes ne relit pas cinquante fois sa
        // commande.
        $commande = $document->source;

        if ($document->type !== DocumentVente::TYPE_BON_LIVRAISON
            || $commande?->type !== DocumentVente::TYPE_COMMANDE) {
            throw ValidationException::withMessages([
                'lignes' => 'Seul un bon de livraison issu d\'une commande peut désigner une ligne de commande.',
            ]);
        }

        $ligneCommande = DocumentVenteLigne::whereKey($sourceLigneId)
            ->where('document_vente_id', $commande->id)
            ->first(['id', 'produit_id', 'designation']);

        if ($ligneCommande === null) {
            throw ValidationException::withMessages([
                'lignes' => 'Ligne de commande source introuvable.',
            ]);
        }

        // Identifiants BRUTS : un article supprimé depuis n'est plus lu par
        // Produit::find(), mais reste celui que la ligne désigne.
        if ((int) ($produitId ?? 0) !== (int) ($ligneCommande->produit_id ?? 0)) {
            throw ValidationException::withMessages([
                'lignes' => sprintf(
                    '« %s » : une ligne de bon de livraison ne solde la commande qu\'avec l\'article commandé. '
                    .'Livrez un article de remplacement sur une ligne à part.',
                    $ligneCommande->designation,
                ),
            ]);
        }

        return (int) $sourceLigneId;
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
