<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recherche insensible à la casse.
 *
 * ⚠️ CE TEST N'A DE VALEUR QUE SOUS `phpunit.pgsql.xml`.
 *
 * SQLite replie la casse ASCII dans `LIKE`, PostgreSQL non. Le développement
 * était donc structurellement vert et la production structurellement cassée :
 * en ligne, chercher « bouteille » ne trouvait pas « Bouteille », et aucun test
 * ne pouvait l'attraper. Sur SQLite ce fichier passe avant comme après le
 * correctif ; c'est sur PostgreSQL qu'il prouve quelque chose.
 *
 *     php artisan test --configuration=phpunit.pgsql.xml --filter=RechercheInsensibleCasse
 *
 * Le correctif est `whereLike()`, qui compile en `ilike` sur PostgreSQL et en
 * `like` sur SQLite. Écrire `ilike` à la main aurait cassé toute la suite :
 * SQLite refuse ce mot-clé.
 */
class RechercheInsensibleCasseTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Tenant A', 'name' => 'Admin',
            'email' => 'a@test.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    /** @return list<string> les noms trouvés pour ce terme */
    private function chercher(string $token, string $url, string $terme, string $champ = 'name'): array
    {
        return collect($this->withToken($token)->getJson($url.'?search='.urlencode($terme))->assertOk()->json('data'))
            ->pluck($champ)->all();
    }

    /* ---------------------------------------------------------------- */

    public function test_le_catalogue_se_cherche_sans_respecter_la_casse(): void
    {
        $token = $this->registerTenant();

        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Bouteille 1,5L', 'type' => 'product', 'sell_price' => 6, 'tva_rate' => 20,
            'barcode' => 'ABC123',
        ])->assertCreated();

        foreach (['bouteille', 'BOUTEILLE', 'BoUtEiLlE'] as $terme) {
            $this->assertSame(
                ['Bouteille 1,5L'],
                $this->chercher($token, '/api/v1/produits', $terme),
                "La recherche « {$terme} » doit trouver « Bouteille 1,5L ».",
            );
        }

        // Le code-barres aussi : il est saisi en majuscules, cherché en minuscules.
        $this->assertSame(['Bouteille 1,5L'], $this->chercher($token, '/api/v1/produits', 'abc123'));
    }

    public function test_les_niveaux_de_stock_se_cherchent_sans_respecter_la_casse(): void
    {
        $token = $this->registerTenant();

        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ])->assertCreated();

        $this->assertSame(['Ciment 50kg'], $this->chercher($token, '/api/v1/stock/niveaux', 'ciment'));
        $this->assertSame(['Ciment 50kg'], $this->chercher($token, '/api/v1/stock/niveaux', 'CIMENT'));
    }

    public function test_les_tiers_se_cherchent_sans_respecter_la_casse(): void
    {
        $token = $this->registerTenant();

        $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Épicerie Zahra', 'is_client' => true,
        ])->assertCreated();

        $this->assertSame(['Épicerie Zahra'], $this->chercher($token, '/api/v1/tiers', 'zahra'));
        $this->assertSame(['Épicerie Zahra'], $this->chercher($token, '/api/v1/tiers', 'ZAHRA'));
    }

    public function test_les_documents_de_vente_se_cherchent_par_nom_de_client(): void
    {
        $token = $this->registerTenant();

        $client = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Épicerie Zahra', 'is_client' => true,
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->assertCreated();

        // La recherche passe par une relation : le correctif doit valoir aussi
        // à l'intérieur d'un whereHas.
        $this->assertCount(1, $this->withToken($token)
            ->getJson('/api/v1/ventes/documents?search=zahra')->assertOk()->json('data'));
    }

    public function test_les_documents_d_achat_se_cherchent_sans_respecter_la_casse(): void
    {
        $token = $this->registerTenant();

        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Sotrama Distribution', 'is_supplier' => true, 'is_client' => false,
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $fournisseur['id'],
            'ref_fournisseur' => 'BC-2026-ALPHA',
            'lignes' => [['designation' => 'Ciment', 'quantite' => 1, 'prix_unitaire' => 60, 'tva_rate' => 20]],
        ])->assertCreated();

        $this->assertCount(1, $this->withToken($token)
            ->getJson('/api/v1/achats/documents?search=sotrama')->assertOk()->json('data'));

        // Et sur la référence du fournisseur, saisie en majuscules.
        $this->assertCount(1, $this->withToken($token)
            ->getJson('/api/v1/achats/documents?search=alpha')->assertOk()->json('data'));
    }

    /**
     * Non-objectif, épinglé pour qu'il ne surprenne pas : `ilike` replie la
     * casse, il ne dépouille PAS les accents. Chercher « epicerie » ne trouve
     * donc pas « Épicerie ». Le jour où l'on voudra l'inverse, il faudra une
     * colonne de recherche normalisée ou l'extension `unaccent` — pas un
     * ajustement discret de cette requête.
     */
    public function test_les_accents_ne_sont_pas_ignores_et_c_est_assume(): void
    {
        $token = $this->registerTenant();

        $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Épicerie Zahra', 'is_client' => true,
        ])->assertCreated();

        $this->assertSame([], $this->chercher($token, '/api/v1/tiers', 'epicerie'));
    }
}
