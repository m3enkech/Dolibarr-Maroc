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
use Illuminate\Support\Facades\DB;
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
            ->when($request->integer('tiers_id') > 0, fn ($q) => $q->where('tiers_id', $request->integer('tiers_id')))

            // La période. Sans elle, retrouver une facture de 2022 dans mille
            // trois cents pièces demandait quatre-vingts clics sur « Suivant ».
            ->when($request->integer('annee') > 0, fn ($q) => $q->whereYear('date_document', $request->integer('annee')))
            ->when($request->date('date_debut'), fn ($q, $d) => $q->whereDate('date_document', '>=', $d))
            ->when($request->date('date_fin'), fn ($q, $d) => $q->whereDate('date_document', '<=', $d))

            ->when($request->filled('montant_min'), fn ($q) => $q->where('total_ttc', '>=', (float) $request->input('montant_min')))
            ->when($request->filled('montant_max'), fn ($q) => $q->where('total_ttc', '<=', (float) $request->input('montant_max')))

            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $terme = $request->string('search')->toString();
                $search = '%'.$terme.'%';

                // On cherche aussi sur ce que les gens ont VRAIMENT sous les
                // yeux quand ils cherchent : le bon de commande cité par le
                // client, son ICE, et la désignation d'un article de la pièce
                // (« toner »). Le code seul ne suffit pas — encore faut-il le
                // connaître.
                $query->where(fn ($q) => $q
                    ->whereLike('code', $search)
                    ->orWhereLike('reference_client', $search)
                    ->orWhereHas('tiers', fn ($t) => $t->whereLike('name', $search)->orWhereLike('ice', $search))
                    ->orWhereHas('lignes', fn ($l) => $l->whereLike('designation', $search)));
            })

            ->tap(fn ($q) => $this->trier($q, $request))
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return DocumentVenteResource::collection($documents);
    }

    /**
     * Les années qui portent des documents, avec leur compte.
     *
     * Une liste de mille trois cents pièces n'est navigable que si l'on peut
     * viser une année d'un clic. Proposer les douze dernières années en dur
     * afficherait surtout des zéros : on rend celles qui existent vraiment.
     */
    public function annees(Request $request): JsonResponse
    {
        // SQLite n'a pas EXTRACT, PostgreSQL n'a pas strftime : l'expression
        // dépend du moteur, et se tromper ne se verrait qu'en production.
        $anneeSql = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', date_document) AS INTEGER)"
            : 'EXTRACT(YEAR FROM date_document)::int';

        $annees = DocumentVente::query()
            ->when(
                in_array($request->string('type')->toString(), DocumentVente::TYPES, true),
                fn ($q) => $q->where('type', $request->string('type')->toString()),
            )
            ->selectRaw("{$anneeSql} AS annee, COUNT(*) AS total")
            ->groupBy(DB::raw($anneeSql))
            ->orderByDesc('annee')
            ->get()
            ->map(fn ($ligne) => ['annee' => (int) $ligne->annee, 'total' => (int) $ligne->total])
            ->values();

        return response()->json(['data' => $annees]);
    }

    /**
     * Le tri demandé, borné à une liste blanche.
     *
     * Laisser passer un nom de colonne libre, c'est ouvrir l'ordre de tri à
     * l'injection ; et trier sur une colonne non indexée ferait ramer la liste
     * sans que personne comprenne pourquoi.
     */
    private function trier($query, Request $request): void
    {
        $colonnes = ['date_document', 'code', 'total_ttc', 'statut', 'date_echeance'];
        $tri = $request->string('tri')->toString();
        $sens = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        if (! in_array($tri, $colonnes, true)) {
            $query->latest('date_document')->latest('id');

            return;
        }

        $query->orderBy($tri, $sens)->orderBy('id', $sens);
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
