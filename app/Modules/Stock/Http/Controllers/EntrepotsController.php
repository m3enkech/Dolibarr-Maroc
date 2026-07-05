<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Stock\Http\Requests\StoreEntrepotRequest;
use App\Modules\Stock\Http\Requests\UpdateEntrepotRequest;
use App\Modules\Stock\Http\Resources\EntrepotResource;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Stock\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EntrepotsController extends Controller
{
    public function __construct(private StockService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return EntrepotResource::collection(
            Entrepot::query()->orderByDesc('is_default')->orderBy('name')->get(),
        );
    }

    public function store(StoreEntrepotRequest $request): EntrepotResource
    {
        return new EntrepotResource($this->service->creerEntrepot($request->validated()));
    }

    public function update(UpdateEntrepotRequest $request, Entrepot $entrepot): EntrepotResource
    {
        return new EntrepotResource($this->service->modifierEntrepot($entrepot, $request->validated()));
    }

    public function destroy(Entrepot $entrepot): JsonResponse
    {
        $this->service->supprimerEntrepot($entrepot);

        return response()->json(['message' => 'Entrepôt supprimé.']);
    }
}
