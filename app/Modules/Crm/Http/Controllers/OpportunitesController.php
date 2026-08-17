<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Http\Requests\StoreOpportuniteRequest;
use App\Modules\Crm\Http\Requests\UpdateOpportuniteRequest;
use App\Modules\Crm\Http\Resources\OpportuniteResource;
use App\Modules\Crm\Models\Opportunite;
use App\Modules\Crm\Services\OpportuniteService;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OpportunitesController extends Controller
{
    public function __construct(private OpportuniteService $service) {}

    /** Tableau de pipeline : opportunités ouvertes par étape + statistiques. */
    public function index(Request $request): JsonResponse
    {
        $this->assertFeature($request);

        $ouvertes = Opportunite::query()
            ->with('tiers')
            ->where('statut', Opportunite::STATUT_OUVERTE)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $colonnes = collect(Opportunite::ETAPES)->mapWithKeys(fn ($etape) => [
            $etape => OpportuniteResource::collection($ouvertes->where('etape', $etape)->values()),
        ]);

        $forecast = $ouvertes->sum(fn ($o) => (float) $o->montant_estime * $o->probabilite / 100);

        return response()->json([
            'etapes' => Opportunite::ETAPES,
            'colonnes' => $colonnes,
            'stats' => [
                'ouvertes' => $ouvertes->count(),
                'total_pipeline' => number_format((float) $ouvertes->sum('montant_estime'), 2, '.', ''),
                'forecast_pondere' => number_format($forecast, 2, '.', ''),
                'gagnees_montant' => number_format(
                    (float) Opportunite::where('statut', Opportunite::STATUT_GAGNEE)->sum('montant_estime'),
                    2, '.', '',
                ),
            ],
        ]);
    }

    /** Historique des opportunités clôturées (gagnées / perdues). */
    public function closes(Request $request): JsonResponse
    {
        $this->assertFeature($request);

        $statut = $request->string('statut')->toString();
        $opportunites = Opportunite::query()
            ->with('tiers')
            ->whereIn('statut', [Opportunite::STATUT_GAGNEE, Opportunite::STATUT_PERDUE])
            ->when(in_array($statut, [Opportunite::STATUT_GAGNEE, Opportunite::STATUT_PERDUE], true),
                fn ($q) => $q->where('statut', $statut))
            ->latest('close_at')
            ->paginate($request->integer('per_page', 30));

        return response()->json(OpportuniteResource::collection($opportunites)->response()->getData(true));
    }

    /**
     * Fiche complète d'une opportunité : le tiers, ses activités, les documents
     * de vente générés depuis elle, et les dates clés.
     */
    public function show(Request $request, Opportunite $opportunite): JsonResponse
    {
        $this->assertFeature($request);

        $opportunite->load(['tiers', 'user']);

        $activites = \App\Modules\Crm\Models\Activite::query()
            ->where('opportunite_id', $opportunite->id)
            ->orderByRaw('COALESCE(date_prevue, created_at) desc')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'sujet' => $a->sujet,
                'note' => $a->note,
                'date_prevue' => $a->date_prevue?->toDateString(),
                'fait' => (bool) $a->fait,
            ]);

        $documents = \App\Modules\Ventes\Models\DocumentVente::query()
            ->where('opportunite_id', $opportunite->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'code' => $d->code,
                'type' => $d->type,
                'statut' => $d->statut,
                'date_document' => $d->date_document?->toDateString(),
                'total_ttc' => number_format((float) $d->total_ttc, 2, '.', ''),
            ]);

        return response()->json([
            'data' => [
                'opportunite' => new OpportuniteResource($opportunite),
                'vendeur' => $opportunite->user?->name,
                'activites' => $activites,
                'documents' => $documents,
                'dates' => [
                    'creee_le' => $opportunite->created_at?->toDateString(),
                    'close_le' => $opportunite->close_at?->toDateString(),
                    'cloture_prevue' => $opportunite->date_cloture_prevue?->toDateString(),
                    'jours_ouverts' => $opportunite->created_at
                        ? (int) $opportunite->created_at->diffInDays($opportunite->close_at ?? now())
                        : null,
                ],
            ],
        ]);
    }

    public function store(StoreOpportuniteRequest $request): JsonResponse
    {
        $this->assertFeature($request);

        $opportunite = $this->service->creer($request->validated());

        return response()->json(['data' => new OpportuniteResource($opportunite->load('tiers'))], 201);
    }

    public function update(UpdateOpportuniteRequest $request, Opportunite $opportunite): JsonResponse
    {
        $this->assertFeature($request);

        return $this->render($this->service->modifier($opportunite, $request->validated()));
    }

    public function deplacer(Request $request, Opportunite $opportunite): JsonResponse
    {
        $this->assertFeature($request);
        $data = $request->validate(['etape' => ['required', Rule::in(Opportunite::ETAPES)]]);

        return $this->render($this->service->deplacer($opportunite, $data['etape']));
    }

    public function cloturer(Request $request, Opportunite $opportunite): JsonResponse
    {
        $this->assertFeature($request);
        $data = $request->validate([
            'statut' => ['required', Rule::in([Opportunite::STATUT_GAGNEE, Opportunite::STATUT_PERDUE])],
        ]);

        return $this->render($this->service->cloturer($opportunite, $data['statut']));
    }

    public function rouvrir(Request $request, Opportunite $opportunite): JsonResponse
    {
        $this->assertFeature($request);

        return $this->render($this->service->rouvrir($opportunite));
    }

    /**
     * Génère un devis brouillon depuis l'opportunité (client + montant estimé),
     * et fait avancer l'opportunité à l'étape « proposition ».
     */
    public function genererDevis(Request $request, Opportunite $opportunite, VenteService $ventes): JsonResponse
    {
        $this->assertFeature($request);

        $devis = $ventes->create([
            'type' => \App\Modules\Ventes\Models\DocumentVente::TYPE_DEVIS,
            'tiers_id' => $opportunite->tiers_id,
            'notes' => "Depuis l'opportunité {$opportunite->code} — {$opportunite->titre}",
            'lignes' => [[
                'designation' => $opportunite->titre,
                'quantite' => 1,
                'prix_unitaire' => (float) $opportunite->montant_estime,
                'tva_rate' => 20,
            ]],
        ]);

        // Lien structuré (en plus de la note) : alimente la fiche de
        // l'opportunité et l'entonnoir des statistiques.
        $devis->update(['opportunite_id' => $opportunite->id]);

        if ($opportunite->isOuverte() && $opportunite->etape === Opportunite::ETAPES[0]) {
            $this->service->deplacer($opportunite, 'proposition');
        }

        return response()->json(['devis_id' => $devis->id, 'devis_code' => $devis->code], 201);
    }

    public function destroy(Request $request, Opportunite $opportunite): JsonResponse
    {
        $this->assertFeature($request);
        $this->service->supprimer($opportunite);

        return response()->json(['message' => 'Opportunité supprimée.']);
    }

    private function render(Opportunite $opportunite): JsonResponse
    {
        return response()->json(['data' => new OpportuniteResource($opportunite->load('tiers'))]);
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
