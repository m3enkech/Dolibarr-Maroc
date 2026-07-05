<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\CategoriesImmobilisation;
use App\Modules\Compta\Http\Requests\CederImmobilisationRequest;
use App\Modules\Compta\Http\Requests\StoreImmobilisationRequest;
use App\Modules\Compta\Http\Resources\ImmobilisationResource;
use App\Modules\Compta\Models\Immobilisation;
use App\Modules\Compta\Services\ImmobilisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ImmobilisationsController extends Controller
{
    public function __construct(private ImmobilisationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $immos = Immobilisation::query()
            ->with(['compteImmo', 'compteAmort'])
            ->when($request->string('statut')->isNotEmpty(), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('label', 'like', $search)->orWhere('code', 'like', $search));
            })
            ->orderByDesc('date_acquisition')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return ImmobilisationResource::collection($immos);
    }

    public function store(StoreImmobilisationRequest $request): ImmobilisationResource
    {
        $immo = $this->service->create($request->validated());

        return new ImmobilisationResource($immo->load(['compteImmo', 'compteAmort']));
    }

    public function show(Immobilisation $immobilisation): JsonResponse
    {
        $immobilisation->load(['compteImmo', 'compteAmort']);

        return response()->json([
            'data' => (new ImmobilisationResource($immobilisation))->resolve(request()),
            'plan' => $this->service->plan($immobilisation),
            'dotations_comptabilisees' => $immobilisation->dotations()
                ->orderBy('annee')
                ->get(['annee', 'montant'])
                ->map(fn ($d) => ['annee' => $d->annee, 'montant' => $d->montant]),
        ]);
    }

    public function destroy(Immobilisation $immobilisation): JsonResponse
    {
        if ($immobilisation->dotations()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer une immobilisation déjà amortie.',
            ], 422);
        }

        $immobilisation->delete();

        return response()->json(['message' => 'Immobilisation supprimée.']);
    }

    public function ceder(CederImmobilisationRequest $request, Immobilisation $immobilisation): ImmobilisationResource
    {
        return new ImmobilisationResource($this->service->ceder($immobilisation, $request->validated()));
    }

    public function genererDotations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'annee' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        return response()->json($this->service->genererDotations((int) $data['annee']));
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => CategoriesImmobilisation::forFrontend()]);
    }
}
