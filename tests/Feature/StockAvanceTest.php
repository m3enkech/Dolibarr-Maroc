<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock avancé : transferts inter-entrepôts, seuils/alertes de réappro,
 * et inventaire physique (comptage → ajustements).
 */
class StockAvanceTest extends TestCase
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

    private function createEntrepot(string $token, string $name): array
    {
        return $this->withToken($token)
            ->postJson('/api/v1/stock/entrepots', ['name' => $name])
            ->json('data');
    }

    private function entrer(string $token, int $produitId, int $entrepotId, float $quantite): void
    {
        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produitId, 'entrepot_id' => $entrepotId, 'type' => 'entree', 'quantite' => $quantite,
        ])->assertCreated();
    }

    /* ---------------------------------------------------------------- */
    /* Transferts                                                        */
    /* ---------------------------------------------------------------- */

    public function test_transfert_moves_stock_between_warehouses(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $depot1 = $this->createEntrepot($token, 'Dépôt 1');
        $depot2 = $this->createEntrepot($token, 'Dépôt 2');
        $this->entrer($token, $produit['id'], $depot1['id'], 100);

        $transfert = $this->withToken($token)->postJson('/api/v1/stock/transferts', [
            'produit_id' => $produit['id'],
            'entrepot_source_id' => $depot1['id'],
            'entrepot_dest_id' => $depot2['id'],
            'quantite' => 30,
            'note' => 'Réassort boutique',
        ]);

        $transfert->assertCreated()
            ->assertJsonPath('sortie.quantite', '-30.000')
            ->assertJsonPath('sortie.quantite_apres', '70.000')
            ->assertJsonPath('entree.quantite', '30.000')
            ->assertJsonPath('entree.quantite_apres', '30.000')
            ->assertJsonPath('sortie.type', 'transfert')
            ->assertJsonPath('entree.type', 'transfert');

        // Les deux mouvements partagent la même référence TRF-.
        $this->assertSame($transfert->json('reference'), $transfert->json('sortie.reference'));
        $this->assertStringStartsWith('TRF-', $transfert->json('reference'));

        // Niveaux par entrepôt : 70 restants côté source, 30 côté destination.
        $this->withToken($token)->getJson("/api/v1/stock/niveaux?entrepot_id={$depot1['id']}")
            ->assertJsonPath('data.0.quantite', '70.000');
        $this->withToken($token)->getJson("/api/v1/stock/niveaux?entrepot_id={$depot2['id']}")
            ->assertJsonPath('data.0.quantite', '30.000');

        // Le total tous entrepôts est inchangé.
        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertJsonPath('data.0.quantite', '100.000');
    }

    public function test_transfert_rejects_insufficient_source_stock(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $depot1 = $this->createEntrepot($token, 'Dépôt 1');
        $depot2 = $this->createEntrepot($token, 'Dépôt 2');
        $this->entrer($token, $produit['id'], $depot1['id'], 10);

        $this->withToken($token)->postJson('/api/v1/stock/transferts', [
            'produit_id' => $produit['id'],
            'entrepot_source_id' => $depot1['id'],
            'entrepot_dest_id' => $depot2['id'],
            'quantite' => 50,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantite');

        // Rien n'a bougé.
        $this->withToken($token)->getJson("/api/v1/stock/niveaux?entrepot_id={$depot1['id']}")
            ->assertJsonPath('data.0.quantite', '10.000');
    }

    public function test_transfert_rejects_same_source_and_destination(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $depot = $this->createEntrepot($token, 'Dépôt 1');

        $this->withToken($token)->postJson('/api/v1/stock/transferts', [
            'produit_id' => $produit['id'],
            'entrepot_source_id' => $depot['id'],
            'entrepot_dest_id' => $depot['id'],
            'quantite' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('entrepot_dest_id');
    }

    public function test_transfert_cannot_use_another_tenant_warehouse(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $depotA = $this->createEntrepot($tokenA, 'Dépôt A');
        $produitB = $this->createProduit($tokenB, ['name' => 'Produit B']);
        $depotB = $this->createEntrepot($tokenB, 'Dépôt B');

        $this->withToken($tokenB)->postJson('/api/v1/stock/transferts', [
            'produit_id' => $produitB['id'],
            'entrepot_source_id' => $depotB['id'],
            'entrepot_dest_id' => $depotA['id'], // entrepôt du tenant A
            'quantite' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('entrepot_dest_id');
    }

    /* ---------------------------------------------------------------- */
    /* Seuils & alertes                                                  */
    /* ---------------------------------------------------------------- */

    public function test_alertes_lists_only_products_below_their_threshold(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $depot = $this->createEntrepot($token, 'Dépôt');

        // A : sous le seuil, avec quantité cible de réappro.
        $a = $this->createProduit($token, ['name' => 'Sous seuil', 'stock_min' => 10, 'stock_reappro' => 50]);
        $this->entrer($token, $a['id'], $depot['id'], 5);

        // B : au-dessus du seuil.
        $b = $this->createProduit($token, ['name' => 'Au dessus', 'stock_min' => 10]);
        $this->entrer($token, $b['id'], $depot['id'], 20);

        // C : sans seuil, jamais alerté.
        $c = $this->createProduit($token, ['name' => 'Non suivi']);
        $this->entrer($token, $c['id'], $depot['id'], 0.5);

        // D : sous le seuil, sans quantité cible → suggestion basée sur le seuil.
        $d = $this->createProduit($token, ['name' => 'Sous seuil sans cible', 'stock_min' => 8]);
        $this->entrer($token, $d['id'], $depot['id'], 3);

        $alertes = $this->withToken($token)->getJson('/api/v1/stock/alertes');
        $alertes->assertOk();
        $data = collect($alertes->json('data'));

        $this->assertEqualsCanonicalizing(
            ['Sous seuil', 'Sous seuil sans cible'],
            $data->pluck('name')->all(),
        );

        $ligneA = $data->firstWhere('name', 'Sous seuil');
        $this->assertSame('45.000', $ligneA['suggestion']); // 50 - 5 - 0
        $this->assertSame('50.000', $ligneA['stock_reappro']);

        $ligneD = $data->firstWhere('name', 'Sous seuil sans cible');
        $this->assertSame('5.000', $ligneD['suggestion']); // 8 - 3
        $this->assertNull($ligneD['stock_reappro']);
    }

    public function test_alertes_suggestion_accounts_for_incoming_orders(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $depot = $this->createEntrepot($token, 'Dépôt');
        $produit = $this->createProduit($token, ['stock_min' => 10, 'stock_reappro' => 50]);
        $this->entrer($token, $produit['id'], $depot['id'], 5);

        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');

        // Commande fournisseur validée (non reçue) : 20 en commande.
        $commande = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande',
            'tiers_id' => $fournisseur['id'],
            'entrepot_id' => $depot['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$commande['id']}/valider")->assertOk();

        $ligne = collect($this->withToken($token)->getJson('/api/v1/stock/alertes')->json('data'))->first();
        $this->assertSame('20.000', $ligne['en_commande']);
        $this->assertSame('25.000', $ligne['suggestion']); // 50 - 5 - 20
    }

    /* ---------------------------------------------------------------- */
    /* Inventaire                                                        */
    /* ---------------------------------------------------------------- */

    public function test_inventaire_snapshots_current_levels(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $depot = $this->createEntrepot($token, 'Dépôt');
        $p1 = $this->createProduit($token, ['name' => 'Produit 1']);
        $p2 = $this->createProduit($token, ['name' => 'Produit 2']);
        $this->entrer($token, $p1['id'], $depot['id'], 100);
        $this->entrer($token, $p2['id'], $depot['id'], 50);

        $inventaire = $this->withToken($token)->postJson('/api/v1/stock/inventaires', [
            'entrepot_id' => $depot['id'], 'note' => 'Inventaire annuel',
        ]);

        $inventaire->assertCreated()
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.entrepot.id', $depot['id']);
        $this->assertStringStartsWith('INV-', $inventaire->json('data.code'));

        $lignes = collect($inventaire->json('data.lignes'));
        $this->assertCount(2, $lignes);
        $this->assertSame('100.000', $lignes->firstWhere('produit_id', $p1['id'])['quantite_theorique']);
        $this->assertNull($lignes->firstWhere('produit_id', $p1['id'])['quantite_comptee']);
    }

    public function test_inventaire_validation_generates_adjustments_only_for_gaps(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $depot = $this->createEntrepot($token, 'Dépôt');
        $p1 = $this->createProduit($token, ['name' => 'Produit 1']);
        $p2 = $this->createProduit($token, ['name' => 'Produit 2']);
        $this->entrer($token, $p1['id'], $depot['id'], 100);
        $this->entrer($token, $p2['id'], $depot['id'], 50);

        $id = $this->withToken($token)->postJson('/api/v1/stock/inventaires', [
            'entrepot_id' => $depot['id'],
        ])->json('data.id');

        // Comptage : p1 manque 8 (92 comptés), p2 conforme (50).
        $maj = $this->withToken($token)->putJson("/api/v1/stock/inventaires/{$id}", [
            'comptages' => [
                ['produit_id' => $p1['id'], 'quantite_comptee' => 92],
                ['produit_id' => $p2['id'], 'quantite_comptee' => 50],
            ],
        ]);
        $maj->assertOk();
        $lignes = collect($maj->json('data.lignes'));
        $this->assertSame('-8.000', $lignes->firstWhere('produit_id', $p1['id'])['ecart']);
        $this->assertSame('0.000', $lignes->firstWhere('produit_id', $p2['id'])['ecart']);

        $this->withToken($token)->postJson("/api/v1/stock/inventaires/{$id}/valider")
            ->assertOk()->assertJsonPath('data.statut', 'valide');

        // Le stock de p1 est ramené à 92, p2 inchangé.
        $this->withToken($token)->getJson("/api/v1/stock/niveaux?entrepot_id={$depot['id']}")
            ->assertJsonPath('data.0.quantite', '92.000')  // Produit 1
            ->assertJsonPath('data.1.quantite', '50.000'); // Produit 2

        // Un seul ajustement, référencé sur le code d'inventaire.
        $ajustements = collect($this->withToken($token)->getJson('/api/v1/stock/mouvements')->json('data'))
            ->where('type', 'ajustement');
        $this->assertCount(1, $ajustements);
        $this->assertSame('-8.000', $ajustements->first()['quantite']);
        $this->assertStringStartsWith('INV-', $ajustements->first()['reference']);
    }

    public function test_inventaire_can_add_product_found_during_count(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $depot = $this->createEntrepot($token, 'Dépôt');
        $p1 = $this->createProduit($token, ['name' => 'Produit 1']);
        $p2 = $this->createProduit($token, ['name' => 'Produit 2']);
        $this->entrer($token, $p1['id'], $depot['id'], 100);

        // L'inventaire ne fige que p1 (p2 n'a aucun stock recensé).
        $id = $this->withToken($token)->postJson('/api/v1/stock/inventaires', [
            'entrepot_id' => $depot['id'],
        ])->json('data.id');

        // On trouve 15 unités de p2 lors du comptage.
        $this->withToken($token)->putJson("/api/v1/stock/inventaires/{$id}", [
            'comptages' => [['produit_id' => $p2['id'], 'quantite_comptee' => 15]],
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/stock/inventaires/{$id}/valider")->assertOk();

        // p2 entre en stock à 15, p1 (non compté) reste à 100.
        $this->withToken($token)->getJson("/api/v1/stock/niveaux?entrepot_id={$depot['id']}")
            ->assertJsonPath('data.0.quantite', '100.000')
            ->assertJsonPath('data.1.quantite', '15.000');
    }

    public function test_validated_inventaire_is_read_only(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $depot = $this->createEntrepot($token, 'Dépôt');
        $p1 = $this->createProduit($token);
        $this->entrer($token, $p1['id'], $depot['id'], 100);

        $id = $this->withToken($token)->postJson('/api/v1/stock/inventaires', [
            'entrepot_id' => $depot['id'],
        ])->json('data.id');
        $this->withToken($token)->putJson("/api/v1/stock/inventaires/{$id}", [
            'comptages' => [['produit_id' => $p1['id'], 'quantite_comptee' => 90]],
        ])->assertOk();
        $this->withToken($token)->postJson("/api/v1/stock/inventaires/{$id}/valider")->assertOk();

        // Plus de comptage ni de suppression après validation.
        $this->withToken($token)->putJson("/api/v1/stock/inventaires/{$id}", [
            'comptages' => [['produit_id' => $p1['id'], 'quantite_comptee' => 80]],
        ])->assertUnprocessable();
        $this->withToken($token)->deleteJson("/api/v1/stock/inventaires/{$id}")->assertUnprocessable();
    }

    public function test_draft_inventaire_can_be_deleted(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $depot = $this->createEntrepot($token, 'Dépôt');
        $p1 = $this->createProduit($token);
        $this->entrer($token, $p1['id'], $depot['id'], 100);

        $id = $this->withToken($token)->postJson('/api/v1/stock/inventaires', [
            'entrepot_id' => $depot['id'],
        ])->json('data.id');

        $this->withToken($token)->deleteJson("/api/v1/stock/inventaires/{$id}")->assertOk();
        $this->withToken($token)->getJson("/api/v1/stock/inventaires/{$id}")->assertNotFound();
    }

    public function test_inventaire_is_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $depotA = $this->createEntrepot($tokenA, 'Dépôt A');
        $produitA = $this->createProduit($tokenA);
        $this->entrer($tokenA, $produitA['id'], $depotA['id'], 100);

        $id = $this->withToken($tokenA)->postJson('/api/v1/stock/inventaires', [
            'entrepot_id' => $depotA['id'],
        ])->json('data.id');

        // Tenant B ne voit pas l'inventaire du tenant A.
        $this->withToken($tokenB)->getJson('/api/v1/stock/inventaires')->assertJsonPath('meta.total', 0);
        $this->withToken($tokenB)->getJson("/api/v1/stock/inventaires/{$id}")->assertNotFound();
    }
}
