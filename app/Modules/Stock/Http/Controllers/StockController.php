<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Http\Requests\StoreMouvementRequest;
use App\Modules\Stock\Http\Resources\MouvementResource;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Stock\Models\MouvementStock;
use App\Modules\Stock\Models\Stock;
use App\Modules\Stock\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __construct(private StockService $service) {}

    /** Niveaux de stock agrégés par produit (optionnellement filtrés par entrepôt). */
    public function niveaux(Request $request): JsonResponse
    {
        $entrepotId = $request->integer('entrepot_id') ?: null;

        $produits = Produit::query()
            ->where('type', 'product')
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search));
            })
            ->addSelect(['stock_quantite' => Stock::query()
                ->selectRaw('COALESCE(SUM(quantite), 0)')
                ->whereColumn('produit_id', 'produits.id')
                ->when($entrepotId, fn ($q) => $q->where('entrepot_id', $entrepotId)),
            ])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => collect($produits->items())->map(fn (Produit $produit) => [
                'produit_id' => $produit->id,
                'code' => $produit->code,
                'name' => $produit->name,
                'unit' => $produit->unit,
                'quantite' => number_format((float) $produit->stock_quantite, 3, '.', ''),
                'valeur_achat' => $produit->buy_price !== null
                    ? number_format((float) $produit->stock_quantite * (float) $produit->buy_price, 2, '.', '')
                    : null,
            ]),
            'meta' => [
                'current_page' => $produits->currentPage(),
                'last_page' => $produits->lastPage(),
                'per_page' => $produits->perPage(),
                'total' => $produits->total(),
            ],
        ]);
    }

    public function mouvements(Request $request): AnonymousResourceCollection
    {
        $mouvements = MouvementStock::query()
            ->with(['produit', 'entrepot'])
            ->when($request->integer('produit_id'), fn ($q, $id) => $q->where('produit_id', $id))
            ->when($request->integer('entrepot_id'), fn ($q, $id) => $q->where('entrepot_id', $id))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return MouvementResource::collection($mouvements);
    }

    public function creerMouvement(StoreMouvementRequest $request): MouvementResource
    {
        $data = $request->validated();
        $produit = Produit::findOrFail($data['produit_id']);
        $entrepot = Entrepot::findOrFail($data['entrepot_id']);
        $quantite = (float) $data['quantite'];
        $note = $data['note'] ?? null;

        $mouvement = match ($data['type']) {
            MouvementStock::TYPE_ENTREE => $this->service->entree($produit, $entrepot, $quantite, $note),
            MouvementStock::TYPE_SORTIE => $this->service->sortie($produit, $entrepot, $quantite, $note),
            MouvementStock::TYPE_AJUSTEMENT => $this->service->ajuster($produit, $entrepot, $quantite, $note),
        };

        return new MouvementResource($mouvement->load(['produit', 'entrepot']));
    }
}
