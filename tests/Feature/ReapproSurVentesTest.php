<?php

namespace Tests\Feature;

use App\Modules\Stock\Services\ReapproService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La quantité à commander, déduite des ventes passées.
 *
 * Les scénarios sont joués DANS LE TEMPS : les ventes sont enregistrées à leur
 * date réelle, car c'est l'écoulement des jours — et notamment les jours de
 * rupture — qui fait tout l'intérêt du calcul.
 */
class ReapproSurVentesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private array $entrepot;

    private array $tiers;

    protected function setUp(): void
    {
        parent::setUp();

        // Tout le décor est planté il y a 120 jours : un produit créé
        // aujourd'hui n'a, par construction, aucun historique.
        $this->travelTo(now()->subDays(120));

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Gros Atlas', 'name' => 'Patron', 'email' => 'a@gros.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');

        $this->entrepot = $this->withToken($this->token)
            ->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');

        $this->tiers = $this->withToken($this->token)
            ->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');

        $this->travelBack();
    }

    /** @return array<string, mixed> */
    private function produit(array $overrides = []): array
    {
        $this->travelTo(now()->subDays(120));

        $produit = $this->withToken($this->token)->postJson('/api/v1/produits', array_merge([
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ], $overrides))->assertCreated()->json('data');

        $this->travelBack();

        return $produit;
    }

    private function entrer(int $produitId, float $quantite, int $joursAvant): void
    {
        $this->travelTo(now()->subDays($joursAvant));

        $this->withToken($this->token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produitId, 'entrepot_id' => $this->entrepot['id'],
            'type' => 'entree', 'quantite' => $quantite,
        ])->assertCreated();

        $this->travelBack();
    }

    private function vendre(int $produitId, float $quantite, int $joursAvant): void
    {
        $this->travelTo(now()->subDays($joursAvant));

        $facture = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $produitId, 'quantite' => $quantite]],
        ])->assertCreated()->json('data');

        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        $this->travelBack();
    }

    /** @return array<string, mixed> */
    private function fournisseur(string $nom): array
    {
        return $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => $nom, 'is_client' => false, 'is_supplier' => true,
        ])->assertCreated()->json('data');
    }

    /** Commande fournisseur validée, datée. */
    private function acheter(int $fournisseurId, int $produitId, float $quantite, float $prix, int $joursAvant): void
    {
        $this->travelTo(now()->subDays($joursAvant));

        $commande = $this->withToken($this->token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $fournisseurId, 'entrepot_id' => $this->entrepot['id'],
            'lignes' => [['produit_id' => $produitId, 'quantite' => $quantite, 'prix_unitaire' => $prix]],
        ])->assertCreated()->json('data');

        $this->withToken($this->token)->postJson("/api/v1/achats/documents/{$commande['id']}/valider")->assertOk();

        $this->travelBack();
    }

    /** @return array<string, mixed>|null */
    private function ligneReappro(int $produitId): ?array
    {
        $data = $this->withToken($this->token)->getJson('/api/v1/stock/alertes')->assertOk()->json('data');

        return collect($data)->firstWhere('produit_id', $produitId);
    }

    /* ---------------------------------------------------------------- */

    /**
     * Un article sans seuil saisi, mais qui vend, est désormais conseillé —
     * c'est toute la raison d'être du calcul : les articles paramétrés à la
     * main sont une minorité.
     */
    public function test_un_produit_sans_seuil_mais_qui_vend_est_conseille(): void
    {
        $produit = $this->produit();
        $this->entrer($produit['id'], 100, 95);

        // 90 unités vendues en 9 fois, régulièrement, sur la fenêtre.
        foreach (range(85, 5, 10) as $joursAvant) {
            $this->vendre($produit['id'], 10, $joursAvant);
        }

        $ligne = $this->ligneReappro($produit['id']);

        $this->assertNotNull($ligne, 'Le produit doit apparaître au réappro.');
        $this->assertSame('ventes', $ligne['origine']);
        $this->assertSame('90.000', $ligne['demande_periode']);
        $this->assertSame(0, $ligne['jours_rupture']);
        $this->assertEqualsWithDelta(1.0, (float) $ligne['conso_jour'], 0.05, 'Une unité par jour.');

        // Horizon = 7 (délai) + 30 (couverture) + 7 (sécurité) = 44 jours.
        // Besoin 44, stock restant 10 → il manque 34.
        $this->assertSame(44, $ligne['horizon_jours']);
        $this->assertSame('34.000', $ligne['suggestion']);
    }

    /**
     * LE point du calcul : un article resté en rupture ne doit pas voir sa
     * moyenne s'effondrer. Sinon on en recommande moins, donc il retombe en
     * rupture — le système entretiendrait le mal qu'il doit soigner.
     */
    public function test_les_jours_de_rupture_ne_font_pas_baisser_la_moyenne(): void
    {
        $produit = $this->produit();

        $this->entrer($produit['id'], 60, 90);
        foreach ([85, 78, 71, 64, 57, 51] as $joursAvant) {
            $this->vendre($produit['id'], 10, $joursAvant);
        }
        // Stock à zéro du jour -51 au jour -5 : 46 jours sans rien vendre,
        // faute de marchandise et non faute de clients.
        $this->entrer($produit['id'], 20, 5);
        $this->vendre($produit['id'], 10, 3);

        $ligne = $this->ligneReappro($produit['id']);

        $this->assertNotNull($ligne);
        $this->assertSame('ventes', $ligne['origine']);
        $this->assertSame('70.000', $ligne['demande_periode']);
        $this->assertEqualsWithDelta(46, $ligne['jours_rupture'], 1.0, 'La rupture est mesurée.');

        // 70 unités sur 44 jours vendables ≈ 1,59/jour — au lieu de 0,78 si l'on
        // divisait bêtement par les 90 jours du calendrier.
        $this->assertGreaterThan(1.4, (float) $ligne['conso_jour']);
        $this->assertLessThan(1.8, (float) $ligne['conso_jour']);
    }

    /** Ce qui est déjà commandé au fournisseur vient en déduction. */
    public function test_la_commande_fournisseur_en_cours_est_deduite(): void
    {
        $produit = $this->produit();
        $this->entrer($produit['id'], 100, 95);

        foreach (range(85, 5, 10) as $joursAvant) {
            $this->vendre($produit['id'], 10, $joursAvant);
        }

        $sans = $this->ligneReappro($produit['id']);

        $fournisseur = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Ciments du Maroc', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');

        $commande = $this->withToken($this->token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $fournisseur['id'], 'entrepot_id' => $this->entrepot['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 20]],
        ])->assertCreated()->json('data');
        $this->withToken($this->token)->postJson("/api/v1/achats/documents/{$commande['id']}/valider")->assertOk();

        $avec = $this->ligneReappro($produit['id']);

        $this->assertSame('34.000', $sans['suggestion']);
        $this->assertSame('14.000', $avec['suggestion'], '20 unités sont déjà en route.');
    }

    /** Un avoir rend la marchandise : la demande nette en tient compte. */
    public function test_un_avoir_diminue_la_demande_mesuree(): void
    {
        $produit = $this->produit();
        $this->entrer($produit['id'], 100, 95);

        foreach (range(85, 5, 10) as $joursAvant) {
            $this->vendre($produit['id'], 10, $joursAvant);
        }

        // Le client rend 10 unités : la dernière facture est avoirée.
        $facture = collect($this->withToken($this->token)->getJson('/api/v1/ventes/documents?type=facture')->json('data'))
            ->sortByDesc('id')->first();

        $avoir = $this->withToken($this->token)
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/transformer", ['type' => 'avoir'])
            ->assertOk()->json('data');
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$avoir['id']}/valider")->assertOk();

        $ligne = $this->ligneReappro($produit['id']);

        $this->assertSame('80.000', $ligne['demande_periode'], '90 vendues moins 10 rendues.');
    }

    /** Un article qui ne vend pas et n'a pas de seuil n'est pas conseillé. */
    public function test_un_produit_sans_vente_ni_seuil_reste_invisible(): void
    {
        $produit = $this->produit(['name' => 'Article dormant']);
        $this->entrer($produit['id'], 5, 95);

        $this->assertNull($this->ligneReappro($produit['id']));
    }

    /**
     * Un article vendu il y a très longtemps sort de la fenêtre : on ne
     * recommande pas sur la foi d'une vente vieille de six mois.
     */
    public function test_les_ventes_hors_fenetre_sont_ignorees(): void
    {
        $produit = $this->produit();
        $this->entrer($produit['id'], 100, 115);
        $this->vendre($produit['id'], 90, 110);

        $this->assertNull($this->ligneReappro($produit['id']));
    }

    /**
     * Rien en base ne relie un produit à un fournisseur : on le déduit du
     * dernier achat réel, pour pré-remplir la commande. Le plus RÉCENT
     * l'emporte — un fournisseur quitté l'an dernier ne doit pas ressortir
     * parce qu'on lui achetait beaucoup.
     */
    public function test_le_fournisseur_habituel_vient_du_dernier_achat(): void
    {
        $produit = $this->produit();
        $this->entrer($produit['id'], 100, 95);
        foreach (range(85, 5, 10) as $joursAvant) {
            $this->vendre($produit['id'], 10, $joursAvant);
        }

        $ancien = $this->fournisseur('Ciments Anciens');
        $recent = $this->fournisseur('Ciments du Maroc');

        // Petites quantités : une grosse commande en cours éteindrait la
        // suggestion, et la ligne disparaîtrait de l'écran.
        $this->acheter($ancien['id'], $produit['id'], 5, 55, joursAvant: 80);
        $this->acheter($recent['id'], $produit['id'], 3, 62, joursAvant: 20);

        $ligne = $this->ligneReappro($produit['id']);

        $this->assertSame($recent['id'], $ligne['fournisseur_id']);
        $this->assertSame('Ciments du Maroc', $ligne['fournisseur_nom']);
        $this->assertSame('62.00', $ligne['dernier_prix_achat'], 'Le dernier prix payé, à confirmer.');
    }

    /** Un article jamais acheté n'invente pas de fournisseur. */
    public function test_sans_achat_passe_aucun_fournisseur_n_est_propose(): void
    {
        $produit = $this->produit();
        $this->entrer($produit['id'], 100, 95);
        foreach (range(85, 5, 10) as $joursAvant) {
            $this->vendre($produit['id'], 10, $joursAvant);
        }

        $ligne = $this->ligneReappro($produit['id']);

        $this->assertNull($ligne['fournisseur_id']);
        $this->assertNull($ligne['dernier_prix_achat']);
    }

    /** Les hypothèses du calcul sont publiées avec le résultat. */
    public function test_les_hypotheses_accompagnent_le_resultat(): void
    {
        $reponse = $this->withToken($this->token)->getJson('/api/v1/stock/alertes')->assertOk();

        $reponse->assertJsonPath('hypotheses.fenetre_jours', ReapproService::FENETRE_JOURS)
            ->assertJsonPath('hypotheses.couverture_jours', ReapproService::COUVERTURE_JOURS)
            ->assertJsonPath('hypotheses.delai_appro_jours', ReapproService::DELAI_APPRO_JOURS)
            ->assertJsonPath('hypotheses.securite_jours', ReapproService::SECURITE_JOURS);
    }
}
