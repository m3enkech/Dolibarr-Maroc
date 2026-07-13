<?php

namespace App\Modules\Parametres\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Modules\Superadmin\Services\SubscriptionBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vue « Mon abonnement » côté entreprise cliente : statut de son abonnement et
 * ses factures d'abonnement (émises par la plateforme), téléchargeables en PDF.
 */
class AbonnementController extends Controller
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;
        $plans = config('plans.plans');

        $factures = $tenant->payments()->latest('paid_at')->latest('id')->get()
            ->map(fn (SubscriptionPayment $p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'paid_at' => $p->paid_at->toDateString(),
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'reference' => $p->reference,
                'has_invoice' => $p->document_vente_id !== null,
            ]);

        return response()->json(['data' => [
            'subscription' => [
                'plan' => $tenant->plan,
                'plan_label' => $plans[$tenant->plan]['label'] ?? $tenant->plan,
                'billing_cycle' => $tenant->billing_cycle,
                'status' => $tenant->effectiveStatus(),
                'current_period_end' => $tenant->current_period_end?->toDateString(),
                'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                'amount' => $tenant->subscriptionAmount(),
            ],
            'factures' => $factures,
        ]]);
    }

    /** Télécharge la facture d'abonnement (réservée à l'entreprise concernée). */
    public function pdf(Request $request, SubscriptionPayment $payment)
    {
        abort_unless($payment->tenant_id === $request->user()->tenant_id, 404);

        return $this->billing->pdf($payment);
    }
}
