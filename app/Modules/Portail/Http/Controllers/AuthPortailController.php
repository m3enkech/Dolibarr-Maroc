<?php

namespace App\Modules\Portail\Http\Controllers;

use App\Core\Tenancy\Tenant;
use App\Http\Controllers\Controller;
use App\Modules\Portail\Models\Acheteur;
use App\Modules\Portail\Models\AcheteurTiers;
use App\Modules\Portail\Services\PortailAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthPortailController extends Controller
{
    public function __construct(private PortailAuthService $service) {}

    public function inscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('acheteurs', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $this->service->inscrire($data);
        $session = $this->service->connecter($data['email'], $data['password']);

        return response()->json([
            'token' => $session['token'],
            'acheteur' => $this->acheteurPayload($session['acheteur']),
        ], 201);
    }

    public function connexion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $session = $this->service->connecter($data['email'], $data['password']);

        return response()->json([
            'token' => $session['token'],
            'acheteur' => $this->acheteurPayload($session['acheteur']),
        ]);
    }

    public function moi(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->acheteurPayload($request->user())]);
    }

    public function deconnexion(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    /** Grossistes chez qui l'acheteur est rattaché, avec l'état de sa demande. */
    public function mesGrossistes(Request $request): JsonResponse
    {
        /** @var Acheteur $acheteur */
        $acheteur = $request->user();

        $rattachements = $acheteur->rattachements()->with('tenant:id,name,slug')->get()
            ->map(fn (AcheteurTiers $r) => [
                'grossiste' => $r->tenant?->name,
                'slug' => $r->tenant?->slug,
                'statut' => $r->statut,
                'demande_at' => $r->demande_at?->toDateString(),
                'approuve_at' => $r->approuve_at?->toDateString(),
            ]);

        return response()->json(['data' => $rattachements]);
    }

    /** Demande d'accès à un grossiste. */
    public function demanderAcces(Request $request): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'string']]);

        $rattachement = $this->service->demanderAcces($request->user(), $data['slug']);

        return response()->json(['data' => [
            'statut' => $rattachement->statut,
            'message' => $rattachement->estApprouve()
                ? 'Accès déjà actif.'
                : 'Demande transmise au grossiste, en attente de validation.',
        ]], 201);
    }

    /** Annuaire public des grossistes acceptant les demandes. */
    public function annuaire(): JsonResponse
    {
        // Tenant ne porte pas BelongsToTenant : lecture légitimement globale.
        $grossistes = Tenant::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Tenant $t) => ['name' => $t->name, 'slug' => $t->slug]);

        return response()->json(['data' => $grossistes]);
    }

    private function acheteurPayload(Acheteur $acheteur): array
    {
        return [
            'id' => $acheteur->id,
            'name' => $acheteur->name,
            'email' => $acheteur->email,
            'phone' => $acheteur->phone,
        ];
    }
}
