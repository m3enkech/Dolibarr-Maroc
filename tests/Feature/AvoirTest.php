<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Avoirs (notes de crédit) : contrepassation comptable, retour de stock
 * (composants de kits compris), remboursement et état de TVA net.
 */
class AvoirTest extends TestCase
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

    private function createTiers(string $token): array
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client Test'])->json('data');
    }

    private function factureValidee(string $token, int $tiersId, array $lignes): array
    {
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $tiersId, 'lignes' => $lignes,
        ])->json('data');

        return $this->withToken($token)
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")
            ->json('data');
    }

    public function test_avoir_standalone_gets_av_code_and_reverses_accounting(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $avoir = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'avoir',
            'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'Remise commerciale', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ]);

        $avoir->assertCreated();
        $this->assertStringStartsWith('PROV-', $avoir->json('data.code'));

        $valide = $this->withToken($token)
            ->postJson("/api/v1/ventes/documents/{$avoir->json('data.id')}/valider");
        $valide->assertOk();
        $this->assertStringStartsWith('AV-', $valide->json('data.code'));

        // Écriture VT contrepassée : crédit 3411 (TTC), débit ventes + TVA.
        $ecritures = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures')->json('data'));
        $this->assertCount(1, $ecritures);
        $ecriture = $ecritures->first();
        $this->assertSame('VT', $ecriture['journal']);
        $this->assertStringContainsString('Avoir', $ecriture['libelle']);

        $lignes = collect($ecriture['lignes']);
        $clients = $lignes->firstWhere('compte_code', '3421');
        $this->assertSame('120.00', $clients['credit']);
        $tva = $lignes->firstWhere('compte_code', '4455');
        $this->assertSame('20.00', $tva['debit']);
    }

    public function test_facture_can_be_transformed_into_avoir(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);
        $produit = $this->createProduit($token);

        $facture = $this->factureValidee($token, $tiers['id'], [
            ['produit_id' => $produit['id'], 'quantite' => 2],
        ]);

        $avoir = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/transformer", [
            'type' => 'avoir',
        ]);

        $avoir->assertOk()
            ->assertJsonPath('data.type', 'avoir')
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.total_ttc', '204.00')
            ->assertJsonPath('data.source.code', $facture['code']);
        $this->assertCount(1, $avoir->json('data.lignes'));

        // Un devis validé ne peut pas devenir un avoir.
        $devis = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 10, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/valider");
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/transformer", [
            'type' => 'avoir',
        ])->assertUnprocessable();
    }

    public function test_avoir_returns_stock_including_kit_components(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);
        $ciment = $this->createProduit($token);
        $entrepot = $this->withToken($token)->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');
        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $ciment['id'], 'entrepot_id' => $entrepot['id'], 'type' => 'entree', 'quantite' => 50,
        ]);

        $kit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Pack duo', 'type' => 'kit', 'sell_price' => 200, 'tva_rate' => 20,
            'composants' => [['produit_id' => $ciment['id'], 'quantite' => 2]],
        ])->json('data');

        // Facture : 1 pack (ciment -2) + 1 ciment seul (-1) → 47.
        $facture = $this->factureValidee($token, $tiers['id'], [
            ['produit_id' => $kit['id'], 'quantite' => 1],
            ['produit_id' => $ciment['id'], 'quantite' => 1],
        ]);

        // Avoir total sur la facture → tout revient : 50.
        $avoir = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/transformer", [
            'type' => 'avoir',
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$avoir['id']}/valider")->assertOk();

        $niveaux = collect($this->withToken($token)->getJson('/api/v1/stock/niveaux')->json('data'));
        $this->assertSame('50.000', $niveaux->firstWhere('name', 'Ciment 50kg')['quantite']);

        $retours = collect($this->withToken($token)->getJson('/api/v1/stock/mouvements?per_page=50')->json('data'))
            ->where('type', 'retour');
        $this->assertCount(2, $retours); // composant du kit + ligne directe
        $this->assertStringStartsWith('AV-', $retours->first()['reference']);
    }

    public function test_avoir_can_be_refunded(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $avoir = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'avoir', 'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'Retour article', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$avoir['id']}/valider")->assertOk();

        $remboursement = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$avoir['id']}/paiements", [
            'montant' => 120, 'mode' => 'especes',
        ]);
        $remboursement->assertOk()->assertJsonPath('data.statut', 'paye');

        // Écriture BQ inversée : débit 3411, crédit 5161, libellé Remboursement.
        $bq = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures')->json('data'))
            ->firstWhere('journal', 'BQ');
        $this->assertStringContainsString('Remboursement', $bq['libelle']);
        $lignes = collect($bq['lignes']);
        $this->assertSame('120.00', $lignes->firstWhere('compte_code', '3421')['debit']);
        $this->assertSame('120.00', $lignes->firstWhere('compte_code', '5161')['credit']);
    }

    public function test_etat_tva_nets_avoirs_against_invoices(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);
        $produit = $this->createProduit($token); // 85 HT, TVA 20

        // Facture 2 unités : TVA 34. Avoir 1 unité : TVA 17. Net attendu : 17.
        $this->factureValidee($token, $tiers['id'], [['produit_id' => $produit['id'], 'quantite' => 2]]);

        $avoir = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'avoir', 'tiers_id' => $tiers['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 1]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$avoir['id']}/valider")->assertOk();

        $this->withToken($token)->getJson('/api/v1/compta/tva?mois='.now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('tva_facturee', '17.00');
    }

    public function test_efacture_is_not_available_for_avoirs(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $avoir = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'avoir', 'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 10, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$avoir['id']}/valider");

        $this->withToken($token)->get("/api/v1/ventes/documents/{$avoir['id']}/efacture")
            ->assertNotFound();
    }
}
