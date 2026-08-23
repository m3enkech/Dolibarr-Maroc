<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitConditionnement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConditionnementsController extends Controller
{
    /** Conditionnements d'un article. */
    public function index(Produit $produit): JsonResponse
    {
        return response()->json(['data' => $this->rendre($produit)]);
    }

    public function store(Request $request, Produit $produit): JsonResponse
    {
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'quantite_base' => ['required', 'numeric', 'gt:0'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'is_default' => ['boolean'],
        ]);

        if (! empty($data['is_default'])) {
            ProduitConditionnement::where('produit_id', $produit->id)->update(['is_default' => false]);
        }

        $produit->conditionnements()->create($data);

        return response()->json(['data' => $this->rendre($produit)], 201);
    }

    public function destroy(ProduitConditionnement $conditionnement): JsonResponse
    {
        $produit = $conditionnement->produit;
        $conditionnement->delete();

        return response()->json(['data' => $produit !== null ? $this->rendre($produit) : []]);
    }

    /**
     * Résolution d'un code-barres de colis pour la caisse : renvoie l'article
     * et le nombre d'unités qu'ajoute un scan.
     */
    public function parBarcode(Request $request): JsonResponse
    {
        $code = trim($request->string('barcode')->toString());

        $conditionnement = $code !== ''
            ? ProduitConditionnement::where('barcode', $code)->with('produit')->first()
            : null;

        if ($conditionnement === null || $conditionnement->produit === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'conditionnement_id' => $conditionnement->id,
            'produit_id' => $conditionnement->produit_id,
            'nom' => $conditionnement->nom,
            'quantite_base' => (float) $conditionnement->quantite_base,
        ]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function rendre(Produit $produit): array
    {
        return $produit->conditionnements()->get()
            ->map(fn (ProduitConditionnement $c) => [
                'id' => $c->id,
                'nom' => $c->nom,
                'quantite_base' => (float) $c->quantite_base,
                'barcode' => $c->barcode,
                'is_default' => $c->is_default,
            ])
            ->all();
    }
}
