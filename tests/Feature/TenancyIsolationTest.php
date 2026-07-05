<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $company, string $email): string
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin '.$company,
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertCreated();

        return $response->json('token');
    }

    public function test_registration_creates_tenant_and_admin(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Atlas Négoce',
            'name' => 'Admin',
            'email' => 'admin@atlas.ma',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('tenant.name', 'Atlas Négoce');
    }

    public function test_tiers_codes_are_sequenced_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $year = now()->year;

        $first = $this->withToken($tokenA)->postJson('/api/v1/tiers', ['name' => 'Client A1']);
        $first->assertCreated()->assertJsonPath('data.code', "CL-{$year}-00001");

        $this->withToken($tokenA)->postJson('/api/v1/tiers', ['name' => 'Client A2'])
            ->assertJsonPath('data.code', "CL-{$year}-00002");

        // La séquence du tenant B est indépendante : elle repart à 00001.
        $this->withToken($tokenB)->postJson('/api/v1/tiers', ['name' => 'Client B1'])
            ->assertJsonPath('data.code', "CL-{$year}-00001");
    }

    public function test_a_tenant_cannot_see_or_access_another_tenants_tiers(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $created = $this->withToken($tokenA)
            ->postJson('/api/v1/tiers', ['name' => 'Secret Client'])
            ->json('data');

        $this->withToken($tokenB)->getJson('/api/v1/tiers')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // Accès direct par id : le scope tenant doit rendre la ressource introuvable.
        $this->withToken($tokenB)->getJson('/api/v1/tiers/'.$created['id'])
            ->assertNotFound();
        $this->withToken($tokenB)->putJson('/api/v1/tiers/'.$created['id'], ['name' => 'Piraté'])
            ->assertNotFound();
        $this->withToken($tokenB)->deleteJson('/api/v1/tiers/'.$created['id'])
            ->assertNotFound();
    }

    public function test_ice_must_be_fifteen_digits(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'X', 'ice' => '123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ice');

        $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'X', 'ice' => '001234567890123'])
            ->assertCreated();
    }

    public function test_guests_cannot_access_tiers(): void
    {
        $this->getJson('/api/v1/tiers')->assertUnauthorized();
    }
}
