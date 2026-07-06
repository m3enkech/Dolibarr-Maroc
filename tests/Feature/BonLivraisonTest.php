<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bons de livraison : sortie de stock à la livraison, règle « le stock bouge
 * une seule fois » (la facture émise depuis un BL ne resort pas le stock),
 * transformations de la chaîne commerciale, PDF.
 */
class BonLivraisonTest extends TestCase
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

    private function createProduit(string $token): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'buy_price' => 60, 'tva_rate' => 20,
        ])->json('data');
    }

    private function entrer(string $token, int $produitId, float $quantite): void
    {
        $entrepot = $this->withToken($token)->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');
        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produitId, 'entrepot_id' => $entrepot['id'], 'type' => 'entree', 'quantite' => $quantite,
        ])->assertCreated();
    }

    private function creerBL(string $token, int $tiersId, int $produitId, float $qte): array
    {
        return $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'bon_livraison', 'tiers_id' => $tiersId,
            'lignes' => [['produit_id' => $produitId, 'quantite' => $qte]],
        ])->json('data');
    }

    public function test_bon_livraison_gets_bl_code_and_moves_stock_on_validation(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $this->entrer($token, $produit['id'], 100);

        $bl = $this->creerBL($token, $tiers['id'], $produit['id'], 30);
        $this->assertStringStartsWith('BL-', $bl['code']);
        $this->assertSame('brouillon', $bl['statut']);

        // Brouillon : le stock n'a pas bougé (toujours 100).
        $this->withToken($token)->getJson('/api/v1/stock/niveaux')->assertJsonPath('data.0.quantite', '100.000');

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$bl['id']}/valider")->assertOk();

        // Validé : 30 sortis → 70. Mouvement référencé sur le BL.
        $this->withToken($token)->getJson('/api/v1/stock/niveaux')->assertJsonPath('data.0.quantite', '70.000');
        $mouvements = $this->withToken($token)->getJson('/api/v1/stock/mouvements');
        $mouvements->assertJsonPath('meta.total', 2) // entrée initiale + sortie BL
            ->assertJsonPath('data.0.quantite', '-30.000');
        $this->assertStringStartsWith('BL-', $mouvements->json('data.0.reference'));

        // Un BL ne génère aucune écriture comptable.
        $this->withToken($token)->getJson('/api/v1/compta/ecritures')->assertJsonPath('meta.total', 0);
    }

    public function test_stock_moves_only_once_from_bl_to_facture(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $this->entrer($token, $produit['id'], 100);

        $bl = $this->creerBL($token, $tiers['id'], $produit['id'], 30);
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$bl['id']}/valider")->assertOk();

        // Transformer le BL en facture puis la valider.
        $facture = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$bl['id']}/transformer", [
            'type' => 'facture',
        ]);
        $facture->assertOk()->assertJsonPath('data.type', 'facture')->assertJsonPath('data.source.code', $bl['code']);
        $factureId = $facture->json('data.id');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$factureId}/valider")->assertOk();

        // Le stock N'a PAS rebougé : toujours 70 (une seule sortie, au BL).
        $this->withToken($token)->getJson('/api/v1/stock/niveaux')->assertJsonPath('data.0.quantite', '70.000');
        $this->withToken($token)->getJson('/api/v1/stock/mouvements')->assertJsonPath('meta.total', 2);

        // Mais la facture, elle, génère bien son écriture comptable (VT).
        $ecritures = $this->withToken($token)->getJson('/api/v1/compta/ecritures');
        $ecritures->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.journal', 'VT');
    }

    public function test_direct_facture_still_moves_stock(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $this->entrer($token, $produit['id'], 100);

        // Facture directe (sans BL) : le stock sort normalement.
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $tiers['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 10]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        $this->withToken($token)->getJson('/api/v1/stock/niveaux')->assertJsonPath('data.0.quantite', '90.000');
    }

    public function test_transformation_chain(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');

        // devis validé → BL autorisé.
        $devis = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'Étude', 'quantite' => 1, 'prix_unitaire' => 500, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/valider")->assertOk();

        $bl = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/transformer", [
            'type' => 'bon_livraison',
        ]);
        $bl->assertOk()->assertJsonPath('data.type', 'bon_livraison');
        $this->assertStringStartsWith('BL-', $bl->json('data.code'));

        // Un BL non validé ne peut pas encore devenir facture.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$bl->json('data.id')}/transformer", [
            'type' => 'facture',
        ])->assertUnprocessable();
    }

    public function test_bon_livraison_pdf_is_generated(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $this->entrer($token, $produit['id'], 100);
        $bl = $this->creerBL($token, $tiers['id'], $produit['id'], 5);

        $response = $this->withToken($token)->get("/api/v1/ventes/documents/{$bl['id']}/pdf");
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
