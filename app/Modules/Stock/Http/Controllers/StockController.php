<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Http\Requests\StoreMouvementRequest;
use App\Modules\Stock\Http\Requests\StoreTransfertRequest;
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
            // Quantités attendues : lignes de commandes fournisseur validées
            // non soldées (reste à recevoir).
            ->addSelect(['en_commande' => $this->enCommandeSubquery()])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => collect($produits->items())->map(fn (Produit $produit) => [
                'produit_id' => $produit->id,
                'code' => $produit->code,
                'name' => $produit->name,
                'unit' => $produit->unit,
                'quantite' => number_format((float) $produit->stock_quantite, 3, '.', ''),
                'en_commande' => number_format((float) $produit->en_commande, 3, '.', ''),
                'stock_min' => $produit->stock_min !== null
                    ? number_format((float) $produit->stock_min, 3, '.', '')
                    : null,
                'sous_seuil' => $produit->stock_min !== null
                    && (float) $produit->stock_quantite <= (float) $produit->stock_min,
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

    /**
     * Produits sous leur seuil d'alerte : stock courant ≤ stock_min. Renvoie la
     * quantité déjà en commande et une suggestion de réappro (quantité cible
     * stock_reappro, à défaut le seuil, moins ce qu'on a et ce qui arrive).
     */
    public function alertes(Request $request): JsonResponse
    {
        $entrepotId = $request->integer('entrepot_id') ?: null;

        $produits = Produit::query()
            ->where('type', 'product')
            ->whereNotNull('stock_min')
            ->addSelect(['stock_quantite' => Stock::query()
                ->selectRaw('COALESCE(SUM(quantite), 0)')
                ->whereColumn('produit_id', 'produits.id')
                ->when($entrepotId, fn ($q) => $q->where('entrepot_id', $entrepotId)),
            ])
            ->addSelect(['en_commande' => $this->enCommandeSubquery()])
            ->orderBy('name')
            ->get()
            ->filter(fn (Produit $p) => (float) $p->stock_quantite <= (float) $p->stock_min)
            ->map(function (Produit $p) {
                $cible = (float) ($p->stock_reappro ?? $p->stock_min);
                $suggestion = max(0, round($cible - (float) $p->stock_quantite - (float) $p->en_commande, 3));

                return [
                    'produit_id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'unit' => $p->unit,
                    'quantite' => number_format((float) $p->stock_quantite, 3, '.', ''),
                    'stock_min' => number_format((float) $p->stock_min, 3, '.', ''),
                    'stock_reappro' => $p->stock_reappro !== null
                        ? number_format((float) $p->stock_reappro, 3, '.', '')
                        : null,
                    'en_commande' => number_format((float) $p->en_commande, 3, '.', ''),
                    'suggestion' => number_format($suggestion, 3, '.', ''),
                ];
            })
            ->values();

        return response()->json(['data' => $produits]);
    }

    /** Transfert d'une quantité d'un entrepôt à un autre (sortie + entrée liées). */
    public function transferer(StoreTransfertRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->service->transferer(
            Produit::findOrFail($data['produit_id']),
            Entrepot::findOrFail($data['entrepot_source_id']),
            Entrepot::findOrFail($data['entrepot_dest_id']),
            (float) $data['quantite'],
            $data['note'] ?? null,
        );

        return response()->json([
            'reference' => $result['reference'],
            'sortie' => new MouvementResource($result['sortie']->load(['produit', 'entrepot'])),
            'entree' => new MouvementResource($result['entree']->load(['produit', 'entrepot'])),
        ], 201);
    }

    /** Reste à recevoir sur les commandes fournisseur validées non soldées. */
    private function enCommandeSubquery(): \Illuminate\Database\Eloquent\Builder
    {
        return DocumentAchatLigne::query()
            ->selectRaw('COALESCE(SUM(quantite - quantite_recue), 0)')
            ->whereColumn('produit_id', 'produits.id')
            ->whereHas('document', fn ($q) => $q
                ->where('type', DocumentAchat::TYPE_COMMANDE)
                ->whereIn('statut', [
                    DocumentAchat::STATUT_VALIDE,
                    DocumentAchat::STATUT_RECUE_PARTIELLE,
                ]));
    }
}
