<?php

namespace App\Modules\Effets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Effets\Http\Requests\StoreEffetRequest;
use App\Modules\Effets\Http\Resources\EffetResource;
use App\Modules\Effets\Models\Effet;
use App\Modules\Effets\Services\EffetService;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EffetsController extends Controller
{
    public function __construct(private EffetService $service) {}

    /** Portefeuille d'effets, filtrable par type et statut. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->assertFeature($request);

        $effets = Effet::query()
            ->with(['tiers', 'documentVente', 'documentAchat'])
            ->when($request->string('type')->isNotEmpty(), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->string('statut')->isNotEmpty(), fn ($q) => $q->where('statut', $request->string('statut')))
            ->orderBy('date_echeance')
            ->paginate($request->integer('per_page', 30));

        return EffetResource::collection($effets);
    }

    public function store(StoreEffetRequest $request): JsonResponse
    {
        $this->assertFeature($request);

        $data = $request->validated();

        $effet = $data['type'] === Effet::TYPE_RECEVOIR
            ? $this->service->creerARecevoir(DocumentVente::findOrFail($data['facture_id']), $data['date_echeance'])
            : $this->service->creerAPayer(DocumentAchat::findOrFail($data['facture_id']), $data['date_echeance']);

        return response()->json(
            ['data' => new EffetResource($effet->load(['tiers', 'documentVente', 'documentAchat']))],
            201,
        );
    }

    public function encaisser(Request $request, Effet $effet): JsonResponse
    {
        $this->assertFeature($request);

        return $this->render($this->service->encaisser($effet));
    }

    public function payer(Request $request, Effet $effet): JsonResponse
    {
        $this->assertFeature($request);

        return $this->render($this->service->payer($effet));
    }

    public function impaye(Request $request, Effet $effet): JsonResponse
    {
        $this->assertFeature($request);

        return $this->render($this->service->marquerImpaye($effet));
    }

    private function render(Effet $effet): JsonResponse
    {
        return response()->json(['data' => new EffetResource($effet->load(['tiers', 'documentVente', 'documentAchat']))]);
    }

    private function assertFeature(Request $request): void
    {
        abort_unless(
            $request->user()->tenant->hasFeature('effets'),
            403,
            'Le module Effets & traites est désactivé dans les paramètres.',
        );
    }
}
