<?php

namespace App\Modules\Achats\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Http\Requests\StoreCommandeReapproRequest;
use App\Modules\Achats\Http\Requests\StoreDocumentAchatRequest;
use App\Modules\Achats\Http\Requests\StorePaiementFournisseurRequest;
use App\Modules\Achats\Http\Requests\UpdateDocumentAchatRequest;
use App\Modules\Achats\Http\Resources\DocumentAchatResource;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Services\AchatService;
use App\Modules\Achats\Services\CommandeReapproService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class AchatsController extends Controller
{
    /** Durée pendant laquelle un renvoi de la même clé rejoue la réponse. */
    private const MEMOIRE_IDEMPOTENCE_HEURES = 6;

    public function __construct(private AchatService $service) {}

    /**
     * Passer une commande fournisseur depuis l'écran de suivi.
     *
     * DEUX BARRIÈRES CONTRE LE DOUBLE ENVOI, et il en faut deux.
     *
     * Le verrou empêche deux requêtes SIMULTANÉES de se croiser — un double clic
     * part plus vite que la première réponse n'arrive. La mémoire du résultat
     * couvre le cas suivant : la requête est repartie après coup (réseau
     * capricieux, bouton retour, onglet rechargé), et on rend alors la MÊME
     * réponse plutôt que de créer une seconde fois.
     *
     * Les deux vivent dans le cache, dont le magasin est la base de données :
     * elles valent donc entre toutes les instances du service, ce qu'un cache
     * fichier ou mémoire ne garantirait pas.
     */
    public function commanderReappro(StoreCommandeReapproRequest $request, CommandeReapproService $reappro): JsonResponse
    {
        $data = $request->validated();
        $cle = "cf-reappro:{$request->user()->tenant_id}:{$data['cle_idempotence']}";
        $verrou = Cache::lock("verrou:{$cle}", 15);

        abort_if(! $verrou->get(), 409, __('Cette commande est déjà en cours de création.'));

        try {
            if ($rejeu = Cache::get($cle)) {
                return response()->json(['data' => $rejeu]);
            }

            $resultat = $reappro->creer(
                $data['produit_ids'],
                $data['entrepot_id'] ?? null,
                $data['fournisseur_id'] ?? null,
                $data['valider'] ?? true,
            );

            Cache::put($cle, $resultat, now()->addHours(self::MEMOIRE_IDEMPOTENCE_HEURES));

            // 200 et non 201 quand rien n'a été créé : rien n'était invalide,
            // il n'y avait simplement rien à commander.
            return response()->json(['data' => $resultat], $resultat['commandes'] === [] ? 200 : 201);
        } finally {
            $verrou->release();
        }
    }

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
                    ->whereLike('code', $search)
                    ->orWhereLike('ref_fournisseur', $search)
                    ->orWhereHas('tiers', fn ($t) => $t->whereLike('name', $search)));
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

    /** Bon de commande / réception / facture fournisseur au format PDF. */
    public function pdf(DocumentAchat $document)
    {
        $document->load(['lignes', 'tiers', 'tenant', 'entrepot', 'paiements']);

        // Ventilation de la TVA par taux (comme pour les documents de vente).
        $tvaBreakdown = $document->lignes
            ->groupBy(fn ($ligne) => (string) $ligne->tva_rate)
            ->map(fn ($lignes, $rate) => [
                'rate' => (float) $rate,
                'ht' => $lignes->sum(fn ($l) => (float) $l->montant_ht),
                'tva' => $lignes->sum(fn ($l) => (float) $l->montant_tva),
            ])
            ->sortByDesc('rate')
            ->values();

        $pdf = Pdf::loadView('pdf.document-achat', [
            'document' => $document,
            'tvaBreakdown' => $tvaBreakdown,
        ]);

        return $pdf->download($document->code.'.pdf');
    }
}
