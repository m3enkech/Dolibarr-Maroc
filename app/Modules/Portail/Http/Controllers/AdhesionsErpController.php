<?php

namespace App\Modules\Portail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portail\Models\AcheteurTiers;
use App\Modules\Portail\Services\PortailAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Côté GROSSISTE : gestion des demandes d'accès au portail.
 *
 * Ces routes sont servies à un utilisateur de l'ERP (guard sanctum), avec le
 * tenant courant déjà posé par le middleware `tenant`. Les requêtes sur
 * AcheteurTiers portent un `where('tenant_id', …)` EXPLICITE : cette table est
 * traversante, le scope global ne la protège pas.
 */
class AdhesionsErpController extends Controller
{
    public function __construct(private PortailAuthService $service) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $demandes = AcheteurTiers::where('tenant_id', $tenantId)
            ->with(['acheteur:id,name,email,phone', 'tiers:id,code,name'])
            ->latest('demande_at')
            ->get()
            ->map(fn (AcheteurTiers $r) => [
                'id' => $r->id,
                'acheteur' => [
                    'name' => $r->acheteur?->name,
                    'email' => $r->acheteur?->email,
                    'phone' => $r->acheteur?->phone,
                ],
                'client' => $r->tiers !== null ? ['id' => $r->tiers->id, 'code' => $r->tiers->code, 'name' => $r->tiers->name] : null,
                'statut' => $r->statut,
                'demande_at' => $r->demande_at?->toDateString(),
                'approuve_at' => $r->approuve_at?->toDateString(),
            ]);

        return response()->json(['data' => $demandes]);
    }

    public function approuver(Request $request, int $rattachement): JsonResponse
    {
        $data = $request->validate(['tiers_id' => ['nullable', 'integer']]);

        $r = $this->rattachementDuTenant($request, $rattachement);
        $this->service->approuver($r, $data['tiers_id'] ?? null, $request->user()->id);

        return response()->json(['message' => 'Accès accordé.']);
    }

    public function refuser(Request $request, int $rattachement): JsonResponse
    {
        $this->service->refuser($this->rattachementDuTenant($request, $rattachement));

        return response()->json(['message' => 'Demande refusée.']);
    }

    public function revoquer(Request $request, int $rattachement): JsonResponse
    {
        $this->service->revoquer($this->rattachementDuTenant($request, $rattachement));

        return response()->json(['message' => 'Accès révoqué.']);
    }

    /**
     * Résolution manuelle plutôt que par route model binding : sans le trait
     * BelongsToTenant, un binding automatique irait chercher le rattachement de
     * n'importe quelle entreprise.
     */
    private function rattachementDuTenant(Request $request, int $id): AcheteurTiers
    {
        $r = AcheteurTiers::where('id', $id)
            ->where('tenant_id', $request->user()->tenant_id)
            ->first();

        abort_if($r === null, 404);

        return $r;
    }
}
