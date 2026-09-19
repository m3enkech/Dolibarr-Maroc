<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Services\ProduitService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\ContactService;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La synthèse d'un tiers : ce qu'on veut savoir avant d'ouvrir quoi que ce soit.
 *
 * Des COMPTES et des TOTAUX, pas des listes — les onglets vont chercher leurs
 * pièces eux-mêmes. Charger ici les quatre cents factures d'un gros client pour
 * n'en afficher que le nombre serait payer la page entière pour un chiffre.
 */
class SyntheseTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tiers $client;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        $this->jeton = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->json('token');

        app(TenantContext::class)->set(
            User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant,
        );

        $this->client = app(TiersService::class)->create([
            'name' => 'CLIM & COOL', 'is_client' => true, 'is_supplier' => false,
        ]);

        $this->produit = app(ProduitService::class)->create([
            'name' => 'Toner Konica', 'type' => Produit::TYPE_PRODUCT, 'sell_price' => 100,
        ]);
    }

    private function document(string $type, float $prix, bool $valider = true, ?string $date = null): DocumentVente
    {
        $doc = app(VenteService::class)->create([
            'type' => $type,
            'tiers_id' => $this->client->id,
            'date_document' => $date ?? now()->toDateString(),
            'lignes' => [[
                'produit_id' => $this->produit->id,
                'designation' => 'Toner Konica',
                'quantite' => 2, 'prix_unitaire' => $prix, 'tva_rate' => 20,
            ]],
        ]);

        return $valider ? app(VenteService::class)->valider($doc) : $doc;
    }

    /** @return array<string, mixed> */
    private function synthese(): array
    {
        return $this->withToken($this->jeton)
            ->getJson("/api/v1/tiers/{$this->client->id}/synthese")
            ->assertOk()
            ->json('data');
    }

    /* ---------------------------------------------------------------- */

    public function test_les_pieces_sont_comptees_par_type(): void
    {
        $this->document(DocumentVente::TYPE_DEVIS, 100);
        $this->document(DocumentVente::TYPE_DEVIS, 100);
        $this->document(DocumentVente::TYPE_FACTURE, 100);

        $synthese = $this->synthese();

        $this->assertSame(2, $synthese['ventes']['devis']);
        $this->assertSame(1, $synthese['ventes']['factures']);
        $this->assertSame(0, $synthese['ventes']['commandes']);
    }

    public function test_le_chiffre_d_affaires_ne_compte_que_les_factures_emises(): void
    {
        $this->document(DocumentVente::TYPE_FACTURE, 100);              // 240 TTC
        $this->document(DocumentVente::TYPE_FACTURE, 50, valider: false); // brouillon
        $this->document(DocumentVente::TYPE_DEVIS, 1000);                 // pas du CA

        $this->assertSame('240.00', $this->synthese()['ca_ttc']);
    }

    /**
     * `valide` SIGNIFIE « due » : VenteService bascule la pièce en `paye` dès
     * qu'elle est soldée. Restent à retrancher les acomptes encaissés.
     */
    public function test_l_impaye_retranche_les_acomptes(): void
    {
        $facture = $this->document(DocumentVente::TYPE_FACTURE, 100); // 240 TTC

        $this->assertSame('240.00', $this->synthese()['impaye']);

        app(VenteService::class)->ajouterPaiement($facture, [
            'montant' => 90, 'mode' => 'virement',
        ]);

        $this->assertSame('150.00', $this->synthese()['impaye']);
    }

    public function test_une_facture_soldee_ne_pese_plus_sur_l_impaye(): void
    {
        $facture = $this->document(DocumentVente::TYPE_FACTURE, 100);

        app(VenteService::class)->ajouterPaiement($facture, ['montant' => 240, 'mode' => 'virement']);

        $synthese = $this->synthese();
        $this->assertSame('0.00', $synthese['impaye']);
        $this->assertSame('240.00', $synthese['ca_ttc'], 'Elle reste du chiffre d\'affaires.');
    }

    public function test_le_ca_douze_mois_ecarte_les_pieces_plus_anciennes(): void
    {
        $this->document(DocumentVente::TYPE_FACTURE, 100, date: now()->subMonths(2)->toDateString());
        $this->document(DocumentVente::TYPE_FACTURE, 500, date: now()->subYears(2)->toDateString());

        $synthese = $this->synthese();

        $this->assertSame('1440.00', $synthese['ca_ttc'], 'Le CA total prend tout.');
        $this->assertSame('240.00', $synthese['ca_12_mois'], 'Les douze mois, non.');
    }

    public function test_les_bornes_de_l_historique_sont_rendues(): void
    {
        $this->document(DocumentVente::TYPE_FACTURE, 100, date: '2022-04-28');
        $this->document(DocumentVente::TYPE_FACTURE, 100, date: '2026-09-18');

        $synthese = $this->synthese();

        $this->assertSame('2022-04-28', $synthese['premier_document']);
        $this->assertSame('2026-09-18', $synthese['dernier_document']);
    }

    public function test_les_contacts_sont_comptes(): void
    {
        app(ContactService::class)->create($this->client, ['nom' => 'Karim Alami']);

        $this->assertSame(1, $this->synthese()['contacts']);
    }

    /** Les chiffres d'un client ne doivent rien porter d'un autre. */
    public function test_la_synthese_ne_melange_pas_deux_clients(): void
    {
        $autre = app(TiersService::class)->create([
            'name' => 'AUTRE SARL', 'is_client' => true, 'is_supplier' => false,
        ]);

        $this->document(DocumentVente::TYPE_FACTURE, 100);

        app(VenteService::class)->valider(app(VenteService::class)->create([
            'type' => DocumentVente::TYPE_FACTURE,
            'tiers_id' => $autre->id,
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 999, 'tva_rate' => 20]],
        ]));

        $this->assertSame('240.00', $this->synthese()['ca_ttc']);
    }

    /* ----------------------- articles échangés ---------------------- */

    public function test_les_articles_achetes_sont_agreges_par_produit(): void
    {
        $this->document(DocumentVente::TYPE_FACTURE, 100); // 2 × 100
        $this->document(DocumentVente::TYPE_FACTURE, 100); // 2 × 100

        $produits = $this->withToken($this->jeton)
            ->getJson("/api/v1/tiers/{$this->client->id}/produits")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $produits, 'Le même article ne fait qu\'une ligne.');
        $this->assertSame('4.000', $produits[0]['quantite']);
        $this->assertSame('400.00', $produits[0]['montant_ht']);
        $this->assertSame(2, $produits[0]['occurrences']);
        $this->assertSame('Toner Konica', $produits[0]['name']);
    }

    public function test_un_brouillon_ne_compte_pas_dans_les_articles(): void
    {
        $this->document(DocumentVente::TYPE_FACTURE, 100, valider: false);

        $this->assertSame([], $this->withToken($this->jeton)
            ->getJson("/api/v1/tiers/{$this->client->id}/produits")
            ->assertOk()
            ->json('data'));
    }

    public function test_la_synthese_d_un_tiers_d_une_autre_entreprise_est_introuvable(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Concurrent', 'name' => 'Autre',
            'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->assertCreated();

        $jetonAutre = $this->postJson('/api/v1/auth/login', [
            'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->json('token');

        $this->withToken($jetonAutre)
            ->getJson("/api/v1/tiers/{$this->client->id}/synthese")
            ->assertNotFound();
    }
}
