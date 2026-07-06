<?php

namespace App\Modules\Equipe\Http\Controllers;

use App\Core\Auth\Roles;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Modules\Equipe\Services\EquipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Parcours public d'acceptation d'invitation (hors authentification et hors
 * contexte tenant : on retrouve l'invitation par son token).
 */
class InvitationController extends Controller
{
    public function __construct(private readonly EquipeService $service) {}

    /** Détail d'une invitation pour préremplir le formulaire de création. */
    public function show(string $token): JsonResponse
    {
        $invitation = Invitation::where('token', $token)->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return response()->json(['message' => 'Invitation invalide ou expirée.'], 404);
        }

        return response()->json([
            'data' => [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'role_label' => Roles::LIBELLES[$invitation->role] ?? $invitation->role,
                'company_name' => $invitation->tenant->name,
            ],
        ]);
    }

    /** Accepte l'invitation : crée l'utilisateur et le connecte. */
    public function accepter(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $invitation = Invitation::where('token', $token)->first();

        if ($invitation === null) {
            return response()->json(['message' => 'Invitation invalide ou expirée.'], 404);
        }

        [$user, $tenant] = $this->service->accepter($invitation, $data['name'], $data['password']);

        return response()->json([
            'token' => $user->createToken('spa')->plainTextToken,
            'user' => $user,
            'tenant' => $tenant,
            'permissions' => $user->permissionsMap(),
        ], 201);
    }
}
