<?php

namespace App\Modules\Tiers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tiers\Http\Requests\StoreTiersRequest;
use App\Modules\Tiers\Http\Requests\UpdateTiersRequest;
use App\Modules\Tiers\Http\Resources\TiersResource;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TiersController extends Controller
{
    public function __construct(private TiersService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tiers = Tiers::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search)
                    ->orWhere('ice', 'like', $search));
            })
            // Un prospect reste un client potentiel (is_client=true) : le filtre
            // « client » ne montre que les clients déjà convertis.
            ->when($request->string('type')->toString() === 'client',
                fn ($q) => $q->where('is_client', true)->where('is_prospect', false))
            ->when($request->string('type')->toString() === 'prospect', fn ($q) => $q->where('is_prospect', true))
            ->when($request->string('type')->toString() === 'fournisseur', fn ($q) => $q->where('is_supplier', true))
            ->when($request->string('lead_source')->isNotEmpty(),
                fn ($q) => $q->where('lead_source', $request->string('lead_source')->toString()))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return TiersResource::collection($tiers);
    }

    public function store(StoreTiersRequest $request): TiersResource
    {
        return new TiersResource($this->service->create($request->validated()));
    }

    public function show(Tiers $tiers): TiersResource
    {
        return new TiersResource($tiers);
    }

    public function update(UpdateTiersRequest $request, Tiers $tiers): TiersResource
    {
        return new TiersResource($this->service->update($tiers, $request->validated()));
    }

    /** Encours et plafond de crédit d'un client (contrôle avant vente à crédit). */
    public function encours(Tiers $tiers, \App\Modules\Tiers\Services\EncoursService $service): \Illuminate\Http\JsonResponse
    {
        $controle = $service->verifier($tiers, 0);

        return response()->json(['data' => [
            'tiers_id' => $tiers->id,
            'encours' => number_format($controle['encours'], 2, '.', ''),
            'plafond' => $controle['plafond'] !== null ? number_format($controle['plafond'], 2, '.', '') : null,
            'disponible' => $controle['disponible'] !== null ? number_format($controle['disponible'], 2, '.', '') : null,
        ]]);
    }

    /** Convertit un prospect en client (le tiers garde son code et son historique). */
    public function convertir(Tiers $tiers): TiersResource
    {
        return new TiersResource($this->service->convertirEnClient($tiers));
    }

    public function destroy(Tiers $tiers): \Illuminate\Http\JsonResponse
    {
        $tiers->delete();

        return response()->json(['message' => 'Tiers supprimé.']);
    }
}
