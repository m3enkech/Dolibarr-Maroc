<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce dont dépend le choix d'un article par recherche (SelecteurProduit) et le
 * catalogue complet de la caisse.
 *
 * Les écrans chargeaient les 200 ou 500 premiers articles dans une liste :
 * au-delà, un article n'était ni choisissable sur un document, ni trouvé par
 * la douchette. Ils cherchent désormais côté serveur, ou lisent le catalogue
 * page à page. À jouer AUSSI sous `phpunit.pgsql.xml` : la recherche par
 * référence et l'ordre des homonymes n'y ont pas le comportement de SQLite.
 */
class ChoixArticleTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Tenant A', 'name' => 'Admin',
            'email' => 'a@test.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    /** @return array<string, mixed> l'article créé */
    private function creerArticle(string $token, array $champs): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', $champs + [
            'type' => 'product', 'sell_price' => 10, 'tva_rate' => 20,
        ])->assertCreated()->json('data');
    }

    /** @return list<string> les noms renvoyés par cette adresse */
    private function noms(string $token, string $url): array
    {
        return collect($this->withToken($token)->getJson($url)->assertOk()->json('data'))->pluck('name')->all();
    }

    /* ---------------------------------------------------------------- */

    public function test_un_article_se_trouve_par_sa_reference_sans_respecter_la_casse(): void
    {
        $token = $this->registerTenant();
        $article = $this->creerArticle($token, ['name' => 'Ramette A4 80g']);

        // La référence est générée en majuscules (PR-2026-00001) ; on la tape
        // en minuscules. Sur PostgreSQL, un `like` sensible à la casse ne la
        // trouvait pas.
        $terme = strtolower($article['code']);
        $this->assertSame(['Ramette A4 80g'], $this->noms($token, '/api/v1/produits?search='.urlencode($terme)));
    }

    public function test_un_code_barres_exact_passe_avant_ceux_qui_le_contiennent(): void
    {
        $token = $this->registerTenant();
        $this->creerArticle($token, ['name' => 'Adaptateur USB', 'barcode' => '10015']);
        $this->creerArticle($token, ['name' => 'Câble HDMI', 'barcode' => '1001']);

        // Le sélecteur prend le PREMIER résultat quand Entrée devance la
        // réponse (douchette) : c'est l'article scanné qui doit l'être, pas le
        // premier par ordre alphabétique parmi ceux qui contiennent le code.
        $this->assertSame(['Câble HDMI', 'Adaptateur USB'], $this->noms($token, '/api/v1/produits?search=1001'));

        // Sans égard à la casse, comme la recherche elle-même.
        $this->creerArticle($token, ['name' => 'Aimant', 'barcode' => 'XK-70']);
        $this->creerArticle($token, ['name' => 'Boîte', 'barcode' => 'XK-7']);
        $this->assertSame(['Boîte', 'Aimant'], $this->noms($token, '/api/v1/produits?search=xk-7'));

        // Sans recherche, l'ordre reste alphabétique : la caisse parcourt ainsi
        // tout le catalogue page à page.
        $this->assertSame(
            ['Adaptateur USB', 'Aimant', 'Boîte', 'Câble HDMI'],
            $this->noms($token, '/api/v1/produits'),
        );
    }

    public function test_le_filtre_de_type_accepte_une_liste(): void
    {
        $token = $this->registerTenant();
        $ciment = $this->creerArticle($token, ['name' => 'Ciment 50kg']);
        $this->creerArticle($token, ['name' => 'Pose', 'type' => 'service']);
        $this->creerArticle($token, [
            'name' => 'Pack chantier', 'type' => 'kit',
            'composants' => [['produit_id' => $ciment['id'], 'quantite' => 2]],
        ]);

        // La composition d'un kit : produits ET services, jamais un kit.
        $this->assertSame(['Ciment 50kg', 'Pose'], $this->noms($token, '/api/v1/produits?type=product,service'));

        // Un seul type : comme avant.
        $this->assertSame(['Pack chantier'], $this->noms($token, '/api/v1/produits?type=kit'));

        // Un type inconnu est ignoré, seul ou dans une liste — comme avant.
        $this->assertCount(3, $this->noms($token, '/api/v1/produits?type=inconnu'));
        $this->assertSame(['Pose'], $this->noms($token, '/api/v1/produits?type=service,inconnu'));
    }

    public function test_le_catalogue_se_parcourt_page_a_page_sans_doublon_ni_oubli(): void
    {
        $token = $this->registerTenant();

        // Cinq homonymes : sans départage, PostgreSQL peut les rendre dans un
        // ordre différent d'une page à l'autre.
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->creerArticle($token, ['name' => 'Ramette A4'])['id'];
        }

        foreach (['/api/v1/produits', '/api/v1/stock/niveaux'] as $url) {
            $lus = [];
            for ($page = 1; $page <= 3; $page++) {
                $reponse = $this->withToken($token)->getJson("{$url}?per_page=2&page={$page}")->assertOk();
                $cle = $url === '/api/v1/stock/niveaux' ? 'produit_id' : 'id';
                $lus = [...$lus, ...collect($reponse->json('data'))->pluck($cle)->all()];
            }

            // Chaque article une fois et une seule, dans l'ordre de création.
            $this->assertSame($ids, $lus, "Parcours de {$url}");
        }
    }

    public function test_l_annuaire_des_tiers_se_parcourt_page_a_page_sans_doublon_ni_oubli(): void
    {
        $token = $this->registerTenant();

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->withToken($token)->postJson('/api/v1/tiers', [
                'name' => 'Épicerie Zahra', 'is_client' => true,
            ])->assertCreated()->json('data.id');
        }

        $lus = [];
        for ($page = 1; $page <= 3; $page++) {
            $lus = [...$lus, ...collect($this->withToken($token)
                ->getJson("/api/v1/tiers?type=client&per_page=2&page={$page}")->assertOk()->json('data'))
                ->pluck('id')->all()];
        }

        $this->assertSame($ids, $lus);
    }

    public function test_une_ligne_de_vente_porte_son_article_pour_le_formulaire_de_modification(): void
    {
        $token = $this->registerTenant();
        $article = $this->creerArticle($token, ['name' => 'Ramette A4 80g']);
        $client = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Épicerie Zahra', 'is_client' => true,
        ])->assertCreated()->json('data');

        $devis = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $client['id'],
            'lignes' => [
                // Désignation retouchée : c'est l'ARTICLE que le sélecteur doit
                // afficher, pas la désignation de la ligne.
                ['produit_id' => $article['id'], 'designation' => 'Ramette — livraison urgente', 'quantite' => 2],
                ['designation' => 'Transport', 'quantite' => 1, 'prix_unitaire' => 50, 'tva_rate' => 20],
            ],
        ])->assertCreated()->json('data');

        $lignes = $this->withToken($token)->getJson("/api/v1/ventes/documents/{$devis['id']}")
            ->assertOk()->json('data.lignes');

        $this->assertSame(
            ['id' => $article['id'], 'code' => $article['code'], 'name' => 'Ramette A4 80g'],
            $lignes[0]['produit'],
        );
        // Ligne libre : la clé est là, et vide.
        $this->assertArrayHasKey('produit', $lignes[1]);
        $this->assertNull($lignes[1]['produit']);

        // Article supprimé depuis : plus d'article à afficher — le formulaire
        // le relit, obtient un 404 et affiche « Article introuvable ».
        $this->withToken($token)->deleteJson("/api/v1/produits/{$article['id']}")->assertOk();
        $this->assertNull($this->withToken($token)->getJson("/api/v1/ventes/documents/{$devis['id']}")
            ->assertOk()->json('data.lignes.0.produit'));
        $this->withToken($token)->getJson("/api/v1/produits/{$article['id']}")->assertNotFound();
    }

    public function test_une_ligne_d_achat_porte_son_article_pour_le_formulaire_de_modification(): void
    {
        $token = $this->registerTenant();
        $article = $this->creerArticle($token, ['name' => 'Ciment 50kg', 'buy_price' => 60]);
        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Sotrama Distribution', 'is_supplier' => true, 'is_client' => false,
        ])->assertCreated()->json('data');

        $commande = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $fournisseur['id'],
            'lignes' => [['produit_id' => $article['id'], 'designation' => 'Ciment', 'quantite' => 3, 'prix_unitaire' => 60, 'tva_rate' => 20]],
        ])->assertCreated()->json('data');

        $this->withToken($token)->getJson("/api/v1/achats/documents/{$commande['id']}")
            ->assertOk()
            ->assertJsonPath('data.lignes.0.produit.code', $article['code'])
            ->assertJsonPath('data.lignes.0.produit.name', 'Ciment 50kg');
    }

    public function test_les_listes_de_documents_ne_chargent_pas_l_article_de_chaque_ligne(): void
    {
        $token = $this->registerTenant();
        $article = $this->creerArticle($token, ['name' => 'Ramette A4 80g']);
        $client = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Épicerie Zahra', 'is_client' => true,
        ])->assertCreated()->json('data');

        // La réponse de création n'est pas celle de show() : la clé n'y est pas,
        // preuve que seule la lecture d'UN document charge les articles.
        $cree = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $article['id'], 'quantite' => 1]],
        ])->assertCreated()->json('data');

        $this->assertArrayNotHasKey('produit', $cree['lignes'][0]);
    }
}
