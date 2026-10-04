<?php

namespace App\Modules\Stock\Services;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Support\Collection;

/**
 * La famille d'une pièce de vente et ce qu'elle porte en stock.
 *
 * Une commande, ses bons de livraison, ses factures et leurs avoirs décrivent
 * la MÊME marchandise : ils forment une famille, la composante connexe de la
 * chaîne source_document_id. Partagé entre la sortie de stock (StockService) et
 * le diagnostic (stock:verifier-familles) : si chacun calculait sa règle, le
 * diagnostic finirait par mesurer l'écart à une règle que plus rien n'applique.
 *
 * LIMITE : un devis transformé en plusieurs commandes (devis cadre, commandes
 * mensuelles) ne forme qu'UNE famille. La facture de l'une couvre alors le BL
 * d'une autre jusqu'à ce que celle-ci soit facturée à son tour : le stock
 * affiché reste trop haut entre les deux, puis se rattrape — jamais si la
 * seconde commande n'est pas facturée dans l'outil. La famille ne peut pas
 * être découpée par commande : une facture tirée du devis doit couvrir les BL
 * de ses commandes (SortieStockUniqueTest, test i et
 * test_limite_devis_transforme_en_deux_commandes).
 */
class FamilleDocuments
{
    /** Les pièces qui écrivent dans le stock, donc qui entrent dans le bilan. */
    public const TYPES_DU_BILAN = [
        DocumentVente::TYPE_BON_LIVRAISON,
        DocumentVente::TYPE_FACTURE,
        DocumentVente::TYPE_AVOIR,
    ];

    /** Document le plus haut de la chaîne des sources (devis, commande ou pièce directe). */
    public function racine(DocumentVente $document): int
    {
        $courant = $document;

        // Borne de sûreté : une chaîne réelle fait deux ou trois maillons ;
        // au-delà, la donnée est corrompue et boucler serait pire.
        for ($i = 0; $i < 10 && $courant->source_document_id !== null; $i++) {
            $parent = DocumentVente::find($courant->source_document_id);

            if ($parent === null) {
                break;
            }

            $courant = $parent;
        }

        return $courant->id;
    }

    /**
     * Tous les documents issus de cette racine, elle comprise.
     *
     * @return array<int, int>
     */
    public function descendance(int $racine): array
    {
        $ids = [$racine];
        $frontiere = [$racine];

        for ($i = 0; $i < 10 && $frontiere !== []; $i++) {
            $enfants = DocumentVente::whereIn('source_document_id', $frontiere)
                ->pluck('id')
                ->all();

            $enfants = array_values(array_diff($enfants, $ids));

            if ($enfants === []) {
                break;
            }

            $ids = array_merge($ids, $enfants);
            $frontiere = $enfants;
        }

        return $ids;
    }

    /**
     * Rang de la prochaine pièce validée dans cette famille, à demander SOUS le
     * verrou de la racine : deux validations de la même famille ne peuvent
     * alors pas obtenir le même rang, et le rang suit l'ordre réel des
     * validations — celui que piecesDuBilan rejoue.
     *
     * @param  array<int, int>  $ids  les autres pièces de la famille
     */
    public function prochainRang(array $ids): int
    {
        return 1 + (int) DocumentVente::whereIn('id', array_values($ids))->max('rang_stock');
    }

    /**
     * Les pièces validées de ces documents qui comptent dans le bilan, lignes
     * chargées, dans l'ORDRE où elles ont été validées — le bilan se rejoue, il
     * ne se somme pas (voir BilanDeSortie).
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, DocumentVente>
     */
    public function piecesDuBilan(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DocumentVente::query()
            ->whereIn('id', array_values($ids))
            ->whereIn('type', self::TYPES_DU_BILAN)
            ->whereIn('statut', [DocumentVente::STATUT_VALIDE, DocumentVente::STATUT_PAYE])
            // Une pièce reprise d'un autre logiciel n'a fait sortir que ce que
            // l'import a décidé (option de reprise) : elle est hors règle.
            ->whereNull('source_systeme')
            // Lu sous le verrou de famille, qu'une facture attend en tenant déjà
            // celui de sa séquence : seules les colonnes du bilan (position
            // sert au tri de la relation).
            ->with('lignes:id,document_vente_id,produit_id,quantite,position')
            ->get()
            // Trié ici plutôt qu'en SQL : SQLite et PostgreSQL ne rangent pas
            // les NULL du même côté.
            ->sort(fn (DocumentVente $a, DocumentVente $b) => $this->cleDeRejeu($a) <=> $this->cleDeRejeu($b))
            ->values();
    }

    /**
     * Les produits que citent ces pièces, composants de kit chargés, en UNE
     * requête plutôt qu'une par ligne.
     *
     * Sans $avecArchives, un article archivé (suppression douce) est ignoré :
     * c'est le comportement de la sortie à la validation, inchangé — une pièce
     * qui le porte ne le sort pas. Le diagnostic, lui, doit les voir : sans
     * eux, toute sortie passée d'un article archivé depuis paraîtrait « due 0 »
     * et ressortirait en fausse sur-sortie.
     *
     * @param  iterable<DocumentVente>  $documents
     * @return Collection<int, Produit> indexés par id
     */
    public function produitsDe(iterable $documents, bool $avecArchives = false): Collection
    {
        $ids = collect($documents)
            ->flatMap(fn (DocumentVente $d) => $d->lignes->pluck('produit_id'))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        if ($avecArchives) {
            return Produit::withTrashed()
                ->with(['composants.composant' => fn ($q) => $q->withTrashed()])
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        }

        return Produit::with('composants.composant')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Ce qu'une pièce fait bouger dans le stock, ligne à ligne : les services ne
     * bougent pas, un kit ne stocke rien lui-même et compte pour ses composants
     * physiques (quantité ligne × quantité du composant). La quantité d'une
     * ligne est déjà en unité de stock, y compris vendue au colis.
     *
     * LIMITE : un kit est éclaté avec sa composition ACTUELLE, pour toutes les
     * pièces de la famille. Recomposé entre le BL et la facture (l'ordre
     * courant), c'est juste : la facture d'un kit livré ne sort rien. Recomposé
     * entre la facture et le BL, le BL emporte la nouvelle composition mais le
     * rejeu relit la facture avec elle aussi, et l'écart de composition ne sort
     * pas. Figer la composition à la validation inverserait le défaut sans le
     * supprimer : il faudrait raisonner en kits ET en composants figés.
     *
     * @param  Collection<int, Produit>  $produits  voir produitsDe()
     * @return list<array{produit: Produit, quantite: float, note: ?string}>
     */
    public function elementsDeStock(DocumentVente $document, Collection $produits): array
    {
        $elements = [];

        foreach ($document->lignes as $ligne) {
            $produit = $ligne->produit_id !== null ? $produits->get($ligne->produit_id) : null;

            if ($produit === null) {
                continue;
            }

            if ($produit->isKit()) {
                foreach ($produit->composants as $composant) {
                    if ($composant->composant?->type !== 'product') {
                        continue;
                    }

                    $elements[] = [
                        'produit' => $composant->composant,
                        'quantite' => (float) $ligne->quantite * (float) $composant->quantite,
                        'note' => 'Kit '.$produit->name,
                    ];
                }

                continue;
            }

            if ($produit->type === 'product') {
                $elements[] = ['produit' => $produit, 'quantite' => (float) $ligne->quantite, 'note' => null];
            }
        }

        return $elements;
    }

    /**
     * Total par produit : deux lignes du même article, ou un kit et l'un de ses
     * composants, visent le même stock.
     *
     * @param  list<array{produit: Produit, quantite: float, note: ?string}>  $elements
     * @return array<int, float>
     */
    public function quantites(array $elements): array
    {
        $totaux = [];

        foreach ($elements as $element) {
            $id = $element['produit']->id;
            $totaux[$id] = round(($totaux[$id] ?? 0.0) + $element['quantite'], 3);
        }

        return $totaux;
    }

    /**
     * Clé de tri du rejeu. Les pièces rangées (voir prochainRang) suivent leur
     * rang : c'est l'ordre de prise du verrou de famille. Celles sans rang
     * passent avant : la racine, toujours validée la première, et les pièces
     * d'avant le rang, toutes validées avant la première pièce rangée — entre
     * elles, validated_at puis l'identifiant, faute de mieux (à la seconde,
     * et l'identifiant suit la création du brouillon, pas sa validation).
     * Sans rang aussi, et donc mal placée : une pièce qui ne portait rien de
     * stocké à sa validation (chemin court de StockService) — sans poids dans
     * le bilan, sauf kit recomposé ou article archivé relu par le diagnostic.
     *
     * @return array{int, int, int, int}
     */
    private function cleDeRejeu(DocumentVente $document): array
    {
        return [
            $document->rang_stock === null ? 0 : 1,
            (int) $document->rang_stock,
            ($document->validated_at ?? $document->created_at)?->getTimestamp() ?? 0,
            $document->id,
        ];
    }
}
