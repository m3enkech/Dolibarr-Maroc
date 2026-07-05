<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
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

    private function createProduit(string $token, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', array_merge([
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20, 'buy_price' => 60,
        ], $overrides))->json('data');
    }

    private function createEntrepot(string $token, string $name = 'Dépôt Casablanca'): array
    {
        return $this->withToken($token)
            ->postJson('/api/v1/stock/entrepots', ['name' => $name])
            ->json('data');
    }

    public function test_first_entrepot_becomes_default_and_default_is_unique(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $year = now()->year;

        $premier = $this->withToken($token)->postJson('/api/v1/stock/entrepots', ['name' => 'Principal']);
        $premier->assertCreated()
            ->assertJsonPath('data.code', "EN-{$year}-00001")
            ->assertJsonPath('data.is_default', true);

        $second = $this->withToken($token)->postJson('/api/v1/stock/entrepots', [
            'name' => 'Secondaire', 'is_default' => true,
        ]);
        $second->assertCreated()->assertJsonPath('data.is_default', true);

        // Le premier a perdu le statut par défaut.
        $liste = $this->withToken($token)->getJson('/api/v1/stock/entrepots')->json('data');
        $this->assertCount(2, $liste);
        $this->assertCount(1, array_filter($liste, fn ($e) => $e['is_default']));
    }

    public function test_entree_sortie_and_quantite_apres(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        $entree = $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'entree', 'quantite' => 100, 'note' => 'Réception initiale',
        ]);
        $entree->assertCreated()
            ->assertJsonPath('data.quantite', '100.000')
            ->assertJsonPath('data.quantite_apres', '100.000');

        $sortie = $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'sortie', 'quantite' => 30,
        ]);
        $sortie->assertCreated()
            ->assertJsonPath('data.quantite', '-30.000')
            ->assertJsonPath('data.quantite_apres', '70.000');

        $niveaux = $this->withToken($token)->getJson('/api/v1/stock/niveaux');
        $niveaux->assertOk()
            ->assertJsonPath('data.0.quantite', '70.000')
            ->assertJsonPath('data.0.valeur_achat', '4200.00'); // 70 × 60
    }

    public function test_ajustement_sets_target_quantity(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'entree', 'quantite' => 100,
        ]);

        // Inventaire : on compte 92 → delta -8.
        $ajustement = $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'ajustement', 'quantite' => 92, 'note' => 'Inventaire annuel',
        ]);
        $ajustement->assertCreated()
            ->assertJsonPath('data.quantite', '-8.000')
            ->assertJsonPath('data.quantite_apres', '92.000');

        // Ajuster à la même quantité est refusé.
        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'ajustement', 'quantite' => 92,
        ])->assertUnprocessable();
    }

    public function test_facture_validation_decrements_stock_for_products_only(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $service = $this->createProduit($token, ['name' => 'Installation', 'type' => 'service']);
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiers['id'],
            'lignes' => [
                ['produit_id' => $produit['id'], 'quantite' => 5],
                ['produit_id' => $service['id'], 'quantite' => 1],
                ['designation' => 'Ligne libre sans produit', 'quantite' => 2, 'prix_unitaire' => 10, 'tva_rate' => 20],
            ],
        ])->json('data');

        // Brouillon : aucun mouvement.
        $this->withToken($token)->getJson('/api/v1/stock/mouvements')
            ->assertJsonPath('meta.total', 0);

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        // Un seul mouvement : le produit physique. Service et ligne libre ignorés.
        $mouvements = $this->withToken($token)->getJson('/api/v1/stock/mouvements');
        $mouvements->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'vente')
            ->assertJsonPath('data.0.quantite', '-5.000')
            ->assertJsonPath('data.0.produit.id', $produit['id']);

        // La référence pointe le numéro définitif FA de la facture.
        $this->assertStringStartsWith('FA-', $mouvements->json('data.0.reference'));

        // L'entrepôt par défaut a été créé à la volée.
        $entrepots = $this->withToken($token)->getJson('/api/v1/stock/entrepots')->json('data');
        $this->assertCount(1, $entrepots);
        $this->assertSame('Entrepôt principal', $entrepots[0]['name']);
        $this->assertTrue($entrepots[0]['is_default']);

        // Stock négatif autorisé (pas de réception préalable).
        $niveaux = $this->withToken($token)->getJson('/api/v1/stock/niveaux');
        $niveaux->assertJsonPath('data.0.quantite', '-5.000');
    }

    public function test_services_cannot_have_manual_movements(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $service = $this->createProduit($token, ['name' => 'Conseil', 'type' => 'service']);
        $entrepot = $this->createEntrepot($token);

        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $service['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'entree', 'quantite' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('produit_id');
    }

    public function test_entrepot_with_movements_cannot_be_deleted(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'entree', 'quantite' => 10,
        ])->assertCreated();

        $this->withToken($token)->deleteJson("/api/v1/stock/entrepots/{$entrepot['id']}")
            ->assertUnprocessable();
    }

    public function test_stock_is_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $produitA = $this->createProduit($tokenA);
        $entrepotA = $this->createEntrepot($tokenA);

        $this->withToken($tokenA)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produitA['id'], 'entrepot_id' => $entrepotA['id'],
            'type' => 'entree', 'quantite' => 50,
        ])->assertCreated();

        // Tenant B ne voit ni les mouvements, ni les entrepôts, ni les niveaux.
        $this->withToken($tokenB)->getJson('/api/v1/stock/mouvements')->assertJsonPath('meta.total', 0);
        $this->assertCount(0, $this->withToken($tokenB)->getJson('/api/v1/stock/entrepots')->json('data'));
        $this->withToken($tokenB)->getJson('/api/v1/stock/niveaux')->assertJsonPath('meta.total', 0);

        // Et ne peut pas créer de mouvement sur l'entrepôt du tenant A.
        $produitB = $this->createProduit($tokenB, ['name' => 'Produit B']);
        $this->withToken($tokenB)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produitB['id'], 'entrepot_id' => $entrepotA['id'],
            'type' => 'entree', 'quantite' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('entrepot_id');
    }

    public function test_niveaux_can_be_filtered_by_entrepot(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $depot1 = $this->createEntrepot($token, 'Dépôt 1');
        $depot2 = $this->createEntrepot($token, 'Dépôt 2');

        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $depot1['id'], 'type' => 'entree', 'quantite' => 30,
        ]);
        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $depot2['id'], 'type' => 'entree', 'quantite' => 12,
        ]);

        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertJsonPath('data.0.quantite', '42.000');

        $this->withToken($token)->getJson("/api/v1/stock/niveaux?entrepot_id={$depot2['id']}")
            ->assertJsonPath('data.0.quantite', '12.000');
    }
}
