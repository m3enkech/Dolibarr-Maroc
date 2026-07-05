<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogueTest extends TestCase
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

    public function test_products_and_services_have_separate_sequences(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $year = now()->year;

        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ])->assertCreated()->assertJsonPath('data.code', "PR-{$year}-00001");

        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Sable m3', 'type' => 'product', 'sell_price' => 300, 'tva_rate' => 20,
        ])->assertJsonPath('data.code', "PR-{$year}-00002");

        // Les services ont leur propre séquence SV.
        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Installation', 'type' => 'service', 'sell_price' => 500, 'tva_rate' => 20,
        ])->assertCreated()->assertJsonPath('data.code', "SV-{$year}-00001");
    }

    public function test_only_moroccan_tva_rates_are_accepted(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // 15 % n'est pas un taux marocain.
        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'X', 'type' => 'product', 'sell_price' => 100, 'tva_rate' => 15,
        ])->assertUnprocessable()->assertJsonValidationErrors('tva_rate');

        foreach ([0, 7, 10, 14, 20] as $rate) {
            $this->withToken($token)->postJson('/api/v1/produits', [
                'name' => "Produit TVA {$rate}", 'type' => 'product', 'sell_price' => 100, 'tva_rate' => $rate,
            ])->assertCreated();
        }
    }

    public function test_ttc_price_is_computed_from_ht_and_tva(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $response = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Prestation conseil', 'type' => 'service', 'sell_price' => 1000, 'tva_rate' => 20,
        ]);

        $response->assertCreated()->assertJsonPath('data.sell_price_ttc', '1200.00');

        $response = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Beurre', 'type' => 'product', 'sell_price' => 45.50, 'tva_rate' => 14,
        ]);

        $response->assertCreated()->assertJsonPath('data.sell_price_ttc', '51.87');
    }

    public function test_type_and_code_are_immutable_after_creation(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $produit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ])->json('data');

        $updated = $this->withToken($token)->putJson('/api/v1/produits/'.$produit['id'], [
            'name' => 'Ciment 50kg', 'type' => 'service', 'code' => 'HACK-01',
        ]);

        $updated->assertOk()
            ->assertJsonPath('data.name', 'Ciment 50kg')
            ->assertJsonPath('data.type', 'product')
            ->assertJsonPath('data.code', $produit['code']);
    }

    public function test_produits_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $produit = $this->withToken($tokenA)->postJson('/api/v1/produits', [
            'name' => 'Secret', 'type' => 'product', 'sell_price' => 10, 'tva_rate' => 20,
        ])->json('data');

        $this->withToken($tokenB)->getJson('/api/v1/produits')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withToken($tokenB)->getJson('/api/v1/produits/'.$produit['id'])
            ->assertNotFound();
    }

    public function test_search_and_type_filter(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20, 'barcode' => '6111000000017',
        ]);
        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Maintenance annuelle', 'type' => 'service', 'sell_price' => 2000, 'tva_rate' => 20,
        ]);

        $this->withToken($token)->getJson('/api/v1/produits?search=6111000000017')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Ciment 50kg');

        $this->withToken($token)->getJson('/api/v1/produits?type=service')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Maintenance annuelle');
    }
}
