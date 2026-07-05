<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtatsSyntheseTest extends TestCase
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

    public function test_cpc_and_bilan_from_sales_are_balanced(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Vente 1000 HT / 1200 TTC encaissée par virement.
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => 1000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement',
        ])->assertOk();

        $etats = $this->withToken($token)->getJson('/api/v1/compta/etats-synthese');
        $etats->assertOk();

        // CPC : produit d'exploitation 1000 (services 7114), résultat net 1000.
        $etats->assertJsonPath('cpc.produits_exploitation', '1000.00')
            ->assertJsonPath('cpc.charges_exploitation', '0.00')
            ->assertJsonPath('cpc.resultat_exploitation', '1000.00')
            ->assertJsonPath('cpc.resultat_net', '1000.00');

        // Bilan : trésorerie-actif 1200 (banque) ; passif = TVA 200 (4441) + résultat 1000.
        $etats->assertJsonPath('bilan.tresorerie_actif', '1200.00')
            ->assertJsonPath('bilan.total_actif', '1200.00')
            ->assertJsonPath('bilan.dont_resultat_net', '1000.00')
            ->assertJsonPath('bilan.passif_circulant', '200.00')
            ->assertJsonPath('bilan.total_passif', '1200.00')
            ->assertJsonPath('bilan.equilibre', true);
    }

    public function test_cpc_with_charges_and_products(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Vente 2000 HT.
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $fv = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'Vente', 'quantite' => 1, 'prix_unitaire' => 2000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$fv['id']}/valider")->assertOk();

        // Achat 500 HT (charge).
        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');
        $fa = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture', 'tiers_id' => $fournisseur['id'],
            'lignes' => [['designation' => 'Achat', 'quantite' => 1, 'prix_unitaire' => 500, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$fa['id']}/valider")->assertOk();

        $etats = $this->withToken($token)->getJson('/api/v1/compta/etats-synthese');
        // Résultat = 2000 produits − 500 charges = 1500.
        $etats->assertJsonPath('cpc.produits_exploitation', '2000.00')
            ->assertJsonPath('cpc.charges_exploitation', '500.00')
            ->assertJsonPath('cpc.resultat_net', '1500.00')
            ->assertJsonPath('bilan.equilibre', true);
    }

    public function test_etats_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $client = $this->withToken($tokenA)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $f = $this->withToken($tokenA)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 1000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($tokenA)->postJson("/api/v1/ventes/documents/{$f['id']}/valider")->assertOk();

        // Tenant B n'a aucun mouvement : tout à zéro, équilibré.
        $this->withToken($tokenB)->getJson('/api/v1/compta/etats-synthese')
            ->assertJsonPath('cpc.resultat_net', '0.00')
            ->assertJsonPath('bilan.total_actif', '0.00')
            ->assertJsonPath('bilan.equilibre', true);
    }
}
