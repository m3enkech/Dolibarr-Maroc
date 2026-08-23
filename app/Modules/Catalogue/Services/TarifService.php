<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitTarif;
use App\Modules\Tiers\Models\Tiers;

/**
 * Moteur tarifaire de la vente en gros.
 *
 * Le prix retenu est le plus SPÉCIFIQUE applicable, dans cet ordre :
 *   1. prix négocié pour CE client, au palier de quantité atteint ;
 *   2. prix de la catégorie tarifaire du client (ou de la catégorie par défaut) ;
 *   3. prix catalogue de l'article (sell_price).
 *
 * À l'intérieur d'un niveau, c'est le palier `quantite_min` le plus élevé
 * atteint par la quantité vendue qui l'emporte (prix dégressifs).
 */
class TarifService
{
    /** Prix unitaire HT applicable à cet article, pour ce client, à cette quantité. */
    public function prixPour(Produit $produit, ?Tiers $client, float $quantite = 1): float
    {
        $tarifs = ProduitTarif::where('produit_id', $produit->id)
            ->where('quantite_min', '<=', $quantite)
            ->orderByDesc('quantite_min')
            ->get();

        if ($client !== null) {
            $negocie = $tarifs->firstWhere('tiers_id', $client->id);
            if ($negocie !== null) {
                return (float) $negocie->prix;
            }
        }

        $categorieId = $client?->categorie_tarifaire_id ?? $this->categorieParDefaut()?->id;

        if ($categorieId !== null) {
            $parCategorie = $tarifs->first(
                fn (ProduitTarif $t) => $t->tiers_id === null && $t->categorie_tarifaire_id === $categorieId,
            );

            if ($parCategorie !== null) {
                return (float) $parCategorie->prix;
            }
        }

        return (float) $produit->sell_price;
    }

    /**
     * Grille complète d'un client : prix applicable par article, pour une
     * quantité de 1. Sert à préremplir la caisse et les écrans de vente, qui
     * recalculent ensuite localement les paliers.
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
                // Un prix négocié pour le client masque celui de sa catégorie.
                $negocies = $lot->where('tiers_id', $client?->id)->filter(fn ($t) => $t->tiers_id !== null);
                $retenus = $negocies->isNotEmpty() ? $negocies : $lot->whereNull('tiers_id');

                return [
                    'produit_id' => (int) $produitId,
                    'paliers' => $retenus
                        ->sortBy('quantite_min')
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
