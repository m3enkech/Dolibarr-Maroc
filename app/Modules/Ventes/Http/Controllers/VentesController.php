<?php

namespace App\Modules\Ventes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ventes\Http\Requests\StoreDocumentVenteRequest;
use App\Modules\Ventes\Http\Requests\StorePaiementRequest;
use App\Modules\Ventes\Http\Requests\UpdateDocumentVenteRequest;
use App\Modules\Ventes\Http\Resources\DocumentVenteResource;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\DocumentPdfService;
use App\Modules\Ventes\Services\EFactureService;
use App\Modules\Ventes\Services\VenteService;
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
            'type' => ['required', Rule::in([DocumentVente::TYPE_COMMANDE, DocumentVente::TYPE_BON_LIVRAISON, DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR])],
        ]);

        return new DocumentVenteResource($this->service->transformer($document, $data['type']));
    }

    /** Livraison partielle d'une commande : le reste demeure en reliquat. */
    public function livrer(Request $request, DocumentVente $document): DocumentVenteResource
    {
        $data = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.source_ligne_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0'],
        ]);

        $bl = $this->service->livrerPartiellement($document, $data['lignes']);

        return new DocumentVenteResource($bl->load(['lignes', 'tiers']));
    }

    public function ajouterPaiement(StorePaiementRequest $request, DocumentVente $document): DocumentVenteResource
    {
        $this->service->ajouterPaiement($document, $request->validated());

        return new DocumentVenteResource($document->fresh(['lignes', 'tiers', 'paiements']));
    }

    public function efacture(DocumentVente $document, EFactureService $efacture)
    {
        abort_unless($document->type === DocumentVente::TYPE_FACTURE, 404);

        if ($document->isBrouillon()) {
            abort(422, 'La facture doit être validée avant de générer la e-facture.');
        }

        return response($efacture->genererXml($document), 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$document->code.'-efacture.xml"',
        ]);
    }

    public function pdf(DocumentVente $document, DocumentPdfService $pdf)
    {
        return $pdf->rendre($document)->download($document->code.'.pdf');
    }
}
