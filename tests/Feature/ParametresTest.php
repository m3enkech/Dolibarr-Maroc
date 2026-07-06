<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paramètres entreprise : feature flags (modules activables par tenant).
 */
class ParametresTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $company, string $email): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin '.$company,
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    public function test_default_features(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $this->withToken($token)->getJson('/api/v1/parametres')
            ->assertOk()
            ->assertJsonPath('data.features.relances', true)   // universel : activé par défaut
            ->assertJsonPath('data.features.effets', false);   // métier B2B : désactivé par défaut
    }

    public function test_features_can_be_toggled_independently(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // On active les effets sans toucher aux relances (les deux peuvent coexister).
        $this->withToken($token)->putJson('/api/v1/parametres', [
            'features' => ['effets' => true],
        ])->assertOk()
            ->assertJsonPath('data.features.effets', true)
            ->assertJsonPath('data.features.relances', true);

        // Puis on coupe les relances.
        $this->withToken($token)->putJson('/api/v1/parametres', [
            'features' => ['relances' => false],
        ])->assertOk()
            ->assertJsonPath('data.features.relances', false)
            ->assertJsonPath('data.features.effets', true);

        // Persisté.
        $this->withToken($token)->getJson('/api/v1/parametres')
            ->assertJsonPath('data.features.relances', false)
            ->assertJsonPath('data.features.effets', true);
    }

    public function test_unknown_feature_keys_are_ignored(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $response = $this->withToken($token)->putJson('/api/v1/parametres', [
            'features' => ['inexistant' => true],
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('inexistant', $response->json('data.features'));
    }

    public function test_features_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $this->withToken($tokenA)->putJson('/api/v1/parametres', ['features' => ['effets' => true]]);

        // B garde ses défauts.
        $this->withToken($tokenB)->getJson('/api/v1/parametres')
            ->assertJsonPath('data.features.effets', false);
    }
}
