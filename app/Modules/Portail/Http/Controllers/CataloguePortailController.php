<?php

namespace App\Modules\Portail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Portail\Http\ClientDuGrossiste;
use App\Modules\Portail\Services\PortailCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CataloguePortailController extends Controller
{
    // Le compte client de l'acheteur chez CE grossiste : c'est lui qui porte le
    // niveau de tarif appliqué au catalogue.
    use ClientDuGrossiste;

    public function __construct(private PortailCatalogueService $service) {}

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $resultat = $this->service->lister(
            $client,
            $request->string('search')->toString() ?: null,
            max(1, $request->integer('page', 1)),
        );

        return response()->json($resultat);
    }

    public function show(Request $request): JsonResponse
    {
        // Paramètre lu par son NOM, et article chargé ICI : sur ce groupe de
        // routes, SubstituteBindings passe avant SetTenantPortail, donc une
        // signature `show(Produit $produit)` ferait chercher l'article AVANT
        // que l'entreprise courante soit posée — le scope étant fail-closed,
        // la route répondrait 404 en permanence. Chargé à ce stade, le scope
        // tenant s'applique normalement : l'article d'un autre grossiste reste
        // introuvable.
        $client = $this->client($request);
        $produit = Produit::findOrFail((int) $request->route('produit'));

        return response()->json(['data' => $this->service->detail($client, $produit)]);
    }
}
