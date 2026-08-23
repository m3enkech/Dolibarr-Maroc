<?php

namespace Tests\Feature;

use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitTarif;
use App\Modules\Catalogue\Services\TarifService;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moteur tarifaire du grossiste : prix par catégorie (gros / demi-gros / détail),
 * prix négocié par client, et paliers dégressifs par quantité.
 */
class TarifGrossisteTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Grossiste Test', 'name' => 'Admin',
            'email' => 'a@gros.ma', 'password' => 'password123',
        ])->json('token');

        // Contexte tenant pour manipuler les modèles directement.
        $tenant = \App\Models\User::withoutGlobalScopes()->where('email', 'a@gros.ma')->first()->tenant;
        app(\App\Core\Tenancy\TenantContext::class)->set($tenant);
    }

    private function produit(float $prix = 100): Produit
    {
        return Produit::create([
            'code' => 'PR-'.uniqid(), 'name' => 'Sac de ciment', 'type' => 'product',
            'sell_price' => $prix, 'buy_price' => 60, 'tva_rate' => 20, 'is_active' => true,
        ]);
    }

    public function test_prix_catalogue_quand_aucun_tarif(): void
    {
        $produit = $this->produit(100);
        $client = Tiers::create(['code' => 'CL-1', 'name' => 'Client', 'is_client' => true]);

        $this->assertSame(100.0, app(TarifService::class)->prixPour($produit, $client, 1));
    }

    public function test_categorie_tarifaire_du_client_sapplique(): void
    {
        $produit = $this->produit(100);
        $gros = CategorieTarifaire::create(['name' => 'Gros']);
        $client = Tiers::create(['code' => 'CL-1', 'name' => 'Grossiste', 'is_client' => true,
            'categorie_tarifaire_id' => $gros->id]);

        ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
            'quantite_min' => 1, 'prix' => 80]);

        $this->assertSame(80.0, app(TarifService::class)->prixPour($produit, $client, 1));
    }

    public function test_paliers_degressifs_par_quantite(): void
    {
        $produit = $this->produit(100);
        $gros = CategorieTarifaire::create(['name' => 'Gros']);
        $client = Tiers::create(['code' => 'CL-1', 'name' => 'G', 'is_client' => true,
            'categorie_tarifaire_id' => $gros->id]);

        foreach ([[1, 90], [50, 80], [200, 70]] as [$qte, $prix]) {
            ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
                'quantite_min' => $qte, 'prix' => $prix]);
        }

        $service = app(TarifService::class);
        $this->assertSame(90.0, $service->prixPour($produit, $client, 10));   // palier 1
        $this->assertSame(80.0, $service->prixPour($produit, $client, 50));   // palier 50 atteint
        $this->assertSame(80.0, $service->prixPour($produit, $client, 199));
        $this->assertSame(70.0, $service->prixPour($produit, $client, 200));  // palier 200
    }

    public function test_prix_negocie_client_prime_sur_sa_categorie(): void
    {
        $produit = $this->produit(100);
        $gros = CategorieTarifaire::create(['name' => 'Gros']);
        $client = Tiers::create(['code' => 'CL-1', 'name' => 'Fidèle', 'is_client' => true,
            'categorie_tarifaire_id' => $gros->id]);

        ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
            'quantite_min' => 1, 'prix' => 80]);
        ProduitTarif::create(['produit_id' => $produit->id, 'tiers_id' => $client->id,
            'quantite_min' => 1, 'prix' => 72]);

        $this->assertSame(72.0, app(TarifService::class)->prixPour($produit, $client, 1));
    }

    public function test_categorie_par_defaut_pour_client_sans_categorie(): void
    {
        $produit = $this->produit(100);
        $detail = CategorieTarifaire::create(['name' => 'Détail', 'is_default' => true]);
        $client = Tiers::create(['code' => 'CL-1', 'name' => 'Passant', 'is_client' => true]);

        ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $detail->id,
            'quantite_min' => 1, 'prix' => 95]);

        $this->assertSame(95.0, app(TarifService::class)->prixPour($produit, $client, 1));
    }

    public function test_facture_applique_le_tarif_du_client_sans_prix_saisi(): void
    {
        $gros = CategorieTarifaire::create(['name' => 'Gros']);
        $produit = $this->produit(100);
        ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
            'quantite_min' => 10, 'prix' => 75]);

        $client = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Dépôt Sud', 'categorie_tarifaire_id' => $gros->id,
        ])->assertCreated()->json('data');

        // Aucun prix_unitaire envoyé : le serveur applique le tarif (20 ≥ palier 10).
        $doc = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 20]],
        ])->assertCreated();

        $doc->assertJsonPath('data.lignes.0.prix_unitaire', '75.00')
            ->assertJsonPath('data.total_ht', '1500.00'); // 20 × 75
    }

    public function test_la_caisse_applique_le_tarif_du_client(): void
    {
        $gros = CategorieTarifaire::create(['name' => 'Gros']);
        $produit = $this->produit(100); // 100 HT → 120 TTC au catalogue
        ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
            'quantite_min' => 10, 'prix' => 80]);

        $client = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Épicerie Nour', 'categorie_tarifaire_id' => $gros->id,
        ])->json('data');

        $this->withToken($this->token)->postJson('/api/v1/pos/session/ouvrir', ['fond_caisse' => 0])->assertCreated();

        // 12 unités au tarif gros (80) = 960 HT → 1152 TTC. Aucun prix envoyé.
        $vente = $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 12]],
            'paiements' => [['mode' => 'especes', 'montant' => 1152]],
        ]);

        $vente->assertCreated()
            ->assertJsonPath('data.lignes.0.prix_unitaire', '80.00')
            ->assertJsonPath('data.total_ttc', '1152.00')
            ->assertJsonPath('data.tiers.name', 'Épicerie Nour');
    }

    public function test_la_grille_client_est_exposee_a_la_caisse(): void
    {
        $gros = CategorieTarifaire::create(['name' => 'Gros']);
        $produit = $this->produit(100);
        foreach ([[1, 90], [50, 80]] as [$qte, $prix]) {
            ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
                'quantite_min' => $qte, 'prix' => $prix]);
        }

        $client = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Dépôt', 'categorie_tarifaire_id' => $gros->id,
        ])->json('data');

        $grille = $this->withToken($this->token)
            ->getJson("/api/v1/tarifs/grille?tiers_id={$client['id']}")->assertOk();

        $grille->assertJsonPath('data.0.produit_id', $produit->id)
            ->assertJsonPath('data.0.paliers.0.quantite_min', 1)
            ->assertJsonPath('data.0.paliers.0.prix', '90.00')
            ->assertJsonPath('data.0.paliers.1.quantite_min', 50)
            ->assertJsonPath('data.0.paliers.1.prix', '80.00');
    }

    public function test_prix_saisi_explicitement_prime_sur_le_tarif(): void
    {
        $gros = CategorieTarifaire::create(['name' => 'Gros']);
        $produit = $this->produit(100);
        ProduitTarif::create(['produit_id' => $produit->id, 'categorie_tarifaire_id' => $gros->id,
            'quantite_min' => 1, 'prix' => 75]);

        $client = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Négocié', 'categorie_tarifaire_id' => $gros->id,
        ])->json('data');

        $doc = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire' => 68]],
        ])->assertCreated();

        $doc->assertJsonPath('data.lignes.0.prix_unitaire', '68.00');
    }
}
