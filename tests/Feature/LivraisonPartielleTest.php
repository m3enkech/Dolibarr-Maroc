<?php

namespace Tests\Feature;

use App\Modules\Catalogue\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Livraison partielle : une commande part en plusieurs bons de livraison, le
 * reste à livrer étant suivi ligne à ligne jusqu'au solde.
 */
class LivraisonPartielleTest extends TestCase
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

    private function produit(string $nom = 'Sac 50kg', float $prix = 100): Produit
    {
        return Produit::create([
            'code' => 'PR-'.uniqid(), 'name' => $nom, 'type' => 'product',
            'sell_price' => $prix, 'buy_price' => 70, 'tva_rate' => 20, 'is_active' => true,
        ]);
    }

    /** Commande validée de 100 sacs. */
    private function commande(Produit $produit, float $quantite = 100): array
    {
        $client = $this->withToken($this->token)
            ->postJson('/api/v1/tiers', ['name' => 'Chantier Nord'])->json('data');

        $doc = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => $quantite]],
        ])->assertCreated()->json('data');

        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$doc['id']}/valider")->assertOk();

        return $this->withToken($this->token)->getJson("/api/v1/ventes/documents/{$doc['id']}")->json('data');
    }

    private function stocker(Produit $produit, float $quantite): void
    {
        $entrepot = $this->withToken($this->token)
            ->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');
        $this->withToken($this->token)->postJson('/api/v1/stock/mouvements', [
            'type' => 'entree', 'produit_id' => $produit->id,
            'entrepot_id' => $entrepot['id'], 'quantite' => $quantite,
        ])->assertCreated();
    }

    /* ---------------------------------------------------------------- */

    public function test_livraison_en_deux_fois_solde_le_reliquat(): void
    {
        $produit = $this->produit();
        $this->stocker($produit, 200);
        $commande = $this->commande($produit, 100);
        $ligneId = $commande['lignes'][0]['id'];

        // 1re livraison : 60 sur 100.
        $bl1 = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 60]],
        ])->assertSuccessful();

        $bl1->assertJsonPath('data.type', 'bon_livraison')
            ->assertJsonPath('data.lignes.0.quantite', '60.000')
            ->assertJsonPath('data.total_ht', '6000.00');

        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$bl1->json('data.id')}/valider")->assertOk();

        // La commande porte 60 livrés, 40 en reliquat, état « partielle ».
        $apres = $this->withToken($this->token)->getJson("/api/v1/ventes/documents/{$commande['id']}")->assertOk();
        $apres->assertJsonPath('data.lignes.0.quantite_livree', '60.000')
            ->assertJsonPath('data.lignes.0.reste_a_livrer', '40.000')
            ->assertJsonPath('data.livraison', 'partielle');

        // 2e livraison : le solde.
        $bl2 = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 40]],
        ])->assertSuccessful();
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$bl2->json('data.id')}/valider")->assertOk();

        $solde = $this->withToken($this->token)->getJson("/api/v1/ventes/documents/{$commande['id']}")->assertOk();
        $solde->assertJsonPath('data.lignes.0.reste_a_livrer', '0.000')
            ->assertJsonPath('data.livraison', 'complete');
    }

    public function test_sur_livraison_refusee(): void
    {
        $produit = $this->produit();
        $commande = $this->commande($produit, 100);
        $ligneId = $commande['lignes'][0]['id'];

        // Plus que commandé d'emblée.
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 120]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lignes');

        // Puis au-delà du reliquat après une 1re livraison.
        $bl = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 80]],
        ])->assertSuccessful();
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$bl->json('data.id')}/valider")->assertOk();

        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 30]], // reste 20
        ])->assertUnprocessable()->assertJsonValidationErrors('lignes');
    }

    public function test_le_stock_ne_sort_que_du_livre(): void
    {
        $produit = $this->produit();
        $this->stocker($produit, 200);
        $commande = $this->commande($produit, 100);
        $ligneId = $commande['lignes'][0]['id'];

        $bl = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 60]],
        ])->assertSuccessful();
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$bl->json('data.id')}/valider")->assertOk();

        // 200 − 60 = 140 : les 40 en reliquat n'ont pas bougé.
        $niveaux = $this->withToken($this->token)->getJson('/api/v1/stock/niveaux')->json('data');
        $this->assertSame('140.000', collect($niveaux)->firstWhere('produit_id', $produit->id)['quantite']);
    }

    public function test_livraison_complete_par_transformation_solde_aussi_le_reliquat(): void
    {
        $produit = $this->produit();
        $this->stocker($produit, 200);
        $commande = $this->commande($produit, 100);

        // Chemin historique : transformer toute la commande en BL.
        $bl = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/transformer", [
            'type' => 'bon_livraison',
        ])->assertSuccessful();
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$bl->json('data.id')}/valider")->assertOk();

        $this->withToken($this->token)->getJson("/api/v1/ventes/documents/{$commande['id']}")
            ->assertJsonPath('data.lignes.0.reste_a_livrer', '0.000')
            ->assertJsonPath('data.livraison', 'complete');
    }

    public function test_livraison_partielle_multi_lignes(): void
    {
        $ciment = $this->produit('Ciment', 100);
        $sable = $this->produit('Sable', 50);
        $client = $this->withToken($this->token)
            ->postJson('/api/v1/tiers', ['name' => 'Chantier'])->json('data');

        $doc = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $client['id'],
            'lignes' => [
                ['produit_id' => $ciment->id, 'quantite' => 100],
                ['produit_id' => $sable->id, 'quantite' => 40],
            ],
        ])->assertCreated()->json('data');
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$doc['id']}/valider")->assertOk();

        $commande = $this->withToken($this->token)->getJson("/api/v1/ventes/documents/{$doc['id']}")->json('data');

        // On ne livre que le sable, en totalité ; le ciment attend.
        $bl = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$doc['id']}/livrer", [
            'lignes' => [
                ['source_ligne_id' => $commande['lignes'][0]['id'], 'quantite' => 0],
                ['source_ligne_id' => $commande['lignes'][1]['id'], 'quantite' => 40],
            ],
        ])->assertSuccessful();

        // Le BL ne porte que la ligne réellement livrée.
        $this->assertCount(1, $bl->json('data.lignes'));
        $bl->assertJsonPath('data.lignes.0.designation', 'Sable');

        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$bl->json('data.id')}/valider")->assertOk();

        $apres = $this->withToken($this->token)->getJson("/api/v1/ventes/documents/{$doc['id']}")->assertOk();
        $apres->assertJsonPath('data.lignes.0.reste_a_livrer', '100.000') // ciment intact
            ->assertJsonPath('data.lignes.1.reste_a_livrer', '0.000')     // sable soldé
            ->assertJsonPath('data.livraison', 'partielle');
    }

    public function test_livraison_refusee_sur_commande_non_validee(): void
    {
        $produit = $this->produit();
        $client = $this->withToken($this->token)
            ->postJson('/api/v1/tiers', ['name' => 'X'])->json('data');

        $doc = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 10]],
        ])->assertCreated()->json('data');

        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$doc['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $doc['lignes'][0]['id'], 'quantite' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('statut');
    }
}
