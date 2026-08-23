<?php

namespace Tests\Feature;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitConditionnement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conditionnements (carton, palette) : on vend au colis, le stock et la
 * comptabilité restent tenus en unité de base.
 */
class ConditionnementTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Grossiste', 'name' => 'Admin',
            'email' => 'a@gros.ma', 'password' => 'password123',
        ])->json('token');

        $tenant = \App\Models\User::withoutGlobalScopes()->where('email', 'a@gros.ma')->first()->tenant;
        app(\App\Core\Tenancy\TenantContext::class)->set($tenant);
    }

    /** Article vendu 10 HT la pièce, stocké à la pièce. */
    private function produit(): Produit
    {
        return Produit::create([
            'code' => 'PR-'.uniqid(), 'name' => 'Bouteille 1L', 'type' => 'product', 'unit' => 'pièce',
            'sell_price' => 10, 'buy_price' => 7, 'tva_rate' => 20, 'is_active' => true,
        ]);
    }

    private function client(): array
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/tiers', ['name' => 'Épicerie'])->json('data');
    }

    public function test_vente_au_carton_convertit_en_unite_de_stock(): void
    {
        $produit = $this->produit();
        $carton = ProduitConditionnement::create([
            'produit_id' => $produit->id, 'nom' => 'Carton de 12', 'quantite_base' => 12,
        ]);
        $client = $this->client();

        // 5 cartons de 12 = 60 pièces à 10 HT = 600 HT / 720 TTC.
        $doc = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [[
                'produit_id' => $produit->id,
                'conditionnement_id' => $carton->id,
                'quantite_colis' => 5,
            ]],
        ])->assertCreated();

        $doc->assertJsonPath('data.lignes.0.quantite', '60.000')
            ->assertJsonPath('data.lignes.0.quantite_colis', '5.000')
            ->assertJsonPath('data.lignes.0.conditionnement', 'Carton de 12')
            ->assertJsonPath('data.total_ht', '600.00')
            ->assertJsonPath('data.total_ttc', '720.00');
    }

    public function test_le_stock_sort_en_unite_de_base(): void
    {
        $produit = $this->produit();
        $carton = ProduitConditionnement::create([
            'produit_id' => $produit->id, 'nom' => 'Carton de 12', 'quantite_base' => 12,
        ]);
        $client = $this->client();

        // Stock initial de 100 pièces.
        $entrepot = $this->withToken($this->token)
            ->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');
        $this->withToken($this->token)->postJson('/api/v1/stock/mouvements', [
            'type' => 'entree', 'produit_id' => $produit->id,
            'entrepot_id' => $entrepot['id'], 'quantite' => 100,
        ])->assertCreated();

        $doc = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'conditionnement_id' => $carton->id, 'quantite_colis' => 3]],
        ])->assertCreated();

        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$doc->json('data.id')}/valider")->assertOk();

        // 3 cartons = 36 pièces sorties : il reste 64.
        $niveaux = $this->withToken($this->token)->getJson('/api/v1/stock/niveaux')->json('data');
        $ligne = collect($niveaux)->firstWhere('produit_id', $produit->id);
        $this->assertSame('64.000', $ligne['quantite']);
    }

    public function test_vente_a_l_unite_toujours_possible(): void
    {
        $produit = $this->produit();
        ProduitConditionnement::create([
            'produit_id' => $produit->id, 'nom' => 'Carton de 12', 'quantite_base' => 12,
        ]);
        $client = $this->client();

        // Sans conditionnement, rien ne change : 7 pièces = 70 HT.
        $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 7]],
        ])->assertCreated()
            ->assertJsonPath('data.lignes.0.quantite', '7.000')
            ->assertJsonPath('data.lignes.0.conditionnement', null)
            ->assertJsonPath('data.total_ht', '70.00');
    }

    public function test_le_tarif_degressif_se_base_sur_la_quantite_convertie(): void
    {
        $produit = $this->produit();
        $carton = ProduitConditionnement::create([
            'produit_id' => $produit->id, 'nom' => 'Carton de 12', 'quantite_base' => 12,
        ]);

        $gros = \App\Modules\Catalogue\Models\CategorieTarifaire::create(['name' => 'Gros']);
        // Palier à partir de 50 unités : 8 DH au lieu de 10.
        \App\Modules\Catalogue\Models\ProduitTarif::create([
            'produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
            'quantite_min' => 50, 'prix' => 8,
        ]);

        $client = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Dépôt Sud', 'categorie_tarifaire_id' => $gros->id,
        ])->json('data');

        // 5 cartons = 60 unités ≥ 50 : le palier doit s'appliquer sur la
        // quantité CONVERTIE, pas sur les 5 colis.
        $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'conditionnement_id' => $carton->id, 'quantite_colis' => 5]],
        ])->assertCreated()
            ->assertJsonPath('data.lignes.0.prix_unitaire', '8.00')
            ->assertJsonPath('data.total_ht', '480.00'); // 60 × 8
    }

    public function test_code_barres_de_colis_resolu_pour_la_caisse(): void
    {
        $produit = $this->produit();
        ProduitConditionnement::create([
            'produit_id' => $produit->id, 'nom' => 'Carton de 12',
            'quantite_base' => 12, 'barcode' => '3000000000012',
        ]);

        $this->withToken($this->token)->getJson('/api/v1/conditionnements/barcode?barcode=3000000000012')
            ->assertOk()
            ->assertJsonPath('data.produit_id', $produit->id)
            ->assertJsonPath('data.quantite_base', 12)
            ->assertJsonPath('data.nom', 'Carton de 12');

        // Code inconnu : pas de résultat, pas d'erreur.
        $this->withToken($this->token)->getJson('/api/v1/conditionnements/barcode?barcode=0000')
            ->assertOk()->assertJsonPath('data', null);
    }

    public function test_gestion_des_conditionnements_d_un_article(): void
    {
        $produit = $this->produit();

        $this->withToken($this->token)->postJson("/api/v1/produits/{$produit->id}/conditionnements", [
            'nom' => 'Carton de 12', 'quantite_base' => 12, 'is_default' => true,
        ])->assertCreated();

        $liste = $this->withToken($this->token)->postJson("/api/v1/produits/{$produit->id}/conditionnements", [
            'nom' => 'Palette de 600', 'quantite_base' => 600,
        ])->assertCreated();

        // Triés par contenance croissante.
        $this->assertSame('Carton de 12', $liste->json('data.0.nom'));
        $this->assertSame('Palette de 600', $liste->json('data.1.nom'));

        $id = $liste->json('data.1.id');
        $this->withToken($this->token)->deleteJson("/api/v1/conditionnements/{$id}")->assertOk();
        $this->assertCount(1, $this->withToken($this->token)
            ->getJson("/api/v1/produits/{$produit->id}/conditionnements")->json('data'));
    }

    public function test_conditionnement_d_un_autre_tenant_inaccessible(): void
    {
        $produit = $this->produit();
        $carton = ProduitConditionnement::create([
            'produit_id' => $produit->id, 'nom' => 'Carton', 'quantite_base' => 12,
        ]);

        $autre = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Intrus', 'name' => 'X', 'email' => 'x@i.ma', 'password' => 'password123',
        ])->json('token');

        $this->withToken($autre)->deleteJson("/api/v1/conditionnements/{$carton->id}")->assertNotFound();
    }
}
