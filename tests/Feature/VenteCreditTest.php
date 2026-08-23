<?php

namespace Tests\Feature;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\EncoursService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vente à crédit au point de vente : encaissement partiel ou nul, encours
 * client et plafond, rapport de caisse et branchement sur le recouvrement.
 */
class VenteCreditTest extends TestCase
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

    /** Article à 1000 HT → 1200 TTC. */
    private function produit(): Produit
    {
        return Produit::create([
            'code' => 'PR-'.uniqid(), 'name' => 'Palette ciment', 'type' => 'product',
            'sell_price' => 1000, 'buy_price' => 700, 'tva_rate' => 20, 'is_active' => true,
        ]);
    }

    private function client(array $data = []): array
    {
        return $this->withToken($this->token)
            ->postJson('/api/v1/tiers', array_merge(['name' => 'Épicerie Nour'], $data))
            ->assertCreated()->json('data');
    }

    private function ouvrirCaisse(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/pos/session/ouvrir', ['fond_caisse' => 0])->assertCreated();
    }

    /* ---------------------------------------------------------------- */

    public function test_encaissement_partiel_laisse_un_solde_du(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        // Ticket 1200 TTC, le client verse 500 : 700 restent dus.
        $vente = $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'],
            'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 500]],
        ]);

        $vente->assertCreated()
            ->assertJsonPath('data.total_ttc', '1200.00')
            // Le statut reste « valide » : c'est lui que regardent les relances.
            ->assertJsonPath('data.statut', 'valide')
            ->assertJsonPath('data.reste_a_payer', '700.00');

        // La créance est constatée en comptabilité : 3421 débité de 1200, crédité de 500.
        $encours = app(EncoursService::class)->pour(Tiers::find($client['id']));
        $this->assertSame(700.0, $encours);
    }

    public function test_vente_entierement_a_credit_sans_aucun_paiement(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        $vente = $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'],
            'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ]);

        $vente->assertCreated()
            ->assertJsonPath('data.statut', 'valide')
            ->assertJsonPath('data.reste_a_payer', '1200.00');

        // Aucun encaissement : la caisse ne doit pas être créditée.
        $this->withToken($this->token)->getJson('/api/v1/pos/session')
            ->assertJsonPath('rapport.especes_theorique', '0.00')
            ->assertJsonPath('rapport.total_credit', '1200.00');
    }

    public function test_ecart_sans_drapeau_credit_reste_une_erreur_de_saisie(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        // 1200 dus, 1150 tapés, sans demander de crédit → refus (faute de frappe).
        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 1150]],
        ])->assertUnprocessable()->assertJsonValidationErrors('paiements');

        // Rien n'a été créé.
        $this->withToken($this->token)->getJson('/api/v1/ventes/documents?type=facture')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_sur_encaissement_toujours_refuse(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'],
            'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 1500]],
        ])->assertUnprocessable()->assertJsonValidationErrors('paiements');
    }

    public function test_credit_refuse_au_client_de_passage(): void
    {
        $produit = $this->produit();
        $this->ouvrirCaisse();

        // Sans tiers identifié, la vente part sur le client comptoir : anonyme,
        // il ne peut rien devoir.
        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 200]],
        ])->assertUnprocessable()->assertJsonValidationErrors('tiers_id');
    }

    public function test_plafond_de_credit_bloque_le_depassement(): void
    {
        $produit = $this->produit();
        $client = $this->client(['plafond_credit' => 1000]);
        $this->ouvrirCaisse();

        // 1200 à crédit alors que le plafond est de 1000 → refus.
        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'],
            'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('tiers_id');

        // En versant 400, le crédit tombe à 800 : sous le plafond, donc accepté.
        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'],
            'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 400]],
        ])->assertCreated();
    }

    public function test_plafond_tient_compte_de_l_encours_deja_accumule(): void
    {
        $produit = $this->produit();
        $client = $this->client(['plafond_credit' => 1500]);
        $this->ouvrirCaisse();

        // 1er crédit : 1200 → encours 1200, sous le plafond de 1500.
        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ])->assertCreated();

        // 2e crédit de 1200 : porterait l'encours à 2400 → refusé.
        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('tiers_id');
    }

    public function test_sans_plafond_le_credit_est_libre(): void
    {
        $produit = $this->produit();
        $client = $this->client(); // plafond_credit null
        $this->ouvrirCaisse();

        foreach (range(1, 3) as $i) {
            $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
                'tiers_id' => $client['id'], 'vente_credit' => true,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
                'paiements' => [],
            ])->assertCreated();
        }

        $this->assertSame(3600.0, app(EncoursService::class)->pour(Tiers::find($client['id'])));
    }

    public function test_la_vente_a_credit_alimente_relances_et_balance_agee(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 200]],
        ])->assertCreated();

        // Balance âgée : 1000 restent dus par ce client.
        $balance = $this->withToken($this->token)->getJson('/api/v1/compta/balance-agee?type=clients')->assertOk();
        $ligne = collect($balance->json('data'))->firstWhere('tiers_id', $client['id']);
        $this->assertNotNull($ligne, 'Le client à crédit doit apparaître dans la balance âgée.');
        $this->assertEquals(1000.0, (float) $ligne['total']);

        $this->withToken($this->token)->putJson('/api/v1/parametres', ['features' => ['relances' => true]])->assertOk();

        // Échéance du jour : la créance n'est pas encore en retard.
        $this->assertEmpty(
            $this->withToken($this->token)->getJson('/api/v1/relances/a-relancer')->json('data'),
            'Une échéance du jour ne doit pas encore être relançable.',
        );

        // Une fois l'échéance passée, la vente à crédit entre dans la worklist.
        \App\Modules\Ventes\Models\DocumentVente::where('type', 'facture')
            ->update(['date_echeance' => now()->subDay()->toDateString()]);

        $aRelancer = $this->withToken($this->token)->getJson('/api/v1/relances/a-relancer')->assertOk();
        $this->assertNotEmpty($aRelancer->json('data'), 'La vente à crédit échue doit être relançable.');
    }

    public function test_echeance_suit_le_delai_de_paiement_du_client(): void
    {
        $produit = $this->produit();
        $client = $this->client(['delai_paiement_jours' => 30]);
        $this->ouvrirCaisse();

        $vente = $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ])->assertCreated();

        $this->assertSame(now()->addDays(30)->toDateString(), $vente->json('data.date_echeance'));
    }

    public function test_rapport_de_caisse_distingue_vendu_encaisse_et_credit(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        // Ticket comptant 1200, puis ticket à crédit 1200 dont 200 encaissés.
        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 1200]],
        ])->assertCreated();

        $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 200]],
        ])->assertCreated();

        $this->withToken($this->token)->getJson('/api/v1/pos/session')->assertOk()
            ->assertJsonPath('rapport.tickets', 2)
            ->assertJsonPath('rapport.total_ttc', '2400.00')      // vendu
            ->assertJsonPath('rapport.total_encaisse', '1400.00') // reçu
            ->assertJsonPath('rapport.total_credit', '1000.00')   // laissé à crédit
            ->assertJsonPath('rapport.especes_theorique', '1400.00');
    }

    public function test_le_credit_d_un_z_cloture_ne_change_plus_apres_reglement(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        $vente = $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ])->assertCreated();

        $sessionId = $this->withToken($this->token)->getJson('/api/v1/pos/session')->json('data.id');
        $this->withToken($this->token)->postJson('/api/v1/pos/session/fermer', ['montant_compte' => 0])->assertOk();

        // Z de clôture : 1200 vendus, entièrement à crédit.
        $this->withToken($this->token)->getJson("/api/v1/pos/sessions/{$sessionId}/rapport")
            ->assertJsonPath('rapport.total_credit', '1200.00');

        // Le client règle plus tard. Le Z archivé doit rester identique : c'est
        // une pièce comptable, elle ne se réécrit pas.
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$vente->json('data.id')}/paiements", [
            'montant' => 1200, 'mode' => 'especes',
        ])->assertSuccessful();

        $this->withToken($this->token)->getJson("/api/v1/pos/sessions/{$sessionId}/rapport")
            ->assertJsonPath('rapport.total_ttc', '1200.00')
            ->assertJsonPath('rapport.total_credit', '1200.00');
    }

    public function test_le_plafond_suit_le_compte_client_remappe(): void
    {
        $produit = $this->produit();
        // Plafond 2000 : une vente de 1200 passe seule, deux ne passent pas.
        // C'est l'ACCUMULATION qui doit être mesurée, donc l'encours réel.
        $client = $this->client(['plafond_credit' => 2000]);

        // L'entreprise remappe son compte collectif clients : la créance ne
        // s'écrit plus sur 3421. L'encours doit la suivre là où elle est écrite.
        $comptes = $this->withToken($this->token)->getJson('/api/v1/compta/comptes')->json('data');
        $autre = collect($comptes)->firstWhere('code', '3424');
        $this->assertNotNull($autre, 'Le compte 3424 doit exister pour ce test.');
        $this->withToken($this->token)->putJson('/api/v1/compta/mappings', [
            'cle' => 'clients', 'compte_id' => $autre['id'],
        ])->assertOk();

        $this->ouvrirCaisse();

        $vente = fn () => $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ]);

        // 1re vente : 1200 sur un plafond de 2000 → acceptée, encours = 1200.
        $vente()->assertCreated();
        $this->assertSame(
            1200.0,
            app(EncoursService::class)->pour(Tiers::find($client['id'])),
            'L\'encours doit être mesuré sur le compte réellement mappé.',
        );

        // 2e vente : porterait l'encours à 2400 > 2000 → refusée.
        $vente()->assertUnprocessable()->assertJsonValidationErrors('tiers_id');
    }

    public function test_reglement_ulterieur_credite_la_session_qui_encaisse(): void
    {
        $produit = $this->produit();
        $client = $this->client();
        $this->ouvrirCaisse();

        $vente = $this->withToken($this->token)->postJson('/api/v1/pos/ventes', [
            'tiers_id' => $client['id'], 'vente_credit' => true,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            'paiements' => [],
        ])->assertCreated();

        // Clôture de la session : rien n'a été encaissé.
        $this->withToken($this->token)->postJson('/api/v1/pos/session/fermer', ['montant_compte' => 0])
            ->assertOk()->assertJsonPath('rapport.especes_theorique', '0.00');

        // Le client règle plus tard, hors caisse : le paiement ne doit pas
        // remonter dans le Z de la session déjà close.
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$vente->json('data.id')}/paiements", [
            'montant' => 1200, 'mode' => 'especes',
        ])->assertSuccessful();

        $sessions = $this->withToken($this->token)->getJson('/api/v1/pos/sessions')->assertOk();
        $rapport = $this->withToken($this->token)
            ->getJson("/api/v1/pos/sessions/{$sessions->json('data.0.id')}/rapport")->assertOk();

        $this->assertSame('0.00', $rapport->json('rapport.especes_theorique'));
        $this->assertSame('0.00', $rapport->json('rapport.total_encaisse'));
    }
}
