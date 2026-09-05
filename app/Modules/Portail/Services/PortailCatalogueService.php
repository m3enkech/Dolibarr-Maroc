<?php

namespace App\Modules\Portail\Services;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitConditionnement;
use App\Modules\Catalogue\Services\TarifService;
use App\Modules\Stock\Models\Stock;
use App\Modules\Tiers\Models\Tiers;

/**
 * Catalogue tel que le voit un acheteur du portail : les articles du grossiste,
 * chacun au tarif négocié de CE client, avec ses paliers dégressifs et ses
 * conditionnements.
 *
 * Toutes les requêtes s'appuient sur le contexte tenant déjà posé et vérifié par
 * SetTenantPortail : on ne refiltre pas à la main, le scope global s'en charge.
 */
class PortailCatalogueService
{
    public function __construct(private TarifService $tarifs) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function lister(Tiers $client, ?string $recherche, int $page, int $parPage = 24): array
    {
        $query = Produit::query()
            ->where('is_active', true)
            // Un acheteur n'achète pas un kit de composants internes : on expose
            // ce qui se vend.
            ->whereIn('type', ['product', 'service', 'kit'])
            ->when($recherche !== null && $recherche !== '', function ($q) use ($recherche) {
                $terme = '%'.$recherche.'%';
                $q->where(fn ($sub) => $sub
                    ->whereLike('name', $terme)
                    ->orWhereLike('code', $terme)
                    ->orWhere('barcode', $recherche));
            })
            ->orderBy('name');

        $produits = $query->paginate($parPage, ['*'], 'page', $page);

        $ids = collect($produits->items())->pluck('id');
        $stocks = $this->disponibilites($ids);
        $conditionnements = ProduitConditionnement::whereIn('produit_id', $ids)
            ->orderBy('quantite_base')
            ->get()
            ->groupBy('produit_id');

        $data = collect($produits->items())->map(fn (Produit $p) => $this->presenter(
            $p,
            $client,
            $stocks[$p->id] ?? 0.0,
            $conditionnements[$p->id] ?? collect(),
        ))->all();

        return [
            'data' => $data,
            'meta' => [
                'total' => $produits->total(),
                'page' => $produits->currentPage(),
                'dernier_page' => $produits->lastPage(),
                'par_page' => $produits->perPage(),
            ],
        ];
    }

    /** Fiche d'un article, avec la grille de paliers applicable à ce client. */
    public function detail(Tiers $client, Produit $produit): array
    {
        $stocks = $this->disponibilites(collect([$produit->id]));
        $conditionnements = ProduitConditionnement::where('produit_id', $produit->id)
            ->orderBy('quantite_base')->get();

        return $this->presenter($produit, $client, $stocks[$produit->id] ?? 0.0, $conditionnements);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProduitConditionnement>  $conditionnements
     * @return array<string, mixed>
     */
    private function presenter(Produit $produit, Tiers $client, float $stock, $conditionnements): array
    {
        $prixUnitaire = $this->tarifs->prixPour($produit, $client, 1);
        $tva = (float) $produit->tva_rate;

        // Paliers de ce client pour cet article, tels qu'ils s'appliqueront.
        $paliers = collect($this->tarifs->grillePour($client))
            ->firstWhere('produit_id', $produit->id)['paliers'] ?? [];

        return [
            'id' => $produit->id,
            'code' => $produit->code,
            'name' => $produit->name,
            'description' => $produit->description,
            'type' => $produit->type,
            'unit' => $produit->unit,
            'tva_rate' => number_format($tva, 2, '.', ''),
            'prix_ht' => number_format($prixUnitaire, 2, '.', ''),
            'prix_ttc' => number_format(round($prixUnitaire * (1 + $tva / 100), 2), 2, '.', ''),
            'paliers' => $paliers,
            'conditionnements' => $conditionnements->map(fn (ProduitConditionnement $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'quantite_base' => (float) $c->quantite_base,
            ])->values()->all(),
            // On expose la DISPONIBILITÉ, pas le niveau de stock : le stock exact
            // d'un fournisseur est une information commercialement sensible.
            'disponible' => $produit->type !== 'product' || $stock > 0,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $produitIds
     * @return array<int, float>
     */
    private function disponibilites($produitIds): array
    {
        return Stock::whereIn('produit_id', $produitIds)
            ->selectRaw('produit_id, SUM(quantite) as total')
            ->groupBy('produit_id')
            ->pluck('total', 'produit_id')
            ->map(fn ($q) => (float) $q)
            ->all();
    }
}
