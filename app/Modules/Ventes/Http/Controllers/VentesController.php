<?php

namespace App\Modules\Ventes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ventes\Http\Requests\StoreDocumentVenteRequest;
use App\Modules\Ventes\Http\Requests\StorePaiementRequest;
use App\Modules\Ventes\Http\Requests\UpdateDocumentVenteRequest;
use App\Modules\Ventes\Http\Resources\DocumentVenteResource;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VentesController extends Controller
{
    public function __construct(private VenteService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = DocumentVente::query()
            ->with('tiers')
            ->when(
                in_array($request->string('type')->toString(), DocumentVente::TYPES, true),
                fn ($q) => $q->where('type', $request->string('type')->toString()),
            )
            ->when($request->string('statut')->isNotEmpty(), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->where('code', 'like', $search)
                    ->orWhereHas('tiers', fn ($t) => $t->where('name', 'like', $search)));
            })
            ->latest('date_document')
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return DocumentVenteResource::collection($documents);
    }

    public function store(StoreDocumentVenteRequest $request): DocumentVenteResource
    {
        return new DocumentVenteResource($this->service->create($request->validated()));
    }

    public function show(DocumentVente $document): DocumentVenteResource
    {
        return new DocumentVenteResource($document->load(['lignes', 'tiers', 'paiements', 'source']));
    }

    public function update(UpdateDocumentVenteRequest $request, DocumentVente $document): DocumentVenteResource
    {
        return new DocumentVenteResource($this->service->update($document, $request->validated()));
    }

    public function destroy(DocumentVente $document): JsonResponse
    {
        $this->service->delete($document);

        return response()->json(['message' => 'Document supprimé.']);
    }

    public function valider(DocumentVente $document): DocumentVenteResource
    {
        return new DocumentVenteResource($this->service->valider($document));
    }

    public function changerStatut(Request $request, DocumentVente $document): DocumentVenteResource
    {
        $data = $request->validate([
            'statut' => ['required', Rule::in([DocumentVente::STATUT_ACCEPTE, DocumentVente::STATUT_REFUSE])],
        ]);

        return new DocumentVenteResource($this->service->changerStatutDevis($document, $data['statut']));
    }

    public function transformer(Request $request, DocumentVente $document): DocumentVenteResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([DocumentVente::TYPE_COMMANDE, DocumentVente::TYPE_FACTURE])],
        ]);

        return new DocumentVenteResource($this->service->transformer($document, $data['type']));
    }

    public function ajouterPaiement(StorePaiementRequest $request, DocumentVente $document): DocumentVenteResource
    {
        $this->service->ajouterPaiement($document, $request->validated());

        return new DocumentVenteResource($document->fresh(['lignes', 'tiers', 'paiements']));
    }

    public function pdf(DocumentVente $document)
    {
        $document->load(['lignes', 'tiers', 'tenant', 'paiements']);

        // Ventilation de la TVA par taux (exigence des factures marocaines).
        $tvaBreakdown = $document->lignes
            ->groupBy(fn ($ligne) => (string) $ligne->tva_rate)
            ->map(fn ($lignes, $rate) => [
                'rate' => (float) $rate,
                'ht' => $lignes->sum(fn ($l) => (float) $l->montant_ht),
                'tva' => $lignes->sum(fn ($l) => (float) $l->montant_tva),
            ])
            ->sortByDesc('rate')
            ->values();

        $pdf = Pdf::loadView('pdf.document-vente', [
            'document' => $document,
            'tvaBreakdown' => $tvaBreakdown,
        ]);

        return $pdf->download($document->code.'.pdf');
    }
}
