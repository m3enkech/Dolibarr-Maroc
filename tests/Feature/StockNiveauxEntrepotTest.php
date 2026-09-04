<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vue mono-dépôt de `/stock/niveaux`.
 *
 * Le stock détenu était filtré par entrepôt, mais pas la quantité ATTENDUE :
 * l'écran affichait donc le stock de Casablanca à côté des commandes attendues
 * à Agadir, sur la même ligne. Le lecteur en concluait que la marchandise était
 * déjà en route pour son dépôt.
 */
class StockNiveauxEntrepotTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $company, string $email): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => $company, 'name' => 'Admin', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    private function entrepot(string $token, string $nom): array
    {
        return $this->withToken($token)
            ->postJson('/api/v1/stock/entrepots', ['name' => $nom])
            ->assertCreated()->json('data');
    }

    /** Une commande fournisseur validée, donc comptée dans « en commande ». */
    private function commandeValidee(string $token, int $tiersId, int $produitId, ?int $entrepotId, float $quantite): void
    {
        $commande = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande',
            'tiers_id' => $tiersId,
            'entrepot_id' => $entrepotId,
            'lignes' => [[
                'produit_id' => $produitId, 'designation' => 'Ciment 50kg',
                'quantite' => $quantite, 'prix_unitaire' => 60, 'tva_rate' => 20,
            ]],
        ])->assertCreated()->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/achats/documents/{$commande['id']}/valider")
            ->assertOk();
    }

    private function ligneNiveaux(string $token, int $produitId, ?int $entrepotId): array
    {
        $url = '/api/v1/stock/niveaux'.($entrepotId !== null ? "?entrepot_id={$entrepotId}" : '');

        $ligne = collect($this->withToken($token)->getJson($url)->assertOk()->json('data'))
            ->firstWhere('produit_id', $produitId);

        $this->assertNotNull($ligne, 'Produit absent de la liste des niveaux.');

        return $ligne;
    }

    /* ---------------------------------------------------------------- */

    public function test_la_quantite_attendue_suit_le_depot_demande(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $casa = $this->entrepot($token, 'Casablanca');
        $agadir = $this->entrepot($token, 'Agadir');

        $produit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20, 'buy_price' => 60,
        ])->assertCreated()->json('data');

        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Cimenterie', 'is_supplier' => true, 'is_client' => false,
        ])->assertCreated()->json('data');

        // 40 unités attendues à CASABLANCA, et rien à Agadir.
        $this->commandeValidee($token, $fournisseur['id'], $produit['id'], $casa['id'], 40);

        $this->assertSame(
            '40.000',
            $this->ligneNiveaux($token, $produit['id'], $casa['id'])['en_commande'],
            'Le dépôt destinataire doit voir la quantité attendue.',
        );

        // Le cœur du correctif : Agadir n'attend rien.
        $this->assertSame(
            '0.000',
            $this->ligneNiveaux($token, $produit['id'], $agadir['id'])['en_commande'],
            "Un autre dépôt ne doit pas s'approprier une commande attendue ailleurs.",
        );

        // Toutes vues confondues, la quantité reste visible.
        $this->assertSame('40.000', $this->ligneNiveaux($token, $produit['id'], null)['en_commande']);
    }

    /**
     * Une commande sans entrepôt désigné reste comptée partout, faute de mieux :
     * c'est le pis-aller documenté dans `enCommandeSubquery()`. On le fige ici
     * pour que sa disparition soit un choix, pas un accident.
     */
    public function test_une_commande_sans_depot_reste_comptee_partout(): void
    {
        $token = $this->registerTenant('Tenant B', 'b@test.ma');

        $casa = $this->entrepot($token, 'Casablanca');
        $agadir = $this->entrepot($token, 'Agadir');

        $produit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20, 'buy_price' => 60,
        ])->assertCreated()->json('data');

        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Cimenterie', 'is_supplier' => true, 'is_client' => false,
        ])->assertCreated()->json('data');

        $this->commandeValidee($token, $fournisseur['id'], $produit['id'], null, 25);

        $this->assertSame('25.000', $this->ligneNiveaux($token, $produit['id'], $casa['id'])['en_commande']);
        $this->assertSame('25.000', $this->ligneNiveaux($token, $produit['id'], $agadir['id'])['en_commande']);
    }
}
