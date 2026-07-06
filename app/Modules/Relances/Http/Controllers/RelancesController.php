<?php

namespace App\Modules\Relances\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Relances\Http\Requests\StoreRelanceRequest;
use App\Modules\Relances\Models\Relance;
use App\Modules\Relances\Services\RelanceService;
use App\Modules\Ventes\Models\DocumentVente;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelancesController extends Controller
{
    public function __construct(private RelanceService $service) {}

    /** Factures échues à relancer (worklist), dérivée de la balance âgée. */
    public function aRelancer(Request $request): JsonResponse
    {
        $this->assertFeature($request);

        return response()->json([
            'data' => $this->service->aRelancer($request->date('au')?->format('Y-m-d')),
        ]);
    }

    public function store(StoreRelanceRequest $request): JsonResponse
    {
        $this->assertFeature($request);

        $data = $request->validated();
        $facture = DocumentVente::findOrFail($data['document_vente_id']);

        $relance = $this->service->enregistrer(
            $facture,
            $data['niveau'],
            $data['canal'] ?? 'courrier',
            $data['note'] ?? null,
        );

        return response()->json([
            'data' => [
                'id' => $relance->id,
                'niveau' => $relance->niveau,
                'niveau_label' => Relance::NIVEAUX[$relance->niveau] ?? (string) $relance->niveau,
            ],
        ], 201);
    }

    public function historique(Request $request, DocumentVente $document): JsonResponse
    {
        $this->assertFeature($request);

        return response()->json(['data' => $this->service->historique($document)]);
    }

    /** Lettre de relance PDF pour une facture, au niveau demandé. */
    public function lettre(Request $request, DocumentVente $document)
    {
        $this->assertFeature($request);
        abort_unless($document->type === DocumentVente::TYPE_FACTURE, 404);

        $niveau = (int) $request->integer('niveau', 1);
        $niveau = in_array($niveau, array_keys(Relance::NIVEAUX), true) ? $niveau : 1;

        $document->load(['tiers', 'tenant', 'paiements']);
        $echeance = $document->date_echeance ?? $document->date_document;

        $pdf = Pdf::loadView('pdf.relance', [
            'document' => $document,
            'niveau' => $niveau,
            'niveauLabel' => Relance::NIVEAUX[$niveau],
            'echeance' => $echeance,
            'joursRetard' => $echeance ? $echeance->startOfDay()->diffInDays(now()->startOfDay()) : 0,
            'resteAPayer' => $document->resteAPayer(),
        ]);

        return $pdf->download('relance-'.$document->code.'.pdf');
    }

    /** Le module doit être activé dans les paramètres de l'entreprise. */
    private function assertFeature(Request $request): void
    {
        abort_unless(
            $request->user()->tenant->hasFeature('relances'),
            403,
            'Le module Relances est désactivé dans les paramètres.',
        );
    }
}
