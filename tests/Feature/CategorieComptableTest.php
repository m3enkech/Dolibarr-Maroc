<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorieComptableTest extends TestCase
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

    private function compteId(string $token, string $code): int
    {
        $comptes = $this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data');

        return collect($comptes)->firstWhere('code', $code)['id'];
    }

    public function test_category_requires_amort_account_when_immobilisation(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // is_immobilisation sans compte d'amortissement → 422.
        $this->withToken($token)->postJson('/api/v1/categories-produit', [
            'name' => 'Matériel info', 'is_immobilisation' => true,
            'compte_achat_id' => $this->compteId($token, '2355'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['compte_amortissement_id', 'duree_amortissement']);

        $ok = $this->withToken($token)->postJson('/api/v1/categories-produit', [
            'name' => 'Matériel info', 'is_immobilisation' => true,
            'compte_achat_id' => $this->compteId($token, '2355'),
            'compte_amortissement_id' => $this->compteId($token, '2926'),
            'duree_amortissement' => 5,
        ]);
        $ok->assertCreated()->assertJsonPath('data.is_immobilisation', true);
    }

    public function test_sale_uses_category_account_else_falls_back(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Sous-compte de vente dédié + catégorie qui le porte.
        $compte71141 = $this->withToken($token)->postJson('/api/v1/compta/comptes', [
            'code' => '71141', 'label' => 'Ventes conseil',
        ])->json('data');
        $categorie = $this->withToken($token)->postJson('/api/v1/categories-produit', [
            'name' => 'Conseil', 'compte_vente_id' => $compte71141['id'],
        ])->json('data');

        // Produit conseil (catégorie) + produit standard (sans catégorie → repli 7111).
        $conseil = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Audit', 'type' => 'service', 'sell_price' => 1000, 'tva_rate' => 20,
            'categorie_produit_id' => $categorie['id'],
        ])->json('data');
        $ciment = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment', 'type' => 'product', 'sell_price' => 500, 'tva_rate' => 20,
        ])->json('data');

        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $tiers['id'],
            'lignes' => [
                ['produit_id' => $conseil['id'], 'quantite' => 1],
                ['produit_id' => $ciment['id'], 'quantite' => 1],
            ],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        $ecriture = $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=VT')->json('data.0');
        $lignes = collect($ecriture['lignes']);

        // Conseil sur 71141, ciment sur 7111 (repli).
        $this->assertSame('1000.00', $lignes->firstWhere('compte_code', '71141')['credit']);
        $this->assertSame('500.00', $lignes->firstWhere('compte_code', '7111')['credit']);
    }

    public function test_purchase_of_immobilisation_routes_to_class2_and_3441(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $categorie = $this->withToken($token)->postJson('/api/v1/categories-produit', [
            'name' => 'Matériel informatique', 'is_immobilisation' => true,
            'compte_achat_id' => $this->compteId($token, '2355'),
            'compte_amortissement_id' => $this->compteId($token, '2926'),
            'duree_amortissement' => 5,
        ])->json('data');

        $produit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ordinateur', 'type' => 'product', 'sell_price' => 0, 'buy_price' => 10000, 'tva_rate' => 20,
            'categorie_produit_id' => $categorie['id'],
        ])->json('data');

        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur IT', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture', 'tiers_id' => $fournisseur['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 1, 'prix_unitaire' => 10000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();

        // Écriture d'achat : débit 2355 (immo) + 3441 (TVA immos, PAS 3442) / crédit 4411.
        $ecriture = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=AC')->json('data'))->first();
        $lignes = collect($ecriture['lignes']);
        $this->assertSame('10000.00', $lignes->firstWhere('compte_code', '2355')['debit']);
        $this->assertSame('2000.00', $lignes->firstWhere('compte_code', '3441')['debit']);
        $this->assertNull($lignes->firstWhere('compte_code', '3442'));       // pas de TVA sur charges
        $this->assertNull($lignes->firstWhere('compte_code', '6111'));       // pas en charges
        $this->assertSame('12000.00', $lignes->firstWhere('compte_code', '4411')['credit']);

        // L'immobilisation a été créée automatiquement, liée à la facture d'achat.
        $immos = $this->withToken($token)->getJson('/api/v1/compta/immobilisations')->json('data');
        $this->assertCount(1, $immos);
        $this->assertSame('Ordinateur', $immos[0]['label']);
        $this->assertSame('10000.00', $immos[0]['valeur_acquisition']);
        $this->assertSame(5, $immos[0]['duree_annees']);
        $this->assertSame('2355', $immos[0]['compte_immo']);
        $this->assertSame($facture['code'], $immos[0]['facture_achat']);
    }

    public function test_mixed_invoice_splits_charges_and_immobilisation(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $catImmo = $this->withToken($token)->postJson('/api/v1/categories-produit', [
            'name' => 'Mobilier', 'is_immobilisation' => true,
            'compte_achat_id' => $this->compteId($token, '2351'),
            'compte_amortissement_id' => $this->compteId($token, '2925'),
            'duree_amortissement' => 10,
        ])->json('data');

        $bureau = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Bureau', 'type' => 'product', 'sell_price' => 0, 'tva_rate' => 20,
            'categorie_produit_id' => $catImmo['id'],
        ])->json('data');
        $papier = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Papier', 'type' => 'product', 'sell_price' => 0, 'tva_rate' => 20,
        ])->json('data'); // sans catégorie → charges 6111

        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture', 'tiers_id' => $fournisseur['id'],
            'lignes' => [
                ['produit_id' => $bureau['id'], 'quantite' => 1, 'prix_unitaire' => 5000, 'tva_rate' => 20],
                ['produit_id' => $papier['id'], 'quantite' => 1, 'prix_unitaire' => 1000, 'tva_rate' => 20],
            ],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();

        $lignes = collect(collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=AC')->json('data'))->first()['lignes']);
        // Bureau → 2351 (immo), papier → 6111 (charge).
        $this->assertSame('5000.00', $lignes->firstWhere('compte_code', '2351')['debit']);
        $this->assertSame('1000.00', $lignes->firstWhere('compte_code', '6111')['debit']);
        // TVA scindée : immo 3441 = 1000, charges 3442 = 200.
        $this->assertSame('1000.00', $lignes->firstWhere('compte_code', '3441')['debit']);
        $this->assertSame('200.00', $lignes->firstWhere('compte_code', '3442')['debit']);

        // Une seule immobilisation créée (le bureau).
        $this->assertCount(1, $this->withToken($token)->getJson('/api/v1/compta/immobilisations')->json('data'));
    }

    public function test_categories_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $this->withToken($tokenA)->postJson('/api/v1/categories-produit', ['name' => 'Cat A'])->assertCreated();

        $this->assertCount(0, $this->withToken($tokenB)->getJson('/api/v1/categories-produit')->json('data'));
    }
}
