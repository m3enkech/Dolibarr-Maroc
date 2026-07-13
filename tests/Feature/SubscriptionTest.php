<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abonnements plateforme : cycle (mensuel/annuel), statut, échéances et suivi
 * des paiements par le superadmin.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function entreprise(string $company, string $email): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company, 'name' => 'Admin '.$company,
            'email' => $email, 'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), Tenant::find($res->json('tenant.id'))];
    }

    private function superadminToken(): string
    {
        [$token, $tenant] = $this->entreprise('Plateforme', 'super@plateforme.ma');
        $tenant->users()->first()->update(['is_superadmin' => true]);

        return $token;
    }

    public function test_nouveau_tenant_demarre_en_essai_14_jours(): void
    {
        [, $tenant] = $this->entreprise('Acme', 'a@acme.ma');

        $this->assertSame('essai', $tenant->subscription_status);
        $this->assertTrue($tenant->trial_ends_at->isFuture());
        $this->assertSame('essai', $tenant->effectiveStatus());
    }

    public function test_enregistrer_un_paiement_mensuel_avance_l_echeance(): void
    {
        $super = $this->superadminToken();
        [, $tenant] = $this->entreprise('Acme', 'a@acme.ma');
        $tenant->update(['plan' => 'business']);

        $res = $this->withToken($super)->postJson("/api/v1/superadmin/tenants/{$tenant->id}/paiements", [
            'amount' => 249, 'method' => 'virement', 'reference' => 'VIR-001',
        ])->assertOk();

        $res->assertJsonPath('data.subscription_status', 'actif')
            ->assertJsonPath('data.effective_status', 'actif');

        // Échéance ~1 mois plus tard, un paiement dans l'historique.
        $tenant->refresh();
        $this->assertTrue($tenant->current_period_end->isFuture());
        $this->assertSame(1, $tenant->payments()->count());
        $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $tenant->current_period_end->timestamp, 3 * 86400);
    }

    public function test_cycle_annuel_facture_le_tarif_annuel_sur_12_mois(): void
    {
        $super = $this->superadminToken();
        [, $tenant] = $this->entreprise('Acme', 'a@acme.ma');
        $tenant->update(['plan' => 'business', 'billing_cycle' => 'annuel']);

        // Montant annuel attendu = price_annual du plan business.
        $this->assertSame(2388, $tenant->subscriptionAmount());

        $this->withToken($super)->postJson("/api/v1/superadmin/tenants/{$tenant->id}/paiements", [
            'amount' => 2388, 'method' => 'cmi',
        ])->assertOk();

        $tenant->refresh();
        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $tenant->current_period_end->timestamp, 3 * 86400);
    }

    public function test_echeance_depassee_passe_en_retard(): void
    {
        [, $tenant] = $this->entreprise('Acme', 'a@acme.ma');
        $tenant->update([
            'subscription_status' => 'actif',
            'current_period_end' => now()->subDays(5)->toDateString(),
        ]);

        $this->assertTrue($tenant->refresh()->isPastDue());
        $this->assertSame('en_retard', $tenant->effectiveStatus());
    }

    public function test_stats_plateforme_reflete_essai_retard_et_encaisse(): void
    {
        $super = $this->superadminToken();
        [, $acme] = $this->entreprise('Acme', 'a@acme.ma');        // essai
        [, $globex] = $this->entreprise('Globex', 'b@globex.ma');
        $globex->update(['subscription_status' => 'actif', 'current_period_end' => now()->subDay()->toDateString()]); // en retard

        // Un paiement ce mois-ci.
        $this->withToken($super)->postJson("/api/v1/superadmin/tenants/{$acme->id}/paiements", [
            'amount' => 99, 'method' => 'especes',
        ])->assertOk();

        $stats = $this->withToken($super)->getJson('/api/v1/superadmin/tenants')->json('data.stats');

        $this->assertSame(1, $stats['en_retard']);          // Globex
        $this->assertGreaterThanOrEqual(99, $stats['encaisse_mois']);
    }

    public function test_historique_paiements_dans_le_detail(): void
    {
        $super = $this->superadminToken();
        [, $tenant] = $this->entreprise('Acme', 'a@acme.ma');

        $this->withToken($super)->postJson("/api/v1/superadmin/tenants/{$tenant->id}/paiements", [
            'amount' => 99, 'method' => 'cheque', 'reference' => 'CHQ-42',
        ])->assertOk();

        $this->withToken($super)->getJson("/api/v1/superadmin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.payments.0.reference', 'CHQ-42')
            ->assertJsonPath('data.payments.0.method', 'cheque');
    }
}
