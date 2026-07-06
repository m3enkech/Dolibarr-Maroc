<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kits : produits composés vendus comme un article unique, dont la vente
 * consomme le stock des composants.
 */
class KitTest extends TestCase
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

    private function createProduit(string $token, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', array_merge([
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'buy_price' => 60, 'tva_rate' => 20,
        ], $overrides))->json('data');
    }

    private function entrerStock(string $token, int $produitId, float $quantite): void
    {
        $entrepot = $this->withToken($token)
            ->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])
            ->json('data')
            ?? [];

        // Réutilise l'entrepôt existant si déjà créé.
        $entrepotId = $entrepot['id'] ?? $this->withToken($token)->getJson('/api/v1/stock/entrepots')->json('data.0.id');

        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produitId, 'entrepot_id' => $entrepotId, 'type' => 'entree', 'quantite' => $quantite,
        ])->assertCreated();
    }

    public function test_kit_creation_with_composition(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $ciment = $this->createProduit($token);
        $pose = $this->createProduit($token, ['name' => 'Pose', 'type' => 'service', 'sell_price' => 100]);

        $kit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Pack construction',
            'type' => 'kit',
            'sell_price' => 300,
            'tva_rate' => 20,
            'composants' => [
                ['produit_id' => $ciment['id'], 'quantite' => 2],
                ['produit_id' => $pose['id'], 'quantite' => 1],
            ],
        ]);

        $kit->assertCreated()
            ->assertJsonPath('data.type', 'kit')
            ->assertJsonPath('data.composants.0.quantite', '2.000')
            ->assertJsonPath('data.composants.1.name', 'Pose');
        $this->assertStringStartsWith('KT-', $kit->json('data.code'));
    }

    public function test_kit_composition_can_be_replaced(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $ciment = $this->createProduit($token);
        $sable = $this->createProduit($token, ['name' => 'Sable 25kg', 'sell_price' => 20]);

        $kit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Pack', 'type' => 'kit', 'sell_price' => 200, 'tva_rate' => 20,
            'composants' => [['produit_id' => $ciment['id'], 'quantite' => 2]],
        ])->json('data');

        $maj = $this->withToken($token)->putJson("/api/v1/produits/{$kit['id']}", [
            'composants' => [['produit_id' => $sable['id'], 'quantite' => 5]],
        ]);

        $maj->assertOk();
        $composants = $maj->json('data.composants');
        $this->assertCount(1, $composants);
        $this->assertSame($sable['id'], $composants[0]['produit_id']);
        $this->assertSame('5.000', $composants[0]['quantite']);
    }

    public function test_kit_cannot_contain_a_kit(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $sousKit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Sous-pack', 'type' => 'kit', 'sell_price' => 100, 'tva_rate' => 20,
        ])->json('data');

        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Pack géant', 'type' => 'kit', 'sell_price' => 500, 'tva_rate' => 20,
            'composants' => [['produit_id' => $sousKit['id'], 'quantite' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('composants.0.produit_id');
    }

    public function test_selling_kit_decrements_component_stock(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $ciment = $this->createProduit($token);
        $sable = $this->createProduit($token, ['name' => 'Sable 25kg', 'sell_price' => 20]);
        $pose = $this->createProduit($token, ['name' => 'Pose', 'type' => 'service', 'sell_price' => 100]);
        $this->entrerStock($token, $ciment['id'], 50);

        // Le sable n'a pas de stock initial : il passera en négatif (autorisé).
        $kit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Pack construction', 'type' => 'kit', 'sell_price' => 300, 'tva_rate' => 20,
            'composants' => [
                ['produit_id' => $ciment['id'], 'quantite' => 2],
                ['produit_id' => $sable['id'], 'quantite' => 3],
                ['produit_id' => $pose['id'], 'quantite' => 1], // service : ignoré par le stock
            ],
        ])->json('data');

        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiers['id'],
            'lignes' => [['produit_id' => $kit['id'], 'quantite' => 2]], // 2 packs
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        // 2 packs → ciment -4, sable -6 ; la pose (service) ne bouge pas.
        $mouvements = collect($this->withToken($token)->getJson('/api/v1/stock/mouvements?per_page=50')->json('data'));
        $ventes = $mouvements->where('type', 'vente');
        $this->assertCount(2, $ventes);
        $this->assertSame('-4.000', $ventes->firstWhere('produit.id', $ciment['id'])['quantite']);
        $this->assertSame('-6.000', $ventes->firstWhere('produit.id', $sable['id'])['quantite']);
        $this->assertStringContainsString('Kit', $ventes->first()['note'] ?? '');

        // Les niveaux ne listent que les produits physiques : le kit n'y figure pas.
        $niveaux = collect($this->withToken($token)->getJson('/api/v1/stock/niveaux?per_page=50')->json('data'));
        $this->assertNull($niveaux->firstWhere('name', 'Pack construction'));
        $this->assertSame('46.000', $niveaux->firstWhere('name', 'Ciment 50kg')['quantite']);
    }

    public function test_composition_is_tenant_scoped(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $produitA = $this->createProduit($tokenA);

        // B ne peut pas composer un kit avec un produit du tenant A.
        $this->withToken($tokenB)->postJson('/api/v1/produits', [
            'name' => 'Pack B', 'type' => 'kit', 'sell_price' => 100, 'tva_rate' => 20,
            'composants' => [['produit_id' => $produitA['id'], 'quantite' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('composants.0.produit_id');
    }
}
