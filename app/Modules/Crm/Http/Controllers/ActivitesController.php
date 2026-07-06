<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Http\Requests\StoreActiviteRequest;
use App\Modules\Crm\Http\Resources\ActiviteResource;
use App\Modules\Crm\Models\Activite;
use App\Modules\Crm\Services\ActiviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivitesController extends Controller
{
    public function __construct(private ActiviteService $service) {}

    /**
     * Activités, filtrables. a_faire=1 → tâches en attente triées par échéance
     * (le « à faire ») ; sinon fil chronologique (timeline), le plus récent d'abord.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertFeature($request);

        $aFaire = $request->boolean('a_faire');

        $activites = Activite::query()
            ->with(['tiers', 'opportunite', 'user'])
            ->when($request->integer('tiers_id'), fn ($q, $id) => $q->where('tiers_id', $id))
            ->when($request->integer('opportunite_id'), fn ($q, $id) => $q->where('opportunite_id', $id))
            ->when($request->string('type')->isNotEmpty(), fn ($q) => $q->where('type', $request->string('type')))
            ->when($aFaire, fn ($q) => $q->where('fait', false)->orderByRaw('date_prevue is null')->orderBy('date_prevue'))
            ->when(! $aFaire, fn ($q) => $q->latest('id'))
            ->paginate($request->integer('per_page', 30));

        return ActiviteResource::collection($activites);
    }

    public function store(StoreActiviteRequest $request): JsonResponse
    {
        $this->assertFeature($request);

        $activite = $this->service->creer($request->validated());

        return response()->json(
            ['data' => new ActiviteResource($activite->load(['tiers', 'opportunite', 'user']))],
            201,
        );
    }

    public function update(StoreActiviteRequest $request, Activite $activite): JsonResponse
    {
        $this->assertFeature($request);

        return $this->render($this->service->modifier($activite, $request->validated()));
    }

    public function fait(Request $request, Activite $activite): JsonResponse
    {
        $this->assertFeature($request);

        return $this->render($this->service->basculerFait($activite));
    }

    public function destroy(Request $request, Activite $activite): JsonResponse
    {
        $this->assertFeature($request);
        $this->service->supprimer($activite);

        return response()->json(['message' => 'Activité supprimée.']);
    }

    private function render(Activite $activite): JsonResponse
    {
        return response()->json(['data' => new ActiviteResource($activite->load(['tiers', 'opportunite', 'user']))]);
    }

    private function assertFeature(Request $request): void
    {
        abort_unless(
            $request->user()->tenant->hasFeature('crm'),
            403,
            'Le module CRM est désactivé dans les paramètres.',
        );
    }
}
