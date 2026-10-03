<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitTarif;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Support\Collection;

/**
 * Moteur tarifaire de la vente en gros.
 *
 * Le prix retenu est le plus SPÉCIFIQUE applicable, dans cet ordre :
 *   1. prix négocié pour CE client, au palier de quantité atteint ;
 *   2. prix de la catégorie tarifaire du client (ou de la catégorie par défaut) ;
 *   3. prix catalogue de l'article (sell_price).
 *
 * À l'intérieur d'un niveau, c'est le palier `quantite_min` le plus élevé
 * atteint par la quantité vendue qui l'emporte (prix dégressifs). Un niveau
 * dont aucun palier n'est atteint cède la place au suivant : un prix négocié
 * « dès 10 » n'empêche pas la catégorie de s'appliquer à 5.
 *
 * Le prix passe toujours par choisir() : la facture (VenteService), le prix de
 * base du portail et l'écran de saisie des ventes (GET /ventes/prix) lisent le
 * MÊME algorithme, si bien que le prix proposé au vendeur est celui que le
 * serveur aurait appliqué. grillePour(), qui livre des PALIERS et non un prix
 * (la caisse les applique elle-même, hors ligne compris), les compose pour que
 * le palier le plus haut atteint redonne ce même prix — voir sa note.
 */
class TarifService
{
    /** Prix unitaire HT applicable à cet article, pour ce client, à cette quantité. */
    public function prixPour(Produit $produit, ?Tiers $client, float $quantite = 1): float
    {
        return $this->tarifPour($produit, $client, $quantite)->prix;
    }

    /** Même calcul que prixPour(), avec l'origine du prix retenu. */
    public function tarifPour(Produit $produit, ?Tiers $client, float $quantite = 1): TarifApplique
    {
        return $this->tarifsPour($client, [[$produit, $quantite]])[0];
    }

    /**
     * Tarifs de plusieurs lignes d'un même client, en une seule lecture.
     *
     * Changer le client d'un devis de trente lignes recalcule trente prix : une
     * requête par article ferait trente allers-retours à la base, pour un
     * résultat identique. Les tarifs de TOUS les articles sont donc lus d'un
     * coup — restreints à ce client et à sa catégorie, car les prix négociés
     * pour les autres clients ne peuvent jamais s'appliquer ici.
     *
     * @param  list<array{0: Produit, 1: float}>  $demandes  article et quantité (en unité de stock)
     * @return list<TarifApplique> dans l'ordre des demandes
     */
    public function tarifsPour(?Tiers $client, array $demandes): array
    {
        if ($demandes === []) {
            return [];
        }

        $categorieId = $client?->categorie_tarifaire_id ?? $this->categorieParDefaut()?->id;
        $categorieId = $categorieId !== null ? (int) $categorieId : null;
        // Catégorie venue du réglage de l'entreprise et non de la fiche client
        // (ou pas de client du tout) : l'écran ne doit pas l'annoncer comme le
        // tarif du client.
        $categorieParDefaut = $client?->categorie_tarifaire_id === null;

        $tarifs = ($client === null && $categorieId === null)
            ? new Collection
            : ProduitTarif::query()
                ->whereIn('produit_id', collect($demandes)->map(fn (array $d) => $d[0]->id)->unique()->values())
                ->where(function ($q) use ($client, $categorieId) {
                    if ($client !== null) {
                        $q->orWhere('tiers_id', $client->id);
                    }
                    if ($categorieId !== null) {
                        $q->orWhere(fn ($c) => $c->whereNull('tiers_id')->where('categorie_tarifaire_id', $categorieId));
                    }
                })
                ->get();

        $parProduit = $tarifs->groupBy('produit_id');

        return array_map(
            fn (array $d) => $this->choisir($d[0], $client, $categorieId, $categorieParDefaut, $parProduit->get($d[0]->id, new Collection), (float) $d[1]),
            array_values($demandes),
        );
    }

    /**
     * LA règle de priorité, écrite une seule fois.
     *
     * @param  Collection<int, ProduitTarif>  $tarifs  tarifs de CET article (tous paliers)
     */
    private function choisir(Produit $produit, ?Tiers $client, ?int $categorieId, bool $categorieParDefaut, Collection $tarifs, float $quantite): TarifApplique
    {
        // Paliers atteints, du plus haut au plus bas : le premier trouvé dans un
        // niveau est le plus favorable que la quantité ouvre dans ce niveau.
        $atteints = $tarifs
            ->filter(fn (ProduitTarif $t) => (float) $t->quantite_min <= $quantite)
            ->sortByDesc(fn (ProduitTarif $t) => (float) $t->quantite_min);

        if ($client !== null) {
            $negocie = $atteints->first(
                fn (ProduitTarif $t) => $t->tiers_id !== null && (int) $t->tiers_id === (int) $client->id,
            );

            if ($negocie !== null) {
                return new TarifApplique((float) $negocie->prix, TarifApplique::CLIENT, (float) $negocie->quantite_min);
            }
        }

        if ($categorieId !== null) {
            // Comparaison en entiers : un identifiant relu d'un formulaire peut
            // arriver en chaîne, et `"3" === 3` écarterait le bon tarif.
            $parCategorie = $atteints->first(
                fn (ProduitTarif $t) => $t->tiers_id === null && (int) $t->categorie_tarifaire_id === (int) $categorieId,
            );

            if ($parCategorie !== null) {
                return new TarifApplique(
                    (float) $parCategorie->prix,
                    TarifApplique::CATEGORIE,
                    (float) $parCategorie->quantite_min,
                    (int) $categorieId,
                    $categorieParDefaut,
                );
            }
        }

        return new TarifApplique((float) $produit->sell_price, TarifApplique::CATALOGUE);
    }

    /**
     * Grille complète d'un client : prix applicable par article, pour une
     * quantité de 1. Sert à préremplir la caisse et les écrans de vente, qui
     * recalculent ensuite localement les paliers.
     *
     * Les paliers livrés sont ceux de choisir(), mis bout à bout : ceux de la
     * catégorie SOUS le premier palier négocié, puis les paliers négociés. Le
     * palier le plus haut atteint (seule règle de la caisse) redonne alors,
     * à toute quantité, le prix que le serveur facture. La grille ne livrait
     * autrefois que les paliers négociés dès qu'il en existait un : sous ce
     * palier, la caisse affichait le catalogue (100) là où le serveur facturait
     * la catégorie (90) — et refusait l'encaissement, le paiement dépassant le
     * total du ticket. Un client au prix négocié « dès 10 » ne pouvait plus
     * acheter 5 unités en caisse.
     *
     * @return array<int, array{produit_id: int, prix: string, paliers: array}>
     */
    public function grillePour(?Tiers $client): array
    {
        $categorieId = $client?->categorie_tarifaire_id ?? $this->categorieParDefaut()?->id;

        $tarifs = ProduitTarif::query()
            ->when($client !== null,
                fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('tiers_id', $client->id)
                    ->orWhere(fn ($c) => $c->whereNull('tiers_id')->where('categorie_tarifaire_id', $categorieId))),
                fn ($q) => $q->whereNull('tiers_id')->where('categorie_tarifaire_id', $categorieId),
            )
            ->orderBy('quantite_min')
            ->get();

        return $tarifs
            ->groupBy('produit_id')
            ->map(function ($lot, $produitId) use ($client) {
                // Un prix négocié masque la catégorie dès SON premier palier,
                // pas en dessous : choisir() n'y passe au négocié qu'une fois
                // ce palier atteint.
                $negocies = $client === null
                    ? new Collection
                    : $lot->filter(fn (ProduitTarif $t) => $t->tiers_id !== null && (int) $t->tiers_id === (int) $client->id);
                $categorie = $lot->whereNull('tiers_id');
                $seuil = $negocies->min(fn (ProduitTarif $t) => (float) $t->quantite_min);

                $retenus = $negocies->isEmpty()
                    ? $categorie
                    : $categorie
                        ->filter(fn (ProduitTarif $t) => (float) $t->quantite_min < $seuil)
                        ->concat($negocies);

                return [
                    'produit_id' => (int) $produitId,
                    'paliers' => $retenus
                        ->sortBy(fn (ProduitTarif $t) => (float) $t->quantite_min)
                        ->map(fn (ProduitTarif $t) => [
                            'quantite_min' => (float) $t->quantite_min,
                            'prix' => number_format((float) $t->prix, 2, '.', ''),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function categorieParDefaut(): ?CategorieTarifaire
    {
        return CategorieTarifaire::where('is_default', true)->first();
    }
}
