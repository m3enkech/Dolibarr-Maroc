<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ventilation d'un article dépôt par dépôt : ce qu'on voit en dépliant une
 * ligne de l'écran de suivi.
 */
class StockDepotsTest extends TestCase
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

    private function produit(string $token, string $nom = 'Ciment 50kg'): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => $nom, 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20, 'buy_price' => 60,
        ])->assertCreated()->json('data');
    }

    private function fournisseur(string $token): array
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Cimenterie', 'is_supplier' => true, 'is_client' => false,
        ])->assertCreated()->json('data');
    }

    private function entree(string $token, int $produitId, int $entrepotId, float $quantite): void
    {
        $this->withToken($token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produitId, 'entrepot_id' => $entrepotId,
            'type' => 'entree', 'quantite' => $quantite,
        ])->assertCreated();
    }

    private function commandeValidee(string $token, int $tiersId, int $produitId, ?int $entrepotId, float $quantite): void
    {
        $commande = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $tiersId, 'entrepot_id' => $entrepotId,
            'lignes' => [[
                'produit_id' => $produitId, 'designation' => 'Ciment 50kg',
                'quantite' => $quantite, 'prix_unitaire' => 60, 'tva_rate' => 20,
            ]],
        ])->assertCreated()->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/achats/documents/{$commande['id']}/valider")
            ->assertOk();
    }

    /* ---------------------------------------------------------------- */

    public function test_chaque_depot_est_liste_meme_quand_il_ne_detient_rien(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $casa = $this->entrepot($token, 'Casablanca');
        $agadir = $this->entrepot($token, 'Agadir');
        $produit = $this->produit($token);

        $this->entree($token, $produit['id'], $casa['id'], 120);

        $data = $this->withToken($token)
            ->getJson("/api/v1/stock/produits/{$produit['id']}/depots")
            ->assertOk()->json('data');

        $this->assertCount(2, $data['depots']);

        $parNom = collect($data['depots'])->keyBy('name');
        $this->assertSame('120.000', $parNom['Casablanca']['quantite']);

        // Un dépôt sans ligne de stock doit dire « 0 », pas disparaître :
        // un Agadir absent poserait une question, un Agadir à zéro y répond.
        $this->assertSame('0.000', $parNom['Agadir']['quantite']);

        $this->assertSame('120.000', $data['total']['quantite']);
        $this->assertTrue($parNom['Casablanca']['is_default'], 'Le dépôt par défaut arrive en tête.');
    }

    public function test_la_ventilation_somme_au_total_de_l_ecran_agrege(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $casa = $this->entrepot($token, 'Casablanca');
        $agadir = $this->entrepot($token, 'Agadir');
        $produit = $this->produit($token);

        $this->entree($token, $produit['id'], $casa['id'], 120);
        $this->entree($token, $produit['id'], $agadir['id'], 17);

        $ventile = $this->withToken($token)
            ->getJson("/api/v1/stock/produits/{$produit['id']}/depots")
            ->assertOk()->json('data');

        $agrege = collect($this->withToken($token)->getJson('/api/v1/stock/niveaux')->json('data'))
            ->firstWhere('produit_id', $produit['id']);

        $this->assertSame($agrege['quantite'], $ventile['total']['quantite']);
        $this->assertSame('137.000', $ventile['total']['quantite']);
    }

    /**
     * Le point délicat : une commande sans dépôt désigné.
     *
     * La vue agrégée la compte dans TOUS les dépôts, faute de mieux. C'est
     * acceptable quand on somme, faux quand on ventile — chaque dépôt croirait
     * l'attendre. Elle a donc son propre seau.
     */
    public function test_une_commande_sans_depot_ne_se_duplique_pas_dans_chaque_depot(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $casa = $this->entrepot($token, 'Casablanca');
        $this->entrepot($token, 'Agadir');
        $produit = $this->produit($token);
        $fournisseur = $this->fournisseur($token);

        $this->commandeValidee($token, $fournisseur['id'], $produit['id'], $casa['id'], 40);
        $this->commandeValidee($token, $fournisseur['id'], $produit['id'], null, 25);

        $data = $this->withToken($token)
            ->getJson("/api/v1/stock/produits/{$produit['id']}/depots")
            ->assertOk()->json('data');

        $parNom = collect($data['depots'])->keyBy('name');

        $this->assertSame('40.000', $parNom['Casablanca']['en_commande'], 'Le dépôt destinataire attend sa commande.');
        $this->assertSame('0.000', $parNom['Agadir']['en_commande'], "Agadir n'attend rien.");
        $this->assertSame('25.000', $data['sans_entrepot']['en_commande'], 'La commande sans dépôt est comptée à part.');
        $this->assertSame('65.000', $data['total']['en_commande'], 'Le total les additionne toutes.');
    }

    public function test_les_derniers_mouvements_accompagnent_la_ventilation(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $casa = $this->entrepot($token, 'Casablanca');
        $produit = $this->produit($token);

        foreach ([5, 7, 11] as $q) {
            $this->entree($token, $produit['id'], $casa['id'], $q);
        }

        $mouvements = $this->withToken($token)
            ->getJson("/api/v1/stock/produits/{$produit['id']}/depots")
            ->assertOk()->json('data.derniers_mouvements');

        $this->assertCount(3, $mouvements);
        $this->assertSame('11.000', $mouvements[0]['quantite'], 'Le plus récent en tête.');
        $this->assertSame('Casablanca', $mouvements[0]['entrepot']);
    }

    /**
     * Le coût ne doit dépendre NI du nombre de dépôts NI du nombre de mouvements.
     *
     * On mesure le même appel sur deux décors très différents — 1 dépôt et
     * 1 mouvement, puis 6 dépôts et 21 mouvements — et on exige que le compte
     * ne bouge quasiment pas. Un N+1 ajouterait ici au moins cinq requêtes ;
     * la marge d'une seule ne lui laisse aucune place.
     *
     * Pourquoi une marge et non l'égalité stricte : sur PostgreSQL, une requête
     * de plus apparaît selon ce qui a tourné AVANT dans la suite, jamais selon
     * la taille du décor — le test passe seul de façon reproductible, et
     * l'écart ne grandit pas quand le décor grandit. Figer l'égalité mesurerait
     * donc l'état de la suite plutôt que le coût de cet appel.
     */
    public function test_le_cout_ne_croit_pas_avec_le_nombre_de_depots(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->produit($token);

        $petit = $this->entrepot($token, 'Dépôt 1');
        $this->entree($token, $produit['id'], $petit['id'], 10);

        $requetes = function (int $produitId) use ($token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withToken($token)->getJson("/api/v1/stock/produits/{$produitId}/depots")->assertOk();
            $compte = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $compte;
        };

        $avant = $requetes($produit['id']);

        // Le décor grossit : cinq dépôts de plus, vingt mouvements de plus.
        for ($i = 2; $i <= 6; $i++) {
            $depot = $this->entrepot($token, "Dépôt {$i}");
            for ($j = 0; $j < 4; $j++) {
                $this->entree($token, $produit['id'], $depot['id'], 3);
            }
        }

        $apres = $requetes($produit['id']);

        $this->assertLessThanOrEqual(
            $avant + 1,
            $apres,
            "Le coût de la ventilation croît avec le décor : {$avant} requêtes pour 1 dépôt, "
            ."{$apres} pour 6 dépôts et 21 mouvements. C'est un N+1.",
        );
    }

    public function test_l_article_d_une_autre_entreprise_est_introuvable(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $casa = $this->entrepot($tokenA, 'Casablanca');
        $produitA = $this->produit($tokenA);
        $this->entree($tokenA, $produitA['id'], $casa['id'], 120);

        $this->withToken($tokenB)
            ->getJson("/api/v1/stock/produits/{$produitA['id']}/depots")
            ->assertNotFound();
    }
}
