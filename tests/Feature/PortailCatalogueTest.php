<?php

namespace Tests\Feature;

use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitConditionnement;
use App\Modules\Catalogue\Models\ProduitTarif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MontePortail;
use Tests\TestCase;

/**
 * Catalogue du portail : chaque acheteur voit les articles de SON grossiste, à
 * SON tarif négocié.
 */
class PortailCatalogueTest extends TestCase
{
    use MontePortail, RefreshDatabase;

    /* ---------------------------------------------------------------- */

    public function test_l_acheteur_voit_le_catalogue_du_grossiste(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');

        $this->withToken($g['token'])->postJson('/api/v1/produits', [
            'name' => 'Bouteille 1,5L', 'type' => 'product', 'sell_price' => 6, 'tva_rate' => 20, 'unit' => 'pièce',
        ])->assertCreated();

        $catalogue = $this->withToken($token)
            ->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")->assertOk();

        $catalogue->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Bouteille 1,5L')
            ->assertJsonPath('data.0.prix_ht', '6.00')
            ->assertJsonPath('data.0.prix_ttc', '7.20');
    }

    public function test_chaque_acheteur_voit_son_propre_tarif(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');

        $produit = $this->withToken($g['token'])->postJson('/api/v1/produits', [
            'name' => 'Sac ciment', 'type' => 'product', 'sell_price' => 100, 'tva_rate' => 20,
        ])->json('data');

        // Un niveau « Gros » à 80, appliqué au seul premier acheteur.
        $tenant = \App\Models\User::withoutGlobalScopes()->where('email', 'a@gros.ma')->first()->tenant;
        app(\App\Core\Tenancy\TenantContext::class)->runAs($tenant, function () use ($produit) {
            $gros = CategorieTarifaire::create(['name' => 'Gros']);
            ProduitTarif::create([
                'produit_id' => $produit['id'], 'categorie_tarifaire_id' => $gros->id,
                'quantite_min' => 1, 'prix' => 80,
            ]);
            ProduitTarif::create([
                'produit_id' => $produit['id'], 'categorie_tarifaire_id' => $gros->id,
                'quantite_min' => 50, 'prix' => 70,
            ]);
        });

        $categorieGros = $this->withToken($g['token'])->getJson('/api/v1/categories-tarifaires')->json('data.0.id');

        // Acheteur 1 : compte client au tarif Gros.
        $clientGros = $this->withToken($g['token'])->postJson('/api/v1/tiers', [
            'name' => 'Gros Client', 'categorie_tarifaire_id' => $categorieGros,
        ])->json('data');
        $t1 = $this->acheteur('gros@test.ma', 'Gros Client');
        $this->rattacher($t1, $g, 'gros@test.ma', $clientGros['id']);

        // Acheteur 2 : compte client sans niveau → prix catalogue.
        $t2 = $this->acheteur('detail@test.ma', 'Petit Client');
        $this->rattacher($t2, $g, 'detail@test.ma');

        $this->withToken($t1)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")
            ->assertJsonPath('data.0.prix_ht', '80.00')
            ->assertJsonPath('data.0.paliers.0.prix', '80.00')
            ->assertJsonPath('data.0.paliers.1.quantite_min', 50)
            ->assertJsonPath('data.0.paliers.1.prix', '70.00');

        $this->withToken($t2)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")
            ->assertJsonPath('data.0.prix_ht', '100.00')
            ->assertJsonPath('data.0.paliers', []);
    }

    public function test_les_conditionnements_et_la_disponibilite_sont_exposes(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');

        $produit = $this->withToken($g['token'])->postJson('/api/v1/produits', [
            'name' => 'Bouteille', 'type' => 'product', 'sell_price' => 6, 'tva_rate' => 20,
        ])->json('data');

        $this->withToken($g['token'])->postJson("/api/v1/produits/{$produit['id']}/conditionnements", [
            'nom' => 'Carton de 12', 'quantite_base' => 12,
        ])->assertCreated();

        // Sans stock : indisponible.
        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")
            ->assertJsonPath('data.0.disponible', false)
            ->assertJsonPath('data.0.conditionnements.0.nom', 'Carton de 12')
            ->assertJsonPath('data.0.conditionnements.0.quantite_base', 12);

        // Après une entrée en stock : disponible.
        $entrepot = $this->withToken($g['token'])->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');
        $this->withToken($g['token'])->postJson('/api/v1/stock/mouvements', [
            'type' => 'entree', 'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'], 'quantite' => 100,
        ])->assertCreated();

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")
            ->assertJsonPath('data.0.disponible', true);
    }

    public function test_le_niveau_de_stock_exact_n_est_jamais_expose(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');

        $produit = $this->withToken($g['token'])->postJson('/api/v1/produits', [
            'name' => 'Bouteille', 'type' => 'product', 'sell_price' => 6, 'tva_rate' => 20,
        ])->json('data');
        $entrepot = $this->withToken($g['token'])->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');
        $this->withToken($g['token'])->postJson('/api/v1/stock/mouvements', [
            'type' => 'entree', 'produit_id' => $produit['id'], 'entrepot_id' => $entrepot['id'], 'quantite' => 1234,
        ])->assertCreated();

        $reponse = $this->withToken($token)
            ->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")->assertOk();

        // La quantité du fournisseur est une information commerciale : elle ne
        // doit apparaître nulle part dans la réponse.
        $this->assertStringNotContainsString('1234', $reponse->getContent());
        $reponse->assertJsonPath('data.0.disponible', true);
    }

    /* ---------------------------------------------------------------- */
    /* Isolation                                                         */
    /* ---------------------------------------------------------------- */

    public function test_un_acheteur_ne_voit_pas_le_catalogue_d_un_grossiste_ou_il_n_est_pas_rattache(): void
    {
        $a = $this->grossiste('Grossiste A', 'a@gros.ma');
        $b = $this->grossiste('Grossiste B', 'b@gros.ma');

        $this->withToken($b['token'])->postJson('/api/v1/produits', [
            'name' => 'Article secret de B', 'type' => 'product', 'sell_price' => 50, 'tva_rate' => 20,
        ])->assertCreated();

        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $a, 'e@test.ma'); // rattaché à A seulement

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$b['slug']}/catalogue")
            ->assertForbidden();
    }

    public function test_une_demande_en_attente_n_ouvre_pas_le_catalogue(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');

        // Demande faite, mais pas encore approuvée.
        $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $g['slug']])->assertCreated();

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")
            ->assertForbidden();
    }

    public function test_un_acces_revoque_ferme_le_catalogue(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")->assertOk();

        $id = $this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')->json('data.0.id');
        $this->withToken($g['token'])->postJson("/api/v1/portail/adhesions/{$id}/revoquer")->assertOk();

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue")->assertForbidden();
    }

    public function test_la_fiche_d_un_article_d_un_autre_grossiste_est_introuvable(): void
    {
        $a = $this->grossiste('Grossiste A', 'a@gros.ma');
        $b = $this->grossiste('Grossiste B', 'b@gros.ma');

        $produitB = $this->withToken($b['token'])->postJson('/api/v1/produits', [
            'name' => 'Article de B', 'type' => 'product', 'sell_price' => 50, 'tva_rate' => 20,
        ])->json('data');

        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $a, 'e@test.ma');

        // En passant par le slug de A (autorisé), avec l'id d'un article de B.
        $this->withToken($token)
            ->getJson("/api/portail/v1/grossistes/{$a['slug']}/catalogue/{$produitB['id']}")
            ->assertNotFound();
    }

    public function test_recherche_dans_le_catalogue(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');

        foreach ([['Bouteille eau', '611000001'], ['Sac ciment', '611000002']] as [$nom, $bc]) {
            $this->withToken($g['token'])->postJson('/api/v1/produits', [
                'name' => $nom, 'type' => 'product', 'sell_price' => 10, 'tva_rate' => 20, 'barcode' => $bc,
            ])->assertCreated();
        }

        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue?search=ciment")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Sac ciment');

        // Recherche par code-barres exact.
        $this->withToken($token)->getJson("/api/portail/v1/grossistes/{$g['slug']}/catalogue?search=611000001")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Bouteille eau');
    }

    /**
     * Régression : la fiche article est la SEULE route du portail qui repose
     * sur la résolution automatique du modèle. Tant que SubstituteBindings
     * passait avant SetTenantPortail, le produit était cherché sans entreprise
     * courante et le scope fail-closed le faisait disparaître — 404 permanent,
     * silencieux, pour tout le monde.
     */
    public function test_la_fiche_article_est_servie_et_reste_cloisonnee(): void
    {
        $a = $this->grossiste('Grossiste A', 'a@gros.ma');
        $b = $this->grossiste('Grossiste B', 'b@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $a, 'e@test.ma');

        $chezA = $this->withToken($a['token'])->postJson('/api/v1/produits', [
            'name' => 'Bouteille 1,5L', 'type' => 'product', 'sell_price' => 6, 'tva_rate' => 20,
        ])->assertCreated()->json('data');

        $chezB = $this->withToken($b['token'])->postJson('/api/v1/produits', [
            'name' => 'Article de B', 'type' => 'product', 'sell_price' => 9, 'tva_rate' => 20,
        ])->assertCreated()->json('data');

        $this->withToken($token)
            ->getJson("/api/portail/v1/grossistes/{$a['slug']}/catalogue/{$chezA['id']}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Bouteille 1,5L')
            ->assertJsonPath('data.prix_ht', '6.00');

        // L'article d'un AUTRE grossiste reste introuvable, même en connaissant
        // son identifiant : le cloisonnement ne doit rien perdre au passage.
        $this->withToken($token)
            ->getJson("/api/portail/v1/grossistes/{$a['slug']}/catalogue/{$chezB['id']}")
            ->assertNotFound();
    }
}
