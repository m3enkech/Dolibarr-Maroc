<?php

namespace App\Modules\Superadmin\Http\Controllers;

use App\Core\Auth\Roles;
use App\Core\Tenancy\Tenant;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Modules\Superadmin\Services\SubscriptionBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Console d'administration PLATEFORME (réservée au superadmin). Opère
 * CROSS-TENANT : liste et pilote toutes les entreprises clientes, leurs
 * abonnements (mensuel/annuel, statut, échéance) et le suivi des paiements.
 */
class SuperadminController extends Controller
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    /** Vue d'ensemble : entreprises + statistiques globales. */
    public function index(): JsonResponse
    {
        $tenants = Tenant::query()->withCount('payments')->orderByDesc('created_at')->get();

        // Administrateurs de toutes les entreprises en une seule requête : la
        // liste affiche le contact de chaque abonné sans requête par ligne.
        $admins = User::query()
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->where('role', Roles::ADMIN)
            ->orderByDesc('is_active')->orderBy('id')
            ->get(['id', 'tenant_id', 'name', 'email', 'is_active'])
            ->groupBy('tenant_id');

        return response()->json([
            'data' => [
                'tenants' => $tenants
                    ->map(fn (Tenant $t) => $this->tenantItem($t, $admins->get($t->id)?->first()))
                    ->values(),
                'stats' => $this->stats($tenants),
                'plans' => collect(config('plans.plans'))
                    ->map(fn ($p, $cle) => ['value' => $cle, 'label' => $p['label'], 'price' => $p['price'], 'price_annual' => $p['price_annual']])
                    ->values(),
                'methods' => SubscriptionPayment::METHODS,
            ],
        ]);
    }

    /** Détail d'une entreprise : utilisateurs + historique des paiements. */
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

        $payments = $tenant->payments()->latest('paid_at')->latest('id')->get()
            ->map(fn (SubscriptionPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'paid_at' => $p->paid_at->toDateString(),
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'reference' => $p->reference,
                'note' => $p->note,
                'has_invoice' => $p->document_vente_id !== null,
            ]);

        return response()->json(['data' => [
            'tenant' => $this->tenantItem($tenant),
            'users' => $users,
            'payments' => $payments,
        ]]);
    }

    /** Change le plan, les sièges extra et le cycle/statut d'abonnement. */
    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['sometimes', 'string', Rule::in(array_keys(config('plans.plans')))],
            'extra_seats' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'billing_cycle' => ['sometimes', 'string', Rule::in(['mensuel', 'annuel'])],
            'subscription_status' => ['sometimes', 'string', Rule::in(['essai', 'actif', 'annule'])],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'current_period_end' => ['sometimes', 'nullable', 'date'],
        ]);

        $tenant->update($data);

        return response()->json(['data' => $this->tenantItem($tenant->refresh())]);
    }

    /** Enregistre un paiement d'abonnement : avance l'échéance et réactive. */
    public function enregistrerPaiement(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'method' => ['required', 'string', Rule::in(SubscriptionPayment::METHODS)],
            'paid_at' => ['sometimes', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();

        // La nouvelle période démarre à la fin de la période payée en cours si
        // elle est encore à venir (renouvellement continu), sinon au jour du paiement.
        $start = ($tenant->current_period_end && $tenant->current_period_end->isFuture())
            ? $tenant->current_period_end->copy()
            : $paidAt->copy();
        $end = $start->copy()->addMonthsNoOverflow($tenant->isAnnual() ? 12 : 1);

        // Facture d'abonnement émise dans la compta de l'opérateur (le tenant du
        // superadmin), sauf s'il facture sa propre entreprise.
        $operateur = $request->user()->tenant;
        $facture = null;
        if ($operateur !== null && $operateur->id !== $tenant->id) {
            $planLabel = config('plans.plans')[$tenant->plan]['label'] ?? $tenant->plan;
            $facture = $this->billing->facturer($operateur, $tenant, (float) $data['amount'], $start, $end, $data['method'], $planLabel);
        }

        $tenant->payments()->create([
            'amount' => $facture ? (float) $facture->total_ttc : $data['amount'],
            'method' => $data['method'],
            'paid_at' => $paidAt->toDateString(),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'recorded_by' => $request->user()->id,
            'operator_tenant_id' => $facture ? $operateur->id : null,
            'document_vente_id' => $facture?->id,
        ]);

        $tenant->update([
            'current_period_end' => $end->toDateString(),
            'subscription_status' => 'actif',
            'subscription_started_at' => $tenant->subscription_started_at ?? now(),
        ]);

        return response()->json(['data' => $this->tenantItem($tenant->refresh())]);
    }

    public function suspend(Request $request, Tenant $tenant): JsonResponse
    {
        abort_if($tenant->id === $request->user()->tenant_id, 422, 'Vous ne pouvez pas suspendre votre propre entreprise.');

        $tenant->update(['suspended_at' => now()]);

        return response()->json(['data' => $this->tenantItem($tenant->refresh())]);
    }

    public function reactivate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['suspended_at' => null]);

        return response()->json(['data' => $this->tenantItem($tenant->refresh())]);
    }

    /** PDF de la facture d'abonnement liée à un paiement. */
    public function pdfPaiement(SubscriptionPayment $payment)
    {
        return $this->billing->pdf($payment);
    }

    private function tenantItem(Tenant $tenant, ?User $admin = null): array
    {
        $plans = config('plans.plans');
        $dernier = $tenant->payments()->latest('paid_at')->latest('id')->first();
        $admin ??= $tenant->users()->where('role', Roles::ADMIN)
            ->orderByDesc('is_active')->orderBy('id')->first();

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
            // Abonnement
            'billing_cycle' => $tenant->billing_cycle,
            'subscription_status' => $tenant->subscription_status,
            'effective_status' => $tenant->effectiveStatus(),
            'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
            'current_period_end' => $tenant->current_period_end?->toDateString(),
            'next_due' => $tenant->subscription_status === 'essai'
                ? $tenant->trial_ends_at?->toDateString()
                : $tenant->current_period_end?->toDateString(),
            'subscription_amount' => $tenant->subscriptionAmount(),
            'mrr' => $tenant->mrr(),
            'payments_count' => (int) ($tenant->payments_count ?? $tenant->payments()->count()),
            'last_payment' => $dernier ? ['amount' => (float) $dernier->amount, 'paid_at' => $dernier->paid_at->toDateString()] : null,
            'created_at' => $tenant->created_at,
            'contact' => $this->contact($tenant, $admin),
        ];
    }

    /**
     * Coordonnées de l'abonné, telles qu'il les a saisies dans « Identité de
     * la société » (Paramètres). L'inscription ne demande que le nom de
     * l'entreprise et l'e-mail de l'administrateur : le reste peut être vide.
     * L'adresse est celle qui figure sur ses factures d'abonnement.
     */
    private function contact(Tenant $tenant, ?User $admin): array
    {
        $settings = $tenant->settings ?? [];
        $champ = function (string $cle) use ($settings): ?string {
            $valeur = trim((string) ($settings[$cle] ?? ''));

            return $valeur === '' ? null : $valeur;
        };

        return [
            'email' => $champ('email'),
            'phone' => $champ('phone'),
            'address' => $champ('address'),
            'postal_code' => $champ('postal_code'),
            'city' => $champ('city'),
            'website' => $champ('website'),
            'ice' => $champ('ice'),
            'if' => $champ('if'),
            'rc' => $champ('rc'),
            'admin_name' => $admin?->name,
            'admin_email' => $admin?->email,
        ];
    }

    private function stats($tenants): array
    {
        $encaisseMois = (float) SubscriptionPayment::query()
            ->whereYear('paid_at', now()->year)
            ->whereMonth('paid_at', now()->month)
            ->sum('amount');

        // MRR = entreprises payantes (actif/en retard, non suspendues, non annulées).
        $payantes = $tenants->filter(fn (Tenant $t) => ! $t->isSuspended()
            && $t->subscription_status === 'actif');

        return [
            'tenants_total' => $tenants->count(),
            'tenants_active' => $tenants->filter(fn (Tenant $t) => ! $t->isSuspended())->count(),
            'tenants_suspended' => $tenants->filter(fn (Tenant $t) => $t->isSuspended())->count(),
            'en_essai' => $tenants->filter(fn (Tenant $t) => $t->effectiveStatus() === 'essai')->count(),
            'en_retard' => $tenants->filter(fn (Tenant $t) => $t->effectiveStatus() === 'en_retard')->count(),
            'users_total' => User::count(),
            'users_active' => User::where('is_active', true)->count(),
            'by_plan' => collect(config('plans.plans'))->keys()
                ->mapWithKeys(fn ($cle) => [$cle => $tenants->where('plan', $cle)->count()])
                ->all(),
            'extra_seats_sold' => (int) $tenants->sum('extra_seats'),
            'mrr_estimated' => round($payantes->sum(fn (Tenant $t) => $t->mrr()), 2),
            'encaisse_mois' => $encaisseMois,
        ];
    }
}
