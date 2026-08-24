<?php

namespace Tests\Feature;

use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Catalogue\Models\ProduitTarif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MontePortail;
use Tests\TestCase;

/**
 * Commandes passées depuis le portail : prix fixé par le serveur, commande
 * soumise à confirmation du grossiste, et suivi jusqu'à la livraison.
 */
class PortailCommandeTest extends TestCase
{
    use MontePortail, RefreshDatabase;

    private function produit(array $g, string $nom = 'Huile 5L', float $prix = 120): array
    {
        return $this->withToken($g['token'])->postJson('/api/v1/produits', [
            'name' => $nom, 'type' => 'product', 'unit' => 'bidon', 'sell_price' => $prix, 'tva_rate' => 20,
        ])->assertCreated()->json('data');
    }

    /* ---------------------------------------------------------------- */

    public function test_passer_une_commande_au_tarif_du_client(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');
        $produit = $this->produit($g);

        // Le client est classé « Gros » : 105 au lieu de 120.
        $cat = $this->withToken($g['token'])->postJson('/api/v1/categories-tarifaires', ['name' => 'Gros'])->json('data');
        $this->withToken($g['token'])->postJson("/api/v1/produits/{$produit['id']}/tarifs", [
            'categorie_tarifaire_id' => $cat['id'], 'quantite_min' => 1, 'prix' => 105,
        ])->assertCreated();
        $this->withToken($g['token'])->putJson("/api/v1/tiers/{$client}", [
            'name' => 'Épicerie', 'categorie_tarifaire_id' => $cat['id'],
        ])->assertOk();

        $reponse = $this->withToken($token)->postJson("/api/portail/v1/grossistes/{$g['slug']}/commandes", [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 10]],
            'note' => 'Livraison avant vendredi svp',
        ])->assertCreated();

        $reponse->assertJsonPath('data.etat', 'en_attente_confirmation')
            ->assertJsonPath('data.lignes.0.prix_unitaire', '105.00')
            ->assertJsonPath('data.total_ht', '1050.00')
            ->assertJsonPath('data.total_ttc', '1260.00')
            ->assertJsonPath('data.notes', 'Livraison avant vendredi svp');
    }

    public function test_le_prix_envoye_par_l_acheteur_est_ignore(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');
        $produit = $this->produit($g, 'Huile 5L', 120);

        // Tentative de commander à 1 DH l'unité.
        $this->withToken($token)->postJson("/api/portail/v1/grossistes/{$g['slug']}/commandes", [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 5, 'prix_unitaire' => 1]],
        ])->assertCreated()
            ->assertJsonPath('data.lignes.0.prix_unitaire', '120.00')
            ->assertJsonPath('data.total_ht', '600.00');
    }

    public function test_commander_au_carton(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');
        $produit = $this->produit($g, 'Huile 5L', 120);

        $cond = $this->withToken($g['token'])->postJson("/api/v1/produits/{$produit['id']}/conditionnements", [
            'nom' => 'Carton de 6', 'quantite_base' => 6,
        ])->assertCreated()->json('data.0');

        // 4 cartons de 6 = 24 unités.
        $this->withToken($token)->postJson("/api/portail/v1/grossistes/{$g['slug']}/commandes", [
            'lignes' => [['produit_id' => $produit['id'], 'conditionnement_id' => $cond['id'], 'quantite_colis' => 4]],
        ])->assertCreated()
            ->assertJsonPath('data.lignes.0.quantite', 24)
            ->assertJsonPath('data.lignes.0.quantite_colis', 4)
            ->assertJsonPath('data.lignes.0.conditionnement', 'Carton de 6')
            ->assertJsonPath('data.total_ht', '2880.00'); // 24 × 120
    }

    public function test_la_commande_arrive_chez_le_grossiste_et_suit_son_cours(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');
        $produit = $this->produit($g);

        $commande = $this->withToken($token)->postJson("/api/portail/v1/grossistes/{$g['slug']}/commandes", [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 100]],
        ])->assertCreated()->json('data');

        // Le grossiste la voit dans son ERP et la confirme.
        $this->withToken($g['token'])->getJson('/api/v1/ventes/documents?type=commande')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->withToken($g['token'])->postJson("/api/v1/ventes/documents/{$commande['id']}/valider")->assertOk();

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/commandes/{$commande['id']}")
            ->assertJsonPath('data.etat', 'confirmee')
            ->assertJsonPath('data.livraison', 'aucune');

        // Livraison partielle : 60 sur 100.
        $ligneId = $this->withToken($g['token'])->getJson("/api/v1/ventes/documents/{$commande['id']}")
            ->json('data.lignes.0.id');
        $bl = $this->withToken($g['token'])->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 60]],
        ])->assertSuccessful();
        $this->withToken($g['token'])->postJson("/api/v1/ventes/documents/{$bl->json('data.id')}/valider")->assertOk();

        // L'acheteur suit son reliquat depuis le portail.
        $suivi = $this->withToken($token)
            ->getJson("/api/portail/v1/grossistes/{$g['slug']}/commandes/{$commande['id']}")->assertOk();

        $suivi->assertJsonPath('data.etat', 'partiellement_livree')
            ->assertJsonPath('data.livraison', 'partielle')
            ->assertJsonPath('data.lignes.0.quantite_livree', 60)
            ->assertJsonPath('data.lignes.0.reste_a_livrer', 40);
    }

    /* ---------------------------------------------------------------- */
    /* Isolation — le point critique de ce lot                           */
    /* ---------------------------------------------------------------- */

    public function test_un_acheteur_ne_voit_que_ses_propres_commandes(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $produit = $this->produit($g);

        $t1 = $this->acheteur('un@test.ma', 'Épicerie Un');
        $this->rattacher($t1, $g, 'un@test.ma');
        $t2 = $this->acheteur('deux@test.ma', 'Épicerie Deux');
        $this->rattacher($t2, $g, 'deux@test.ma');

        $c1 = $this->withToken($t1)->postJson("/api/portail/v1/grossistes/{$g['slug']}/commandes", [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 10]],
        ])->assertCreated()->json('data');

        $this->withToken($t2)->postJson("/api/portail/v1/grossistes/{$g['slug']}/commandes", [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 99]],
        ])->assertCreated();

        // Chacun ne voit qu'une commande : la sienne.
        $liste1 = $this->withToken($t1)->getJson("/api/portail/v1/grossistes/{$g['slug']}/commandes")->assertOk();
        $liste1->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', $c1['code']);

        $this->withToken($t2)->getJson("/api/portail/v1/grossistes/{$g['slug']}/commandes")
            ->assertJsonPath('meta.total', 1);

        // Et l'un ne peut pas ouvrir la commande de l'autre.
        $this->withToken($t2)->getJson("/api/portail/v1/grossistes/{$g['slug']}/commandes/{$c1['id']}")
            ->assertNotFound();
    }

    public function test_les_commandes_hors_portail_du_meme_client_restent_visibles(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');
        $produit = $this->produit($g);

        // Commande saisie par le grossiste lui-même, pour ce client.
        $this->withToken($g['token'])->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $client,
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 3]],
        ])->assertCreated();

        // L'acheteur la retrouve dans son portail : c'est bien son historique.
        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/commandes")
            ->assertJsonPath('meta.total', 1);
    }

    public function test_commander_un_article_d_un_autre_grossiste_est_refuse(): void
    {
        $a = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $b = $this->grossiste('Gros Rif', 'b@gros.ma');
        $produitB = $this->produit($b, 'Article de Rif');

        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $a, 'e@test.ma');

        $this->withToken($token)->postJson("/api/portail/v1/grossistes/{$a['slug']}/commandes", [
            'lignes' => [['produit_id' => $produitB['id'], 'quantite' => 5]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lignes');
    }

    public function test_situation_du_compte_encours_et_plafond(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $this->withToken($g['token'])->putJson("/api/v1/tiers/{$client}", [
            'name' => 'Épicerie', 'plafond_credit' => 5000, 'delai_paiement_jours' => 30,
        ])->assertOk();

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/mon-compte")
            ->assertOk()
            ->assertJsonPath('data.encours', '0.00')
            ->assertJsonPath('data.plafond', '5000.00')
            ->assertJsonPath('data.disponible', '5000.00')
            ->assertJsonPath('data.delai_paiement_jours', 30);
    }
}
