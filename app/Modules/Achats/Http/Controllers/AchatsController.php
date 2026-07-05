<?php

namespace App\Modules\Achats\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Http\Requests\StoreDocumentAchatRequest;
use App\Modules\Achats\Http\Requests\StorePaiementFournisseurRequest;
use App\Modules\Achats\Http\Requests\UpdateDocumentAchatRequest;
use App\Modules\Achats\Http\Resources\DocumentAchatResource;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Services\AchatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AchatsController extends Controller
{
    public function __construct(private AchatService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = DocumentAchat::query()
            ->with(['tiers', 'entrepot'])
            ->when(
                in_array($request->string('type')->toString(), DocumentAchat::TYPES, true),
                fn ($q) => $q->where('type', $request->string('type')->toString()),
            )
            ->when($request->string('statut')->isNotEmpty(), fn ($q) => $q->where('statut', $request->string('statut')->toString()))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->where('code', 'like', $search)
                    ->orWhere('ref_fournisseur', 'like', $search)
                    ->orWhereHas('tiers', fn ($t) => $t->where('name', 'like', $search)));
            })
            ->latest('date_document')
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        return DocumentAchatResource::collection($documents);
    }

    public function store(StoreDocumentAchatRequest $request): DocumentAchatResource
    {
        return new DocumentAchatResource($this->service->create($request->validated()));
    }

    public function show(DocumentAchat $document): DocumentAchatResource
    {
        return new DocumentAchatResource($document->load(['lignes', 'tiers', 'entrepot', 'paiements', 'source']));
    }

    public function update(UpdateDocumentAchatRequest $request, DocumentAchat $document): DocumentAchatResource
    {
        return new DocumentAchatResource($this->service->update($document, $request->validated()));
    }

    public function destroy(DocumentAchat $document): JsonResponse
    {
        $this->service->delete($document);

        return response()->json(['message' => 'Document supprimé.']);
    }

    public function valider(DocumentAchat $document): DocumentAchatResource
    {
        return new DocumentAchatResource($this->service->valider($document));
    }

    public function transformer(Request $request, DocumentAchat $document): DocumentAchatResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([DocumentAchat::TYPE_RECEPTION, DocumentAchat::TYPE_FACTURE])],
        ]);

        return new DocumentAchatResource($this->service->transformer($document, $data['type']));
    }

    public function ajouterPaiement(StorePaiementFournisseurRequest $request, DocumentAchat $document): DocumentAchatResource
    {
        $this->service->ajouterPaiement($document, $request->validated());

        return new DocumentAchatResource($document->fresh(['lignes', 'tiers', 'entrepot', 'paiements']));
    }
}
