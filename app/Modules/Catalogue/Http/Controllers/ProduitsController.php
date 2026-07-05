<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Http\Requests\StoreProduitRequest;
use App\Modules\Catalogue\Http\Requests\UpdateProduitRequest;
use App\Modules\Catalogue\Http\Resources\ProduitResource;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Services\ProduitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProduitsController extends Controller
{
    public function __construct(private ProduitService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $produits = Produit::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search)
                    ->orWhere('barcode', 'like', $search));
            })
            ->when(
                in_array($request->string('type')->toString(), ['product', 'service'], true),
                fn ($q) => $q->where('type', $request->string('type')->toString()),
            )
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return ProduitResource::collection($produits);
    }

    public function store(StoreProduitRequest $request): ProduitResource
    {
        return new ProduitResource($this->service->create($request->validated()));
    }

    public function show(Produit $produit): ProduitResource
    {
        return new ProduitResource($produit);
    }

    public function update(UpdateProduitRequest $request, Produit $produit): ProduitResource
    {
        return new ProduitResource($this->service->update($produit, $request->validated()));
    }

    public function destroy(Produit $produit): JsonResponse
    {
        $produit->delete();

        return response()->json(['message' => 'Produit supprimé.']);
    }
}
