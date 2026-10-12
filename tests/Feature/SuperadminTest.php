<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Console d'administration plateforme (cross-tenant, réservée au superadmin) :
 * liste des entreprises, changement de plan/sièges, suspension/réactivation.
 */
class SuperadminTest extends TestCase
{
    use RefreshDatabase;

    /** Enregistre une entreprise et renvoie [token, tenant, adminUser]. */
    private function entreprise(string $company, string $email): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin '.$company,
            'email' => $email,
            'password' => 'password123',
        ])->assertCreated();

        $tenant = Tenant::find($res->json('tenant.id'));

        return [$res->json('token'), $tenant, $tenant->users()->first()];
    }

    private function superadminToken(): array
    {
        [$token, $tenant, $user] = $this->entreprise('Plateforme', 'super@plateforme.ma');
        $user->update(['is_superadmin' => true]);

        return [$token, $tenant];
    }

    public function test_non_superadmin_est_refuse(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');

        $this->withToken($token)->getJson('/api/v1/superadmin/tenants')->assertForbidden();
    }

    public function test_superadmin_liste_toutes_les_entreprises_et_stats(): void
    {
        [$superToken] = $this->superadminToken();
        $this->entreprise('Acme', 'a@acme.ma');
        $this->entreprise('Globex', 'b@globex.ma');

        $res = $this->withToken($superToken)->getJson('/api/v1/superadmin/tenants')->assertOk();

        // 3 entreprises (Plateforme + Acme + Globex).
        $this->assertCount(3, $res->json('data.tenants'));
        $res->assertJsonPath('data.stats.tenants_total', 3)
            ->assertJsonPath('data.stats.tenants_active', 3);
    }

    public function test_superadmin_change_plan_et_sieges(): void
    {
        [$superToken] = $this->superadminToken();
        [, $tenant] = $this->entreprise('Acme', 'a@acme.ma');

        $this->withToken($superToken)->putJson("/api/v1/superadmin/tenants/{$tenant->id}", [
            'plan' => 'business', 'extra_seats' => 3,
        ])->assertOk()
            ->assertJsonPath('data.plan', 'business')
            ->assertJsonPath('data.seat_limit', 11); // 8 inclus + 3
    }

    public function test_suspension_bloque_connexion_et_api(): void
    {
        [$superToken] = $this->superadminToken();
        [$acmeToken, $tenant] = $this->entreprise('Acme', 'a@acme.ma');

        // Suspension.
        $this->withToken($superToken)->postJson("/api/v1/superadmin/tenants/{$tenant->id}/suspend")
            ->assertOk()->assertJsonPath('data.suspended', true);

        // Le token existant est coupé (SetTenantContext) et la connexion refusée.
        $this->withToken($acmeToken)->getJson('/api/v1/ventes/documents')->assertForbidden();
        $this->postJson('/api/v1/auth/login', ['email' => 'a@acme.ma', 'password' => 'password123'])
            ->assertStatus(422);

        // Réactivation.
        $this->withToken($superToken)->postJson("/api/v1/superadmin/tenants/{$tenant->id}/reactivate")
            ->assertOk()->assertJsonPath('data.suspended', false);
        $this->postJson('/api/v1/auth/login', ['email' => 'a@acme.ma', 'password' => 'password123'])
            ->assertOk();
    }

    public function test_superadmin_ne_suspend_pas_sa_propre_entreprise(): void
    {
        [$superToken, $tenant] = $this->superadminToken();

        $this->withToken($superToken)->postJson("/api/v1/superadmin/tenants/{$tenant->id}/suspend")
            ->assertStatus(422);
    }

    public function test_detail_entreprise_liste_ses_users(): void
    {
        [$superToken] = $this->superadminToken();
        [, $tenant] = $this->entreprise('Acme', 'a@acme.ma');
        User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'comptable', 'is_active' => true]);

        $res = $this->withToken($superToken)->getJson("/api/v1/superadmin/tenants/{$tenant->id}")->assertOk();
        $this->assertCount(2, $res->json('data.users'));
    }

    public function test_console_montre_les_coordonnees_de_chaque_abonne(): void
    {
        [$superToken] = $this->superadminToken();
        [$acmeToken, $acme] = $this->entreprise('Acme', 'a@acme.ma');
        [, $globex] = $this->entreprise('Globex', 'b@globex.ma');

        // Acme remplit sa fiche « Identité de la société » ; Globex non.
        $this->withToken($acmeToken)->putJson('/api/v1/parametres/societe', [
            'email' => 'compta@acme.ma',
            'phone' => '+212 5 22 00 00 00',
            'address' => '12 rue de Fès',
            'postal_code' => '20000',
            'city' => 'Casablanca',
            'ice' => '001234567000089',
        ])->assertOk();
        $this->app['auth']->forgetGuards();

        $res = $this->withToken($superToken)->getJson('/api/v1/superadmin/tenants')->assertOk();
        $parId = collect($res->json('data.tenants'))->keyBy('id');

        $this->assertSame([
            'email' => 'compta@acme.ma',
            'phone' => '+212 5 22 00 00 00',
            'address' => '12 rue de Fès',
            'postal_code' => '20000',
            'city' => 'Casablanca',
            'website' => null,
            'ice' => '001234567000089',
            'if' => null,
            'rc' => null,
            'admin_name' => 'Admin Acme',
            'admin_email' => 'a@acme.ma',
        ], $parId[$acme->id]['contact']);

        // Fiche vide : seul l'administrateur inscrit est connu.
        $this->assertSame('b@globex.ma', $parId[$globex->id]['contact']['admin_email']);
        $this->assertNull($parId[$globex->id]['contact']['phone']);
        $this->assertNull($parId[$globex->id]['contact']['address']);

        // Le détail porte les mêmes coordonnées.
        $this->withToken($superToken)->getJson("/api/v1/superadmin/tenants/{$acme->id}")
            ->assertOk()
            ->assertJsonPath('data.tenant.contact.city', 'Casablanca')
            ->assertJsonPath('data.tenant.contact.admin_email', 'a@acme.ma');
    }
}
