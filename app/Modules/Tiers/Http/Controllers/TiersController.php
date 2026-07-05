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
            ->when($request->string('type')->toString() === 'client', fn ($q) => $q->where('is_client', true))
            ->when($request->string('type')->toString() === 'fournisseur', fn ($q) => $q->where('is_supplier', true))
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

    public function destroy(Tiers $tiers): \Illuminate\Http\JsonResponse
    {
        $tiers->delete();

        return response()->json(['message' => 'Tiers supprimé.']);
    }
}
