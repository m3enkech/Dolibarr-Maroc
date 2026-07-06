<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Socle multi-utilisateurs : invitations par token, limites de sièges par
 * plan, garde-fous (dernier admin), désactivation de compte.
 */
class EquipeTest extends TestCase
{
    use RefreshDatabase;

    /** Enregistre une entreprise (admin) et renvoie [token, tenant]. */
    private function nouvelleEntreprise(string $plan = 'business'): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme',
            'name' => 'Patron',
            'email' => 'patron@acme.ma',
            'password' => 'password123',
        ])->assertCreated();

        $tenant = Tenant::find($res->json('tenant.id'));
        $tenant->update(['plan' => $plan]);

        return [$res->json('token'), $tenant->refresh()];
    }

    public function test_invitation_puis_acceptation_cree_le_collaborateur(): void
    {
        [$token, $tenant] = $this->nouvelleEntreprise();

        $token_invit = $this->withToken($token)->postJson('/api/v1/equipe/invitations', [
            'email' => 'commercial@acme.ma',
            'role' => 'commercial',
        ])->assertCreated()->json('invitation.token');

        // Le collaborateur consulte l'invitation (public, par token).
        $this->getJson("/api/v1/invitations/{$token_invit}")
            ->assertOk()
            ->assertJsonPath('data.email', 'commercial@acme.ma')
            ->assertJsonPath('data.company_name', 'Acme');

        // Il l'accepte en choisissant son mot de passe.
        $this->postJson("/api/v1/invitations/{$token_invit}/accepter", [
            'name' => 'Sara',
            'password' => 'motdepasse1',
        ])->assertCreated()
            ->assertJsonPath('user.role', 'commercial')
            ->assertJsonPath('permissions.ventes', 'write');

        // Il peut se connecter et rejoint bien le même tenant.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'commercial@acme.ma',
            'password' => 'motdepasse1',
        ])->assertOk()->assertJsonPath('tenant.id', $tenant->id);

        $this->assertSame(2, $tenant->refresh()->seatsUsed());
    }

    public function test_limite_de_sieges_bloque_les_invitations(): void
    {
        // Plan Essentiel = 2 sièges ; l'admin en occupe 1.
        [$token] = $this->nouvelleEntreprise('essentiel');

        // 1re invitation : réserve le 2e siège.
        $this->withToken($token)->postJson('/api/v1/equipe/invitations', [
            'email' => 'a@acme.ma', 'role' => 'lecture',
        ])->assertCreated();

        // 2e invitation : plus de siège disponible.
        $this->withToken($token)->postJson('/api/v1/equipe/invitations', [
            'email' => 'b@acme.ma', 'role' => 'lecture',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_sieges_extra_augmentent_la_limite(): void
    {
        [$token, $tenant] = $this->nouvelleEntreprise('essentiel');
        $tenant->update(['extra_seats' => 1]); // 2 inclus + 1 = 3

        foreach (['a@acme.ma', 'b@acme.ma'] as $email) {
            $this->withToken($token)->postJson('/api/v1/equipe/invitations', [
                'email' => $email, 'role' => 'lecture',
            ])->assertCreated();
        }

        $this->assertSame(3, $tenant->refresh()->seatLimit());
        $this->assertSame(0, $tenant->seatsConsumed() - 3); // 1 actif + 2 invitations
    }

    public function test_on_ne_peut_pas_retrograder_le_dernier_admin(): void
    {
        [$token, $tenant] = $this->nouvelleEntreprise();
        $admin = $tenant->users()->first();

        $this->withToken($token)->putJson("/api/v1/equipe/users/{$admin->id}", [
            'role' => 'commercial',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_on_ne_peut_pas_supprimer_son_propre_compte(): void
    {
        [$token, $tenant] = $this->nouvelleEntreprise();
        $admin = $tenant->users()->first();

        $this->withToken($token)->deleteJson("/api/v1/equipe/users/{$admin->id}")
            ->assertStatus(422)->assertJsonValidationErrors('user');
    }

    public function test_desactiver_un_compte_libere_un_siege_et_bloque_la_connexion(): void
    {
        [$token, $tenant] = $this->nouvelleEntreprise('essentiel');
        $collab = User::factory()->create([
            'tenant_id' => $tenant->id, 'role' => 'lecture', 'is_active' => true,
            'email' => 'collab@acme.ma',
        ]);

        $this->assertSame(2, $tenant->refresh()->seatsUsed());

        $this->withToken($token)->putJson("/api/v1/equipe/users/{$collab->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertSame(1, $tenant->refresh()->seatsUsed());

        $this->postJson('/api/v1/auth/login', [
            'email' => 'collab@acme.ma', 'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_abonnement_reserve_au_superadmin(): void
    {
        [$token, $tenant] = $this->nouvelleEntreprise('essentiel');

        // Un admin d'entreprise ne peut pas s'auto-attribuer des sièges.
        $this->withToken($token)->putJson('/api/v1/equipe/abonnement', [
            'extra_seats' => 5,
        ])->assertForbidden();

        // Le superadmin plateforme, si.
        $tenant->users()->first()->update(['is_superadmin' => true]);
        $this->withToken($token)->putJson('/api/v1/equipe/abonnement', [
            'plan' => 'premium', 'extra_seats' => 5,
        ])->assertOk()->assertJsonPath('data.subscription.seat_limit', 30);
    }
}
