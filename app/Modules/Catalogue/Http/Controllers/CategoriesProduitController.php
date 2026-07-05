<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Http\Requests\StoreCategorieProduitRequest;
use App\Modules\Catalogue\Models\CategorieProduit;
use App\Modules\Compta\Services\ComptaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoriesProduitController extends Controller
{
    public function __construct(private ComptaService $compta) {}

    public function index(): AnonymousResourceCollection
    {
        // S'assure que le plan comptable est disponible pour le sélecteur.
        $this->compta->initialiserPlanComptable();

        $categories = CategorieProduit::query()
            ->with(['compteVente', 'compteAchat', 'compteAmortissement'])
            ->withCount('produits')
            ->orderBy('name')
            ->get();

        return \App\Modules\Catalogue\Http\Resources\CategorieProduitResource::collection($categories);
    }

    public function store(StoreCategorieProduitRequest $request): JsonResponse
    {
        $categorie = CategorieProduit::create($this->normalise($request->validated()));

        return response()->json([
            'data' => new \App\Modules\Catalogue\Http\Resources\CategorieProduitResource(
                $categorie->load(['compteVente', 'compteAchat', 'compteAmortissement']),
            ),
        ], 201);
    }

    public function update(StoreCategorieProduitRequest $request, CategorieProduit $categorie): JsonResponse
    {
        $categorie->update($this->normalise($request->validated()));

        return response()->json([
            'data' => new \App\Modules\Catalogue\Http\Resources\CategorieProduitResource(
                $categorie->fresh(['compteVente', 'compteAchat', 'compteAmortissement']),
            ),
        ]);
    }

    public function destroy(CategorieProduit $categorie): JsonResponse
    {
        if ($categorie->produits()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une catégorie utilisée par des produits.',
            ], 422);
        }

        $categorie->delete();

        return response()->json(['message' => 'Catégorie supprimée.']);
    }

    /** Une catégorie non-immobilisation n'a ni compte d'amortissement ni durée. */
    private function normalise(array $data): array
    {
        if (empty($data['is_immobilisation'])) {
            $data['compte_amortissement_id'] = null;
            $data['duree_amortissement'] = null;
        }

        return $data;
    }
}
