<?php

namespace App\Modules\Equipe\Http\Controllers;

use App\Core\Auth\Roles;
use App\Core\Tenancy\Tenant;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Modules\Equipe\Services\EquipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipeController extends Controller
{
    public function __construct(private readonly EquipeService $service) {}

    /** Équipe complète : utilisateurs, invitations en attente, abonnement. */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;

        return response()->json(['data' => $this->payload($tenant)]);
    }

    /** Invite un collaborateur ; renvoie le token à partager (pas d'email requis). */
    public function inviter(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(Roles::assignables())],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;

        $invitation = $this->service->inviter($tenant, $request->user(), $data['email'], $data['role']);

        return response()->json([
            'data' => $this->payload($tenant->refresh()),
            'invitation' => $this->invitationItem($invitation),
        ], 201);
    }

    public function revoquerInvitation(Request $request, Invitation $invitation): JsonResponse
    {
        $this->autoriserMemeTenant($request, $invitation->tenant_id);

        $this->service->revoquerInvitation($invitation);

        return response()->json(['data' => $this->payload($request->user()->tenant->refresh())]);
    }

    public function modifierUtilisateur(Request $request, User $user): JsonResponse
    {
        $this->autoriserMemeTenant($request, $user->tenant_id);

        $data = $request->validate([
            'role' => ['sometimes', 'string', Rule::in(Roles::assignables())],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->service->modifierUtilisateur($user, $data);

        return response()->json(['data' => $this->payload($request->user()->tenant->refresh())]);
    }

    public function supprimerUtilisateur(Request $request, User $user): JsonResponse
    {
        $this->autoriserMemeTenant($request, $user->tenant_id);

        $this->service->supprimerUtilisateur($user, $request->user());

        return response()->json(['data' => $this->payload($request->user()->tenant->refresh())]);
    }

    /** Réservé au superadmin plateforme : ajuste le plan et les sièges extra. */
    public function abonnement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string', Rule::in(array_keys(config('plans.plans')))],
            'extra_seats' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;
        $tenant->update($data);

        return response()->json(['data' => $this->payload($tenant->refresh())]);
    }

    /** Empêche d'agir sur les ressources d'un autre tenant (défense en profondeur). */
    private function autoriserMemeTenant(Request $request, int $tenantId): void
    {
        abort_unless($request->user()->tenant_id === $tenantId, 404);
    }

    private function payload(Tenant $tenant): array
    {
        $plans = config('plans.plans');

        $users = $tenant->users()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_label' => Roles::LIBELLES[$u->role] ?? $u->role,
                'is_active' => (bool) $u->is_active,
                'is_superadmin' => (bool) $u->is_superadmin,
                'created_at' => $u->created_at,
            ]);

        $invitations = $tenant->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get()
            ->map(fn (Invitation $i) => $this->invitationItem($i));

        return [
            'users' => $users,
            'invitations' => $invitations,
            'roles' => collect(Roles::assignables())
                ->map(fn ($r) => ['value' => $r, 'label' => Roles::LIBELLES[$r]])
                ->values(),
            'subscription' => [
                'plan' => $tenant->plan,
                'plan_label' => $plans[$tenant->plan]['label'] ?? $tenant->plan,
                'included_seats' => $tenant->includedSeats(),
                'extra_seats' => (int) $tenant->extra_seats,
                'seat_limit' => $tenant->seatLimit(),
                'seats_used' => $tenant->seatsUsed(),
                'pending_invitations' => $tenant->pendingInvitationsCount(),
                'seats_available' => max(0, $tenant->seatLimit() - $tenant->seatsConsumed()),
                'extra_seat_price' => (int) config('plans.extra_seat_price'),
            ],
        ];
    }

    private function invitationItem(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'role_label' => Roles::LIBELLES[$invitation->role] ?? $invitation->role,
            'token' => $invitation->token,
            'expires_at' => $invitation->expires_at,
        ];
    }
}
