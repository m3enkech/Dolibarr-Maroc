<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Http\Requests\FermerSessionRequest;
use App\Modules\Pos\Http\Requests\OuvrirSessionRequest;
use App\Modules\Pos\Http\Requests\StoreVentePosRequest;
use App\Modules\Pos\Http\Resources\PosSessionResource;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Pos\Services\PosService;
use App\Modules\Ventes\Http\Resources\DocumentVenteResource;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function __construct(private PosService $service) {}

    /** Session ouverte du vendeur courant, avec son rapport X (null sinon). */
    public function sessionCourante(): JsonResponse
    {
        $session = $this->service->sessionOuverte();

        return response()->json([
            'data' => $session ? new PosSessionResource($session->load('user')) : null,
            'rapport' => $session ? $this->service->rapport($session) : null,
        ]);
    }

    public function ouvrir(OuvrirSessionRequest $request): JsonResponse
    {
        $session = $this->service->ouvrirSession(
            (float) $request->validated()['fond_caisse'],
            $request->validated()['note'] ?? null,
        );

        return response()->json([
            'data' => new PosSessionResource($session->load('user')),
            'rapport' => $this->service->rapport($session),
        ], 201);
    }

    public function fermer(FermerSessionRequest $request): JsonResponse
    {
        $session = $this->service->sessionOuverte();

        if ($session === null) {
            throw ValidationException::withMessages([
                'session' => 'Aucune session de caisse ouverte.',
            ]);
        }

        // Le rapport Z fige les totaux avant la fermeture.
        $rapport = $this->service->rapport($session);

        $session = $this->service->fermerSession(
            $session,
            (float) $request->validated()['montant_compte'],
            $request->validated()['note'] ?? null,
        );

        return response()->json([
            'data' => new PosSessionResource($session->load('user')),
            'rapport' => $rapport,
        ]);
    }

    /** Historique des sessions (les plus récentes d'abord). */
    public function sessions(Request $request): AnonymousResourceCollection
    {
        return PosSessionResource::collection(
            PosSession::query()
                ->with('user')
                ->latest('id')
                ->paginate($request->integer('per_page', 20)),
        );
    }

    /** Rapport X/Z d'une session (en cours ou fermée). */
    public function rapport(PosSession $session): JsonResponse
    {
        return response()->json([
            'data' => new PosSessionResource($session->load('user')),
            'rapport' => $this->service->rapport($session),
        ]);
    }

    /** Vente comptoir : facture créée, validée et payée en un appel. */
    public function vendre(StoreVentePosRequest $request): JsonResponse
    {
        $session = $this->service->sessionOuverte();

        if ($session === null) {
            throw ValidationException::withMessages([
                'session' => 'Ouvrez une session de caisse avant d\'encaisser.',
            ]);
        }

        $data = $request->validated();

        $document = $this->service->vendre(
            $session,
            $data['lignes'],
            $data['paiements'],
            $data['tiers_id'] ?? null,
        );

        // Rendu de monnaie : espèces remises − part payée en espèces.
        $rendu = null;
        if (isset($data['montant_donne'])) {
            $especes = (float) $document->paiements->where('mode', 'especes')->sum('montant');
            $rendu = number_format(max(0, round((float) $data['montant_donne'] - $especes, 2)), 2, '.', '');
        }

        return response()->json([
            'data' => new DocumentVenteResource($document),
            'rendu' => $rendu,
            'rapport' => $this->service->rapport($session),
        ], 201);
    }

    /** Tickets de la session courante (pour le bandeau « dernières ventes »). */
    public function tickets(Request $request): AnonymousResourceCollection
    {
        $session = $this->service->sessionOuverte();

        $tickets = DocumentVente::query()
            ->when(
                $session !== null,
                fn ($q) => $q->where('pos_session_id', $session->id),
                fn ($q) => $q->whereRaw('1 = 0'),
            )
            ->with(['tiers', 'paiements'])
            ->latest('id')
            ->paginate($request->integer('per_page', 10));

        return DocumentVenteResource::collection($tickets);
    }
}
