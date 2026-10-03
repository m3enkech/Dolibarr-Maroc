<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GET /ventes/prix : le tarif du client proposé au vendeur pendant la saisie.
 *
 * Le formulaire de vente envoie TOUJOURS un prix — c'est le prix affiché qui
 * est enregistré. Si ce point d'accès se trompait, le tarif du client serait
 * perdu sans bruit ; s'il fuyait d'une entreprise à l'autre, il exposerait les
 * prix d'un concurrent. D'où ces tests.
 */
class PrixVenteTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @return array{0: string, 1: int} jeton de l'administrateur et identifiant de l'entreprise */
    private function entreprise(string $nom = 'Acme'): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => $nom, 'name' => 'Admin',
            'email' => strtolower($nom).'@test.ma', 'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), $res->json('tenant.id')];
    }

    private function produit(string $token, float $prix = 100): int
    {
        return $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Article '.(++$this->seq), 'type' => 'product',
            'sell_price' => $prix, 'tva_rate' => 20,
        ])->assertCreated()->json('data.id');
    }

    private function categorie(string $token, string $nom, bool $parDefaut = false): int
    {
        return $this->withToken($token)->postJson('/api/v1/categories-tarifaires', [
            'name' => $nom, 'is_default' => $parDefaut,
        ])->assertCreated()->json('data.id');
    }

    private function client(string $token, ?int $categorieId = null): int
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Client '.(++$this->seq), 'categorie_tarifaire_id' => $categorieId,
        ])->assertCreated()->json('data.id');
    }

    /** Tarif d'une catégorie (`['categorie_tarifaire_id' => …]`) ou d'un client (`['tiers_id' => …]`). */
    private function tarif(string $token, int $produitId, array $cible, float $quantiteMin, float $prix): void
    {
        $this->withToken($token)->postJson("/api/v1/produits/{$produitId}/tarifs", $cible + [
            'quantite_min' => $quantiteMin, 'prix' => $prix,
        ])->assertCreated();
    }

    /** @param  list<array{0: int, 1: float}>  $lignes  article, quantité */
    private function prix(string $token, ?int $tiersId, array $lignes): TestResponse
    {
        $query = http_build_query(array_filter([
            'tiers_id' => $tiersId,
            'lignes' => array_map(fn (array $l) => ['produit_id' => $l[0], 'quantite' => $l[1]], $lignes),
        ], fn ($v) => $v !== null));

        return $this->withToken($token)->getJson('/api/v1/ventes/prix?'.$query);
    }

    /* ---------------------------------------------------------------- */
    /* Priorité des règles */
    /* ---------------------------------------------------------------- */

    public function test_prix_negocie_puis_categorie_du_client_puis_catalogue(): void
    {
        [$token] = $this->entreprise();
        $gros = $this->categorie($token, 'Gros');
        $client = $this->client($token, $gros);
        $voisin = $this->client($token, $gros);

        $negocie = $this->produit($token);
        $this->tarif($token, $negocie, ['categorie_tarifaire_id' => $gros], 1, 80);
        $this->tarif($token, $negocie, ['tiers_id' => $client], 1, 72);

        $parCategorie = $this->produit($token);
        $this->tarif($token, $parCategorie, ['categorie_tarifaire_id' => $gros], 1, 80);

        $sansTarif = $this->produit($token, 100);

        // Le prix négocié pour UN AUTRE client ne s'applique jamais à celui-ci.
        $negocieAilleurs = $this->produit($token, 100);
        $this->tarif($token, $negocieAilleurs, ['tiers_id' => $voisin], 1, 50);

        $this->prix($token, $client, [[$negocie, 1], [$parCategorie, 1], [$sansTarif, 1], [$negocieAilleurs, 1]])
            ->assertOk()
            ->assertJsonCount(4, 'data')
            // Les réponses suivent l'ordre des lignes demandées.
            ->assertJsonPath('data.0.produit_id', $negocie)
            ->assertJsonPath('data.0.prix', '72.00')
            ->assertJsonPath('data.0.origine', 'client')
            ->assertJsonPath('data.0.categorie', null)
            ->assertJsonPath('data.1.prix', '80.00')
            ->assertJsonPath('data.1.origine', 'categorie')
            ->assertJsonPath('data.1.categorie', 'Gros')
            ->assertJsonPath('data.2.prix', '100.00')
            ->assertJsonPath('data.2.origine', 'catalogue')
            ->assertJsonPath('data.2.palier', null)
            ->assertJsonPath('data.3.prix', '100.00')
            ->assertJsonPath('data.3.origine', 'catalogue');
    }

    public function test_sans_client_ou_sans_categorie_la_categorie_par_defaut_sapplique(): void
    {
        [$token] = $this->entreprise();
        $detail = $this->categorie($token, 'Détail', parDefaut: true);
        $produit = $this->produit($token, 100);
        $this->tarif($token, $produit, ['categorie_tarifaire_id' => $detail], 1, 95);

        // Avant le choix du client, l'écran montre déjà le tarif par défaut —
        // annoncé comme tel, pas comme un « tarif client ».
        $this->prix($token, null, [[$produit, 1]])->assertOk()
            ->assertJsonPath('data.0.prix', '95.00')
            ->assertJsonPath('data.0.origine', 'categorie')
            ->assertJsonPath('data.0.categorie', 'Détail')
            ->assertJsonPath('data.0.par_defaut', true);

        // Client sans catégorie : toujours la catégorie par défaut.
        $this->prix($token, $this->client($token), [[$produit, 1]])->assertOk()
            ->assertJsonPath('data.0.prix', '95.00')
            ->assertJsonPath('data.0.par_defaut', true);

        // Client RANGÉ dans cette même catégorie : c'est alors la sienne.
        $this->prix($token, $this->client($token, $detail), [[$produit, 1]])->assertOk()
            ->assertJsonPath('data.0.prix', '95.00')
            ->assertJsonPath('data.0.par_defaut', false);
    }

    /* ---------------------------------------------------------------- */
    /* Prix dégressifs */
    /* ---------------------------------------------------------------- */

    public function test_le_palier_depend_de_la_quantite(): void
    {
        [$token] = $this->entreprise();
        $gros = $this->categorie($token, 'Gros');
        $client = $this->client($token, $gros);
        $produit = $this->produit($token, 100);
        foreach ([[1, 90], [50, 80], [200, 70]] as [$qte, $prix]) {
            $this->tarif($token, $produit, ['categorie_tarifaire_id' => $gros], $qte, $prix);
        }

        // Le même article sur quatre lignes : un lot n'a pas à dédoublonner.
        $this->prix($token, $client, [[$produit, 10], [$produit, 50], [$produit, 199.5], [$produit, 200]])
            ->assertOk()
            ->assertJsonPath('data.0.prix', '90.00')
            ->assertJsonPath('data.0.palier', 1)
            ->assertJsonPath('data.1.prix', '80.00')   // palier atteint pile
            ->assertJsonPath('data.1.palier', 50)
            ->assertJsonPath('data.2.prix', '80.00')
            ->assertJsonPath('data.2.quantite', 199.5)
            ->assertJsonPath('data.3.prix', '70.00')
            ->assertJsonPath('data.3.palier', 200);
    }

    public function test_un_prix_negocie_non_atteint_cede_a_la_categorie(): void
    {
        [$token] = $this->entreprise();
        $gros = $this->categorie($token, 'Gros');
        $client = $this->client($token, $gros);
        $produit = $this->produit($token, 100);
        $this->tarif($token, $produit, ['categorie_tarifaire_id' => $gros], 1, 90);
        $this->tarif($token, $produit, ['tiers_id' => $client], 10, 72);

        // Sous le palier négocié, c'est la catégorie — pas le catalogue.
        $this->prix($token, $client, [[$produit, 5], [$produit, 12]])->assertOk()
            ->assertJsonPath('data.0.prix', '90.00')
            ->assertJsonPath('data.0.origine', 'categorie')
            ->assertJsonPath('data.1.prix', '72.00')
            ->assertJsonPath('data.1.origine', 'client')
            ->assertJsonPath('data.1.palier', 10);
    }

    /* ---------------------------------------------------------------- */
    /* Un seul algorithme */
    /* ---------------------------------------------------------------- */

    public function test_le_prix_propose_est_celui_que_le_serveur_facture_sans_prix_saisi(): void
    {
        [$token] = $this->entreprise();
        $gros = $this->categorie($token, 'Gros');
        $this->categorie($token, 'Détail', parDefaut: true);
        $client = $this->client($token, $gros);

        $a = $this->produit($token, 100);
        $this->tarif($token, $a, ['categorie_tarifaire_id' => $gros], 1, 90);
        $this->tarif($token, $a, ['categorie_tarifaire_id' => $gros], 20, 85);
        $b = $this->produit($token, 60);
        $this->tarif($token, $b, ['categorie_tarifaire_id' => $gros], 1, 55);
        $this->tarif($token, $b, ['tiers_id' => $client], 5, 50);
        $c = $this->produit($token, 40);

        $lignes = [[$a, 3], [$a, 25], [$b, 2], [$b, 6], [$c, 1]];
        $proposes = collect($this->prix($token, $client, $lignes)->assertOk()->json('data'))->pluck('prix')->all();

        // Même pièce, AUCUN prix envoyé : le serveur applique son tarif.
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client,
            'lignes' => array_map(fn (array $l) => ['produit_id' => $l[0], 'quantite' => $l[1]], $lignes),
        ])->assertCreated()->json('data');

        $this->assertSame(['90.00', '85.00', '55.00', '50.00', '40.00'], $proposes);
        $this->assertSame($proposes, array_column($facture['lignes'], 'prix_unitaire'));
    }

    /**
     * La caisse n'a que la grille (GET /tarifs/grille) et en applique le palier
     * le plus haut atteint (pages/pos/tarifs.ts). À TOUTE quantité, cela doit
     * redonner le prix que le serveur facture : la caisse plafonne chaque
     * paiement au total qu'elle affiche, et le serveur refuse un encaissement
     * supérieur au total du ticket. La grille ne livrait que les paliers
     * négociés dès qu'il en existait un : sous le premier, la caisse affichait
     * le catalogue (100) quand le serveur facturait la catégorie (90), et la
     * vente était refusée.
     */
    public function test_la_grille_de_la_caisse_redonne_le_prix_facture_a_toute_quantite(): void
    {
        [$token] = $this->entreprise();
        $gros = $this->categorie($token, 'Gros');
        $client = $this->client($token, $gros);

        // Négocié « dès 10 » sur une catégorie à paliers, de part et d'autre.
        $a = $this->produit($token, 100);
        foreach ([[1, 90], [5, 88], [10, 86], [50, 80]] as [$qte, $prix]) {
            $this->tarif($token, $a, ['categorie_tarifaire_id' => $gros], $qte, $prix);
        }
        $this->tarif($token, $a, ['tiers_id' => $client], 10, 72);
        $this->tarif($token, $a, ['tiers_id' => $client], 100, 65);
        // Négocié seul, dès 3 : en dessous, le catalogue.
        $b = $this->produit($token, 60);
        $this->tarif($token, $b, ['tiers_id' => $client], 3, 50);
        // Catégorie seule.
        $c = $this->produit($token, 40);
        $this->tarif($token, $c, ['categorie_tarifaire_id' => $gros], 1, 35);
        // Aucun tarif.
        $d = $this->produit($token, 20);

        $catalogue = [$a => 100.0, $b => 60.0, $c => 40.0, $d => 20.0];
        $grille = collect($this->withToken($token)->getJson("/api/v1/tarifs/grille?tiers_id={$client}")
            ->assertOk()->json('data'))->keyBy('produit_id');

        // Règle de la caisse (prixSelonPaliers / prixApplicable), recopiée.
        $prixCaisse = function (int $produit, float $quantite) use ($grille, $catalogue): string {
            $atteint = collect($grille->get($produit)['paliers'] ?? [])
                ->filter(fn (array $p) => $quantite >= $p['quantite_min'])
                ->sortByDesc('quantite_min')
                ->first();

            return number_format($atteint !== null ? (float) $atteint['prix'] : $catalogue[$produit], 2, '.', '');
        };

        $lignes = [];
        foreach (array_keys($catalogue) as $produit) {
            foreach ([1, 2.5, 3, 4.999, 5, 9, 9.999, 10, 49, 50, 99, 100, 250] as $quantite) {
                $lignes[] = [$produit, (float) $quantite];
            }
        }

        $factures = $this->prix($token, $client, $lignes)->assertOk()->json('data');

        foreach ($lignes as $i => [$produit, $quantite]) {
            $this->assertSame(
                $factures[$i]['prix'],
                $prixCaisse($produit, $quantite),
                "Article {$produit}, quantité {$quantite} : la caisse et le serveur divergent.",
            );
        }

        // Le cas signalé, en clair : 5 unités de A sous le palier négocié.
        $this->assertSame('88.00', $prixCaisse($a, 5));
    }

    /* ---------------------------------------------------------------- */
    /* Cloisonnement entre entreprises */
    /* ---------------------------------------------------------------- */

    public function test_un_article_d_une_autre_entreprise_est_refuse(): void
    {
        [$tokenA] = $this->entreprise('Alpha');
        [$tokenB] = $this->entreprise('Beta');
        $articleA = $this->produit($tokenA, 100);
        $articleB = $this->produit($tokenB, 777);

        $reponse = $this->prix($tokenA, null, [[$articleA, 1], [$articleB, 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lignes.1.produit_id')
            ->assertJsonMissingValidationErrors('lignes.0.produit_id');

        // Rien n'est servi — pas même le prix de la ligne valide.
        $reponse->assertJsonMissingPath('data');
        $this->assertStringNotContainsString('777', $reponse->getContent());
    }

    public function test_un_client_d_une_autre_entreprise_est_refuse(): void
    {
        [$tokenA] = $this->entreprise('Alpha');
        [$tokenB] = $this->entreprise('Beta');
        $articleA = $this->produit($tokenA, 100);
        $clientB = $this->client($tokenB);

        $this->prix($tokenA, $clientB, [[$articleA, 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tiers_id')
            ->assertJsonMissingPath('data');
    }

    public function test_les_tarifs_d_une_autre_entreprise_ne_s_appliquent_pas(): void
    {
        // La catégorie par défaut de B ne doit pas déteindre sur les prix de A.
        [$tokenA] = $this->entreprise('Alpha');
        [$tokenB] = $this->entreprise('Beta');
        $articleA = $this->produit($tokenA, 100);
        $this->categorie($tokenB, 'Défaut B', parDefaut: true);

        $this->prix($tokenA, null, [[$articleA, 1]])->assertOk()
            ->assertJsonPath('data.0.prix', '100.00')
            ->assertJsonPath('data.0.origine', 'catalogue');
    }

    /* ---------------------------------------------------------------- */
    /* Permission */
    /* ---------------------------------------------------------------- */

    public function test_reserve_aux_roles_qui_ont_acces_aux_ventes(): void
    {
        [$token, $tenantId] = $this->entreprise();
        $produit = $this->produit($token, 100);

        $jeton = fn (string $role) => User::factory()->create([
            'tenant_id' => $tenantId, 'role' => $role, 'is_active' => true,
        ])->createToken('spa')->plainTextToken;

        // Le caissier lit le catalogue mais n'a pas accès aux ventes.
        $this->prix($jeton('caissier'), null, [[$produit, 1]])->assertForbidden();

        foreach (['commercial', 'manager', 'comptable'] as $role) {
            $this->prix($jeton($role), null, [[$produit, 1]])->assertOk();
        }
    }

    /* ---------------------------------------------------------------- */
    /* Lot */
    /* ---------------------------------------------------------------- */

    public function test_trente_lignes_coutent_autant_de_requetes_qu_une_seule(): void
    {
        [$token] = $this->entreprise();
        $gros = $this->categorie($token, 'Gros');
        $client = $this->client($token, $gros);

        $lignes = [];
        for ($i = 0; $i < 30; $i++) {
            $produit = $this->produit($token, 100 + $i);
            $this->tarif($token, $produit, $i % 2 === 0 ? ['categorie_tarifaire_id' => $gros] : ['tiers_id' => $client], 1, 50 + $i);
            $lignes[] = [$produit, 1 + $i];
        }

        $requetes = function (array $lot) use ($token, $client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->prix($token, $client, $lot)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $requetes([$lignes[0]]); // échauffement : rien de ce qui se charge une fois ne fausse la mesure
        $une = $requetes([$lignes[0]]);
        $trente = $requetes($lignes);

        $this->assertSame($une, $trente, "1 ligne : {$une} requêtes ; 30 lignes : {$trente}.");
    }

    public function test_validation_du_lot(): void
    {
        [$token] = $this->entreprise();
        $produit = $this->produit($token, 100);

        $this->withToken($token)->getJson('/api/v1/ventes/prix')
            ->assertUnprocessable()->assertJsonValidationErrors('lignes');

        $this->prix($token, null, [[$produit, 0]])
            ->assertUnprocessable()->assertJsonValidationErrors('lignes.0.quantite');

        $this->prix($token, null, array_fill(0, 101, [$produit, 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes');
    }
}
