<?php

namespace App\Modules\Portail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Portail\Models\AcheteurTiers;
use App\Modules\Portail\Services\PortailCatalogueService;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CataloguePortailController extends Controller
{
    public function __construct(private PortailCatalogueService $service) {}

    public function index(Request $request): JsonResponse
    {
        $client = $this->clientDuPortail($request);

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
        $client = $this->clientDuPortail($request);

        return response()->json(['data' => $this->service->detail($client, $produit)]);
    }

    /**
     * Le compte client de l'acheteur chez CE grossiste — c'est lui qui porte le
     * niveau de tarif. Le rattachement a déjà été vérifié par SetTenantPortail.
     */
    private function clientDuPortail(Request $request): Tiers
    {
        /** @var AcheteurTiers $rattachement */
        $rattachement = $request->attributes->get('portail_rattachement');

        $client = Tiers::find($rattachement->tiers_id);

        abort_if($client === null, 403, 'Votre compte client n\'est plus disponible chez ce grossiste.');

        return $client;
    }
}
