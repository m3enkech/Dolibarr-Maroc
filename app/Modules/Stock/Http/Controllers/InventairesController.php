<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Stock\Http\Requests\StoreInventaireRequest;
use App\Modules\Stock\Http\Requests\UpdateInventaireRequest;
use App\Modules\Stock\Http\Resources\InventaireResource;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Stock\Models\Inventaire;
use App\Modules\Stock\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventairesController extends Controller
{
    public function __construct(private StockService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $inventaires = Inventaire::query()
            ->with('entrepot')
            ->when($request->integer('entrepot_id'), fn ($q, $id) => $q->where('entrepot_id', $id))
            ->when($request->string('statut')->isNotEmpty(), fn ($q) => $q->where('statut', $request->string('statut')))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return InventaireResource::collection($inventaires);
    }

    public function store(StoreInventaireRequest $request): InventaireResource
    {
        $entrepot = Entrepot::findOrFail($request->validated()['entrepot_id']);
        $inventaire = $this->service->demarrerInventaire($entrepot, $request->validated()['note'] ?? null);

        return new InventaireResource($inventaire->load(['entrepot', 'lignes.produit']));
    }

    public function show(Inventaire $inventaire): InventaireResource
    {
        return new InventaireResource($inventaire->load(['entrepot', 'lignes.produit']));
    }

    public function update(UpdateInventaireRequest $request, Inventaire $inventaire): InventaireResource
    {
        $inventaire = $this->service->enregistrerComptages($inventaire, $request->validated()['comptages']);

        return new InventaireResource($inventaire->load(['entrepot', 'lignes.produit']));
    }

    public function valider(Inventaire $inventaire): InventaireResource
    {
        $inventaire = $this->service->validerInventaire($inventaire);

        return new InventaireResource($inventaire->load(['entrepot', 'lignes.produit']));
    }

    public function destroy(Inventaire $inventaire): JsonResponse
    {
        $this->service->supprimerInventaire($inventaire);

        return response()->json(['message' => 'Inventaire supprimé.']);
    }
}
