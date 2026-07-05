<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AchatsTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $company, string $email): string
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin '.$company,
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertCreated();

        return $response->json('token');
    }

    private function createFournisseur(string $token, string $name = 'Fournisseur Test'): array
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => $name, 'is_client' => false, 'is_supplier' => true,
        ])->json('data');
    }

    private function createProduit(string $token, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', array_merge([
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'buy_price' => 60, 'tva_rate' => 20,
        ], $overrides))->json('data');
    }

    private function createEntrepot(string $token, string $name = 'Dépôt principal'): array
    {
        return $this->withToken($token)
            ->postJson('/api/v1/stock/entrepots', ['name' => $name])
            ->json('data');
    }

    private function createCommandeValidee(string $token, int $tiersId, int $entrepotId, array $lignes): array
    {
        $commande = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande',
            'tiers_id' => $tiersId,
            'entrepot_id' => $entrepotId,
            'lignes' => $lignes,
        ])->json('data');

        return $this->withToken($token)
            ->postJson("/api/v1/achats/documents/{$commande['id']}/valider")
            ->json('data');
    }

    public function test_commande_requires_a_supplier(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Client pur', 'is_client' => true, 'is_supplier' => false,
        ])->json('data');

        $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande',
            'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 10, 'tva_rate' => 20]],
        ])->assertUnprocessable()->assertJsonValidationErrors('tiers_id');
    }

    public function test_ligne_defaults_use_buy_price(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token);
        $produit = $this->createProduit($token, ['buy_price' => 60]);

        $commande = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande',
            'tiers_id' => $fournisseur['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 10]],
        ]);

        $commande->assertCreated()
            ->assertJsonPath('data.code', 'CF-'.now()->year.'-00001')
            ->assertJsonPath('data.lignes.0.prix_unitaire', '60.00')
            ->assertJsonPath('data.total_ht', '600.00');
    }

    public function test_partial_receptions_flow_with_over_reception_blocked(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token);
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        $commande = $this->createCommandeValidee($token, $fournisseur['id'], $entrepot['id'], [
            ['produit_id' => $produit['id'], 'quantite' => 100, 'prix_unitaire' => 60],
        ]);

        // 1re réception : la transformation propose le reste (100), on reçoit 60.
        $reception1 = $this->withToken($token)->postJson("/api/v1/achats/documents/{$commande['id']}/transformer", [
            'type' => 'reception',
        ])->json('data');
        $this->assertSame('RE-'.now()->year.'-00001', $reception1['code']);
        $this->assertSame($entrepot['id'], $reception1['entrepot_id']); // entrepôt hérité de la commande

        $this->withToken($token)->putJson("/api/v1/achats/documents/{$reception1['id']}", [
            'lignes' => [[
                'produit_id' => $produit['id'],
                'source_ligne_id' => $reception1['lignes'][0]['source_ligne_id'],
                'quantite' => 60,
            ]],
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/achats/documents/{$reception1['id']}/valider")->assertOk();

        // La commande passe en "reçue partiellement", reste 40.
        $apres1 = $this->withToken($token)->getJson("/api/v1/achats/documents/{$commande['id']}")->json('data');
        $this->assertSame('recue_partielle', $apres1['statut']);
        $this->assertSame('40.000', $apres1['lignes'][0]['reste_a_recevoir']);

        // Le stock est entré : +60.
        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertJsonPath('data.0.quantite', '60.000');

        // 2e réception : propose 40 ; en recevoir 50 est refusé.
        $reception2 = $this->withToken($token)->postJson("/api/v1/achats/documents/{$commande['id']}/transformer", [
            'type' => 'reception',
        ])->json('data');
        $this->assertSame('40.000', $reception2['lignes'][0]['quantite']);

        $this->withToken($token)->putJson("/api/v1/achats/documents/{$reception2['id']}", [
            'lignes' => [[
                'produit_id' => $produit['id'],
                'source_ligne_id' => $reception2['lignes'][0]['source_ligne_id'],
                'quantite' => 50,
            ]],
        ])->assertUnprocessable();

        // On valide les 40 restants : commande "reçue", stock 100.
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$reception2['id']}/valider")->assertOk();

        $apres2 = $this->withToken($token)->getJson("/api/v1/achats/documents/{$commande['id']}")->json('data');
        $this->assertSame('recue', $apres2['statut']);

        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertJsonPath('data.0.quantite', '100.000');

        // Plus rien à réceptionner.
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$commande['id']}/transformer", [
            'type' => 'reception',
        ])->assertUnprocessable();
    }

    public function test_reception_requires_entrepot_at_validation(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token);
        $produit = $this->createProduit($token);
        $this->createEntrepot($token);

        // Réception directe sans entrepôt → refusée à la validation.
        $reception = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'reception',
            'tiers_id' => $fournisseur['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 5]],
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/achats/documents/{$reception['id']}/valider")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entrepot_id');
    }

    public function test_stock_moves_exactly_once_across_the_chain(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token);
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        $commande = $this->createCommandeValidee($token, $fournisseur['id'], $entrepot['id'], [
            ['produit_id' => $produit['id'], 'quantite' => 10, 'prix_unitaire' => 60],
        ]);

        // Réception complète → stock +10.
        $reception = $this->withToken($token)->postJson("/api/v1/achats/documents/{$commande['id']}/transformer", [
            'type' => 'reception',
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$reception['id']}/valider")->assertOk();

        // Facture issue de la réception → PAS de nouvelle entrée.
        $facture = $this->withToken($token)->postJson("/api/v1/achats/documents/{$reception['id']}/transformer", [
            'type' => 'facture',
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();

        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertJsonPath('data.0.quantite', '10.000');
        $this->withToken($token)->getJson('/api/v1/stock/mouvements')
            ->assertJsonPath('meta.total', 1);

        // Facture DIRECTE (sans source) → entrée implicite +3.
        $directe = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture',
            'tiers_id' => $fournisseur['id'],
            'entrepot_id' => $entrepot['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 3, 'prix_unitaire' => 60]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$directe['id']}/valider")->assertOk();

        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertJsonPath('data.0.quantite', '13.000');

        $mouvements = $this->withToken($token)->getJson('/api/v1/stock/mouvements');
        $mouvements->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.type', 'achat');
    }

    public function test_facture_generates_ac_entry_and_updates_buy_price(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token, 'Cimenterie Atlas');
        $produit = $this->createProduit($token, ['buy_price' => 60]);
        $entrepot = $this->createEntrepot($token);

        // Facture directe : 20 sacs à 65 (nouveau prix) + prestation transport 300 à 14 %.
        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture',
            'tiers_id' => $fournisseur['id'],
            'entrepot_id' => $entrepot['id'],
            'ref_fournisseur' => 'FA-CIM-889',
            'lignes' => [
                ['produit_id' => $produit['id'], 'quantite' => 20, 'prix_unitaire' => 65],
                ['designation' => 'Transport', 'quantite' => 1, 'prix_unitaire' => 300, 'tva_rate' => 14],
            ],
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();

        // Écriture AC : 1300 (6111) + 300 (6117) + 302 TVA (3442) = 1902 (4411).
        $ecritures = $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=AC');
        $ecritures->assertJsonPath('meta.total', 1);

        $lignes = collect($ecritures->json('data.0.lignes'));
        $this->assertSame('1300.00', $lignes->firstWhere('compte_code', '6111')['debit']);
        $this->assertSame('300.00', $lignes->firstWhere('compte_code', '6117')['debit']);
        $this->assertSame('302.00', $lignes->firstWhere('compte_code', '3442')['debit']);
        $this->assertSame('1902.00', $lignes->firstWhere('compte_code', '4411')['credit']);
        $this->assertStringContainsString('FA-CIM-889', $ecritures->json('data.0.libelle'));

        // Le prix d'achat du produit est mis à jour au dernier prix facturé.
        $this->withToken($token)->getJson("/api/v1/produits/{$produit['id']}")
            ->assertJsonPath('data.buy_price', '65.00');
    }

    public function test_paiement_fournisseur_generates_bq_entry_and_settles_facture(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token);
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture',
            'tiers_id' => $fournisseur['id'],
            'entrepot_id' => $entrepot['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 10, 'prix_unitaire' => 100]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();

        // TTC = 1200. Paiement partiel virement 700 puis solde chèque 500.
        $partiel = $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/paiements", [
            'montant' => 700, 'mode' => 'virement', 'reference' => 'VIR-F-01',
        ]);
        $partiel->assertOk()
            ->assertJsonPath('data.statut', 'valide')
            ->assertJsonPath('data.reste_a_payer', '500.00');

        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/paiements", [
            'montant' => 600, 'mode' => 'especes',
        ])->assertUnprocessable();

        $solde = $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/paiements", [
            'montant' => 500, 'mode' => 'cheque',
        ]);
        $solde->assertOk()->assertJsonPath('data.statut', 'paye');

        // Écritures BQ : débit 4411, crédit 5141 (virement) et 5111 (chèque).
        $bq = $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=BQ');
        $bq->assertJsonPath('meta.total', 2);
        $lignes = collect($bq->json('data'))->flatMap(fn ($e) => $e['lignes']);
        $this->assertSame('700.00', $lignes->firstWhere('compte_code', '5141')['credit']);
        $this->assertSame('500.00', $lignes->firstWhere('compte_code', '5111')['credit']);
        $this->assertSame('1200.00', number_format(
            $lignes->where('compte_code', '4411')->sum(fn ($l) => (float) $l['debit']), 2, '.', '',
        ));
    }

    public function test_tva_report_now_includes_recuperable(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token);
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        // Achat : 1000 HT → 200 de TVA récupérable.
        $achat = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture',
            'tiers_id' => $fournisseur['id'],
            'entrepot_id' => $entrepot['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 10, 'prix_unitaire' => 100]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$achat['id']}/valider")->assertOk();

        // Vente : 1700 HT → 340 de TVA facturée.
        $vente = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$vente['id']}/valider")->assertOk();

        $tva = $this->withToken($token)->getJson('/api/v1/compta/tva?mois='.now()->format('Y-m'));
        $tva->assertOk()
            ->assertJsonPath('tva_facturee', '340.00')
            ->assertJsonPath('tva_recuperable', '200.00')
            ->assertJsonPath('tva_due', '140.00');
    }

    public function test_en_commande_column_in_stock_niveaux(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->createFournisseur($token);
        $produit = $this->createProduit($token);
        $entrepot = $this->createEntrepot($token);

        $this->createCommandeValidee($token, $fournisseur['id'], $entrepot['id'], [
            ['produit_id' => $produit['id'], 'quantite' => 100, 'prix_unitaire' => 60],
        ]);

        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertJsonPath('data.0.quantite', '0.000')
            ->assertJsonPath('data.0.en_commande', '100.000');
    }

    public function test_mappings_rattrapage_for_existing_tenants(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Initialisation : 11 mappings, dont les 4 achats.
        $mappings = $this->withToken($token)->getJson('/api/v1/compta/mappings')->json('data');
        $this->assertCount(11, $mappings);
        $this->assertSame('4411', collect($mappings)->firstWhere('cle', 'fournisseurs')['compte_code']);

        // Simule un tenant d'avant le module Achats : mappings achats absents.
        DB::table('compta_mappings')->whereIn('cle', [
            'fournisseurs', 'achats_marchandises', 'achats_services', 'tva_recuperable',
        ])->delete();

        // Le rattrapage les recrée au prochain passage.
        $repare = $this->withToken($token)->getJson('/api/v1/compta/mappings')->json('data');
        $this->assertCount(11, $repare);
        $this->assertSame('3442', collect($repare)->firstWhere('cle', 'tva_recuperable')['compte_code']);
    }

    public function test_achats_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $fournisseurA = $this->createFournisseur($tokenA);
        $produitA = $this->createProduit($tokenA);
        $entrepotA = $this->createEntrepot($tokenA);

        $commande = $this->createCommandeValidee($tokenA, $fournisseurA['id'], $entrepotA['id'], [
            ['produit_id' => $produitA['id'], 'quantite' => 5, 'prix_unitaire' => 60],
        ]);

        $this->withToken($tokenB)->getJson('/api/v1/achats/documents')
            ->assertJsonPath('meta.total', 0);
        $this->withToken($tokenB)->getJson("/api/v1/achats/documents/{$commande['id']}")
            ->assertNotFound();

        // Le tenant B ne peut référencer ni le fournisseur ni l'entrepôt du tenant A.
        $fournisseurB = $this->createFournisseur($tokenB, 'Fournisseur B');
        $this->withToken($tokenB)->postJson('/api/v1/achats/documents', [
            'type' => 'commande',
            'tiers_id' => $fournisseurA['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 10, 'tva_rate' => 20]],
        ])->assertUnprocessable();
        $this->withToken($tokenB)->postJson('/api/v1/achats/documents', [
            'type' => 'commande',
            'tiers_id' => $fournisseurB['id'],
            'entrepot_id' => $entrepotA['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 10, 'tva_rate' => 20]],
        ])->assertUnprocessable()->assertJsonValidationErrors('entrepot_id');
    }
}
