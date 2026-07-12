<?php

namespace App\Modules\Superadmin\Http\Controllers;

use App\Core\Auth\Roles;
use App\Core\Tenancy\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Console d'administration PLATEFORME (réservée au superadmin). Opère
 * CROSS-TENANT : liste et pilote toutes les entreprises clientes. N'utilise
 * pas le middleware 'tenant' (pas de contexte tenant). Le modèle Tenant et le
 * modèle User ne portent pas le scope tenant, les requêtes sont donc globales.
 */
class SuperadminController extends Controller
{
    /** Vue d'ensemble : entreprises + statistiques globales. */
    public function index(): JsonResponse
    {
        $tenants = Tenant::query()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => [
                'tenants' => $tenants->map(fn (Tenant $t) => $this->tenantItem($t))->values(),
                'stats' => $this->stats($tenants),
                'plans' => collect(config('plans.plans'))
                    ->map(fn ($p, $cle) => ['value' => $cle, 'label' => $p['label'], 'price' => $p['price']])
                    ->values(),
            ],
        ]);
    }

    /** Détail d'une entreprise avec ses utilisateurs. */
    public function show(Tenant $tenant): JsonResponse
    {
        $users = $tenant->users()->orderByDesc('is_active')->orderBy('name')->get()
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

        return response()->json(['data' => [
            'tenant' => $this->tenantItem($tenant),
            'users' => $users,
        ]]);
    }

    /** Change le plan et/ou les sièges extra d'une entreprise. */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string', Rule::in(array_keys(config('plans.plans')))],
            'extra_seats' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $tenant->update($data);

        return response()->json(['data' => $this->tenantItem($tenant->refresh())]);
    }

    /** Suspend l'accès d'une entreprise (ses utilisateurs sont coupés). */
    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        abort_if($tenant->id === $request->user()->tenant_id, 422, 'Vous ne pouvez pas suspendre votre propre entreprise.');

        $tenant->update(['suspended_at' => now()]);

        return response()->json(['data' => $this->tenantItem($tenant->refresh())]);
    }

    /** Réactive une entreprise suspendue. */
    public function reactivate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['suspended_at' => null]);

        return response()->json(['data' => $this->tenantItem($tenant->refresh())]);
    }

    private function tenantItem(Tenant $tenant): array
    {
        $plans = config('plans.plans');

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'plan_label' => $plans[$tenant->plan]['label'] ?? $tenant->plan,
            'included_seats' => $tenant->includedSeats(),
            'extra_seats' => (int) $tenant->extra_seats,
            'seat_limit' => $tenant->seatLimit(),
            'seats_used' => $tenant->seatsUsed(),
            'users_count' => $tenant->users()->count(),
            'suspended' => $tenant->isSuspended(),
            'suspended_at' => $tenant->suspended_at,
            'estimated_monthly' => $tenant->estimatedMonthly(),
            'created_at' => $tenant->created_at,
        ];
    }

    private function stats($tenants): array
    {
        $actifs = $tenants->filter(fn (Tenant $t) => ! $t->isSuspended());

        return [
            'tenants_total' => $tenants->count(),
            'tenants_active' => $actifs->count(),
            'tenants_suspended' => $tenants->count() - $actifs->count(),
            'users_total' => User::count(),
            'users_active' => User::where('is_active', true)->count(),
            'by_plan' => collect(config('plans.plans'))->keys()
                ->mapWithKeys(fn ($cle) => [$cle => $tenants->where('plan', $cle)->count()])
                ->all(),
            'extra_seats_sold' => (int) $tenants->sum('extra_seats'),
            'mrr_estimated' => (int) $actifs->sum(fn (Tenant $t) => $t->estimatedMonthly()),
        ];
    }
}
