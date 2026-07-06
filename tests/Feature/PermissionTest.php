<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rôles & permissions appliqués sur l'API : chaque rôle n'accède qu'aux
 * modules autorisés, en lecture ou écriture selon la méthode HTTP.
 */
class PermissionTest extends TestCase
{
    use RefreshDatabase;

    /** Enregistre une entreprise (admin) et renvoie [token, tenantId]. */
    private function nouvelleEntreprise(string $company, string $email): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin',
            'email' => $email,
            'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), $res->json('tenant.id')];
    }

    /** Crée un collaborateur avec un rôle donné et renvoie son token. */
    private function tokenPourRole(int $tenantId, string $role): string
    {
        $user = User::factory()->create([
            'tenant_id' => $tenantId,
            'role' => $role,
            'is_active' => true,
        ]);

        return $user->createToken('spa')->plainTextToken;
    }

    public function test_admin_recoit_ses_permissions_a_la_connexion(): void
    {
        [$token] = $this->nouvelleEntreprise('Acme', 'admin@acme.ma');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('permissions.compta', 'write')
            ->assertJsonPath('permissions.equipe', 'write');
    }

    public function test_comptable_lit_les_ventes_mais_ne_peut_pas_en_creer(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise('Acme', 'admin@acme.ma');
        $token = $this->tokenPourRole($tenantId, 'comptable');

        $this->withToken($token)->getJson('/api/v1/ventes/documents')->assertOk();
        $this->withToken($token)->postJson('/api/v1/ventes/documents', [])->assertForbidden();
    }

    public function test_commercial_na_pas_acces_a_la_compta(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise('Acme', 'admin@acme.ma');
        $token = $this->tokenPourRole($tenantId, 'commercial');

        $this->withToken($token)->getJson('/api/v1/compta/comptes')->assertForbidden();
        // Mais il peut travailler ses ventes (pas un 403).
        $this->withToken($token)->getJson('/api/v1/ventes/documents')->assertOk();
    }

    public function test_caissier_limite_a_la_caisse(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise('Acme', 'admin@acme.ma');
        $token = $this->tokenPourRole($tenantId, 'caissier');

        $this->withToken($token)->getJson('/api/v1/compta/comptes')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/ventes/documents')->assertForbidden();
        // Lecture catalogue autorisée (pour vendre).
        $this->withToken($token)->getJson('/api/v1/produits')->assertOk();
    }

    public function test_lecture_seule_ne_peut_rien_ecrire(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise('Acme', 'admin@acme.ma');
        $token = $this->tokenPourRole($tenantId, 'lecture');

        $this->withToken($token)->getJson('/api/v1/tiers')->assertOk();
        $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'X'])->assertForbidden();
    }

    public function test_seul_admin_accede_a_la_gestion_equipe(): void
    {
        [$adminToken, $tenantId] = $this->nouvelleEntreprise('Acme', 'admin@acme.ma');
        $comptable = $this->tokenPourRole($tenantId, 'comptable');

        $this->withToken($adminToken)->getJson('/api/v1/equipe')->assertOk();
        $this->withToken($comptable)->getJson('/api/v1/equipe')->assertForbidden();
    }

    public function test_tous_les_roles_peuvent_lire_les_parametres_pour_le_menu(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise('Acme', 'admin@acme.ma');

        foreach (['comptable', 'commercial', 'caissier', 'lecture'] as $role) {
            $token = $this->tokenPourRole($tenantId, $role);
            $this->withToken($token)->getJson('/api/v1/parametres')->assertOk();
            // Mais pas modifier (réservé admin).
            $this->withToken($token)->putJson('/api/v1/parametres', ['features' => []])->assertForbidden();
        }
    }
}
