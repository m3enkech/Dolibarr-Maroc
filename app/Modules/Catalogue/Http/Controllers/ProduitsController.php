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
        // `type` accepte une liste (`product,service`) : la composition d'un kit
        // cherche parmi les produits ET les services, jamais les kits. Filtrée
        // côté client, une page de vingt résultats pourrait n'être que des kits
        // écartés — la liste se viderait alors que des articles correspondent.
        // Un type inconnu est ignoré, comme avant.
        $types = array_values(array_intersect(
            explode(',', $request->string('type')->toString()),
            Produit::TYPES,
        ));

        $produits = Produit::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                // whereLike, et non where(..., 'like', ...) : `LIKE` est SENSIBLE
                // à la casse sur PostgreSQL — ce que fait tourner la production —
                // et insensible sur SQLite, où tournent les tests. Chercher
                // « bouteille » ne trouvait donc pas « Bouteille » en ligne, et
                // aucun test ne pouvait l'attraper. whereLike compile en `ilike`
                // sur PostgreSQL et en `like` sur SQLite : une seule écriture,
                // juste sur les deux moteurs.
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->whereLike('name', $search)
                    ->orWhereLike('code', $search)
                    ->orWhereLike('barcode', $search));
            })
            ->when($types !== [], fn ($q) => $q->whereIn('type', $types))
            // Une recherche qui EST une référence ou un code-barres met cet
            // article en tête. Le `like` trouve aussi tout ce qui le contient :
            // « 1001 » ramène « Adaptateur » (code-barres 10015) avant « Câble »
            // (1001), par ordre alphabétique — et le sélecteur, qui prend le
            // premier résultat quand Entrée précède la réponse (douchette,
            // frappe rapide), mettait le mauvais article sur la ligne. La caisse
            // cherche déjà le code-barres exact d'abord. Sans égard à la casse,
            // comme le `like`. Sans recherche (parcours complet de la caisse),
            // l'ordre reste nom puis identifiant.
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $exact = mb_strtolower($request->string('search')->trim()->toString());
                $query->orderByRaw(
                    'CASE WHEN LOWER(code) = ? OR LOWER(barcode) = ? THEN 0 ELSE 1 END',
                    [$exact, $exact],
                );
            })
            // L'identifiant départage les homonymes. Sans lui, PostgreSQL ne
            // garantit aucun ordre entre deux « Ramette A4 » : parcouru page à
            // page (catalogue complet de la caisse), un article pouvait
            // apparaître sur deux pages et un autre sur aucune — introuvable
            // alors à la douchette.
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15));

        return ProduitResource::collection($produits);
    }

    public function store(StoreProduitRequest $request): ProduitResource
    {
        return new ProduitResource($this->service->create($request->validated()));
    }

    public function show(Produit $produit): ProduitResource
    {
        return new ProduitResource($produit->load('composants.composant'));
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
