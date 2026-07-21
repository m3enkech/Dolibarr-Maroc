<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POS : sessions de caisse, vente comptoir en un geste (facture validée + payée),
 * rendu de monnaie, rapport X/Z et écart de clôture.
 */
class PosTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $company, string $email): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin '.$company,
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    private function createProduit(string $token, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', array_merge([
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'buy_price' => 60, 'tva_rate' => 20,
        ], $overrides))->json('data');
    }

    private function ouvrirSession(string $token, float $fond = 500): array
    {
        return $this->withToken($token)
            ->postJson('/api/v1/pos/session/ouvrir', ['fond_caisse' => $fond])
            ->json('data');
    }

    public function test_session_lifecycle_and_unicity(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Pas de session au départ.
        $this->withToken($token)->getJson('/api/v1/pos/session')
            ->assertOk()->assertJsonPath('data', null);

        $ouverture = $this->withToken($token)->postJson('/api/v1/pos/session/ouvrir', [
            'fond_caisse' => 500, 'note' => 'Caisse du matin',
        ]);
        $ouverture->assertCreated()
            ->assertJsonPath('data.statut', 'ouverte')
            ->assertJsonPath('data.fond_caisse', '500.00');
        $this->assertStringStartsWith('CS-', $ouverture->json('data.code'));

        // Une seule session ouverte à la fois par vendeur.
        $this->withToken($token)->postJson('/api/v1/pos/session/ouvrir', ['fond_caisse' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('session');

        // La session courante est renvoyée avec son rapport.
        $courante = $this->withToken($token)->getJson('/api/v1/pos/session');
        $courante->assertOk()
            ->assertJsonPath('data.code', $ouverture->json('data.code'))
            ->assertJsonPath('rapport.tickets', 0)
            ->assertJsonPath('rapport.especes_theorique', '500.00');
    }

    public function test_vente_requires_open_session(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);

        $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 102]],
        ])->assertUnprocessable()->assertJsonValidationErrors('session');
    }

    public function test_vente_pos_creates_paid_invoice_with_stock_and_compta(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token); // 85 HT, TVA 20 → 102 TTC l'unité
        $this->ouvrirSession($token);

        // 2 unités = 170 HT / 204 TTC. Client donne 250 en espèces.
        $vente = $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 2]],
            'paiements' => [['mode' => 'especes', 'montant' => 204]],
            'montant_donne' => 250,
        ]);

        $vente->assertCreated()
            ->assertJsonPath('data.type', 'facture')
            ->assertJsonPath('data.statut', 'paye')
            ->assertJsonPath('data.total_ttc', '204.00')
            ->assertJsonPath('data.tiers.name', 'Client comptoir')
            ->assertJsonPath('rendu', '46.00')
            ->assertJsonPath('rapport.tickets', 1)
            ->assertJsonPath('rapport.especes_theorique', '704.00'); // 500 + 204
        $this->assertStringStartsWith('FA-', $vente->json('data.code'));

        // Sortie de stock automatique (type vente, -2).
        $mouvements = $this->withToken($token)->getJson('/api/v1/stock/mouvements');
        $mouvements->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'vente')
            ->assertJsonPath('data.0.quantite', '-2.000');

        // Écritures comptables : VT (facture) + BQ (encaissement espèces).
        $ecritures = $this->withToken($token)->getJson('/api/v1/compta/ecritures');
        $journaux = collect($ecritures->json('data'))->pluck('journal');
        $this->assertCount(2, $journaux);
        $this->assertEqualsCanonicalizing(['VT', 'BQ'], $journaux->all());

        // Le ticket apparaît dans les dernières ventes de la session.
        $this->withToken($token)->getJson('/api/v1/pos/tickets')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', $vente->json('data.code'));
    }

    public function test_vente_avec_remise_ligne_et_rapport_total_remises(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token); // 85 HT, TVA 20
        $this->ouvrirSession($token);

        // 2 unités à 85 HT avec 10% de remise :
        // HT net = 2 × 85 × 0.9 = 153.00 ; TVA = 30.60 ; TTC = 183.60 ; remise = 17.00.
        $vente = $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 2, 'remise_percent' => 10]],
            'paiements' => [['mode' => 'carte', 'montant' => 183.60]],
        ]);

        $vente->assertCreated()
            ->assertJsonPath('data.total_ht', '153.00')
            ->assertJsonPath('data.total_ttc', '183.60')
            ->assertJsonPath('data.lignes.0.remise_percent', '10.00')
            ->assertJsonPath('rapport.total_remises', '17.00')
            ->assertJsonPath('rapport.total_ttc', '183.60');
    }

    public function test_vente_sort_du_stock_de_l_entrepot_de_la_caisse(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);

        // Deux entrepôts : le 1er devient défaut, le 2e est la boutique.
        $principal = $this->withToken($token)->postJson('/api/v1/stock/entrepots', ['name' => 'Principal'])->json('data');
        $boutique = $this->withToken($token)->postJson('/api/v1/stock/entrepots', ['name' => 'Boutique Centre'])->json('data');

        // Session de caisse rattachée à la boutique.
        $this->withToken($token)->postJson('/api/v1/pos/session/ouvrir', [
            'fond_caisse' => 0, 'entrepot_id' => $boutique['id'],
        ])->assertCreated()->assertJsonPath('data.entrepot_nom', 'Boutique Centre');

        // Vente 2 unités (85 HT → 102 TTC × 2 = 204).
        $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 2]],
            'paiements' => [['mode' => 'especes', 'montant' => 204]],
        ])->assertCreated();

        // La sortie de stock vise la boutique, pas l'entrepôt par défaut.
        $this->withToken($token)->getJson("/api/v1/stock/mouvements?entrepot_id={$boutique['id']}")
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.quantite', '-2.000')
            ->assertJsonPath('data.0.entrepot.name', 'Boutique Centre');

        $this->withToken($token)->getJson("/api/v1/stock/mouvements?entrepot_id={$principal['id']}")
            ->assertJsonPath('meta.total', 0);
    }

    public function test_vente_est_idempotente_par_client_uuid(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token); // 85 HT → 102 TTC
        $this->ouvrirSession($token);

        $uuid = '11111111-2222-3333-4444-555555555555';
        $payload = [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 102]],
            'client_uuid' => $uuid,
        ];

        // 1er envoi : crée la facture.
        $premier = $this->withToken($token)->postJson('/api/v1/pos/ventes', $payload)->assertCreated();
        $code = $premier->json('data.code');

        // 2e envoi identique (rejoué après une coupure réseau) : même facture, pas de doublon.
        $this->withToken($token)->postJson('/api/v1/pos/ventes', $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', $code);

        // Une seule facture, un seul mouvement de stock, un seul ticket dans le rapport.
        $this->withToken($token)->getJson('/api/v1/ventes/documents?type=facture')
            ->assertJsonPath('meta.total', 1);
        $this->withToken($token)->getJson('/api/v1/stock/mouvements')
            ->assertJsonPath('meta.total', 1);
        $this->withToken($token)->getJson('/api/v1/pos/session')
            ->assertJsonPath('rapport.tickets', 1);
    }

    public function test_vente_rejects_wrong_payment_total(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $this->ouvrirSession($token);

        // 204 TTC dus, 200 encaissés → refus, et rien n'est créé (rollback).
        $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 2]],
            'paiements' => [['mode' => 'especes', 'montant' => 200]],
        ])->assertUnprocessable()->assertJsonValidationErrors('paiements');

        $this->withToken($token)->getJson('/api/v1/ventes/documents?type=facture')
            ->assertJsonPath('meta.total', 0);
        $this->withToken($token)->getJson('/api/v1/stock/mouvements')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_paiement_mixte_especes_carte(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $this->ouvrirSession($token);

        $vente = $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 2]],
            'paiements' => [
                ['mode' => 'carte', 'montant' => 100],
                ['mode' => 'especes', 'montant' => 104],
            ],
        ]);

        $vente->assertCreated()->assertJsonPath('data.statut', 'paye');
        $this->assertCount(2, $vente->json('data.paiements'));

        // Le rapport ventile par mode ; seules les espèces comptent pour la caisse.
        $vente->assertJsonPath('rapport.par_mode.carte', '100.00')
            ->assertJsonPath('rapport.par_mode.especes', '104.00')
            ->assertJsonPath('rapport.especes_theorique', '604.00'); // 500 + 104
    }

    public function test_fermeture_calcule_ecart(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $produit = $this->createProduit($token);
        $this->ouvrirSession($token, 500);

        $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 2]],
            'paiements' => [['mode' => 'especes', 'montant' => 204]],
        ])->assertCreated();

        // Théorique = 704, compté = 690 → écart -14.
        $fermeture = $this->withToken($token)->postJson('/api/v1/pos/session/fermer', [
            'montant_compte' => 690,
        ]);
        $fermeture->assertOk()
            ->assertJsonPath('data.statut', 'fermee')
            ->assertJsonPath('data.montant_compte', '690.00')
            ->assertJsonPath('data.ecart', '-14.00')
            ->assertJsonPath('rapport.tickets', 1);

        // Plus de session ouverte : vendre ou refermer est impossible.
        $this->withToken($token)->getJson('/api/v1/pos/session')->assertJsonPath('data', null);
        $this->withToken($token)->postJson('/api/v1/pos/ventes', [
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 1]],
            'paiements' => [['mode' => 'especes', 'montant' => 102]],
        ])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/pos/session/fermer', ['montant_compte' => 0])
            ->assertUnprocessable();

        // Le rapport Z reste consultable sur la session fermée.
        $this->withToken($token)->getJson("/api/v1/pos/sessions/{$fermeture->json('data.id')}/rapport")
            ->assertOk()->assertJsonPath('rapport.especes_theorique', '704.00');
    }

    public function test_sessions_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $session = $this->ouvrirSession($tokenA);

        // B ne voit ni la session courante de A, ni son historique, ni son rapport.
        $this->withToken($tokenB)->getJson('/api/v1/pos/session')->assertJsonPath('data', null);
        $this->withToken($tokenB)->getJson('/api/v1/pos/sessions')->assertJsonPath('meta.total', 0);
        $this->withToken($tokenB)->getJson("/api/v1/pos/sessions/{$session['id']}/rapport")
            ->assertNotFound();
    }
}
