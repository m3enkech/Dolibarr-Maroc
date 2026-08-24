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

    public function show(Request $request, Produit $produit): JsonResponse
    {
        // Le route model binding est déjà filtré par le scope tenant (Produit
        // porte BelongsToTenant) : un article d'un autre grossiste renvoie 404.
        $client = $this->client($request);

        return response()->json(['data' => $this->service->detail($client, $produit)]);
    }
}
