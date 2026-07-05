<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComptaTest extends TestCase
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

    /** Crée une facture validée : 2 sacs de ciment (170 HT à 20 %) + 1 service (1000 HT à 20 %). */
    private function createFactureValidee(string $token): array
    {
        $produit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ])->json('data');
        $service = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Pose', 'type' => 'service', 'sell_price' => 1000, 'tva_rate' => 20,
        ])->json('data');
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiers['id'],
            'lignes' => [
                ['produit_id' => $produit['id'], 'quantite' => 2],
                ['produit_id' => $service['id'], 'quantite' => 1],
            ],
        ])->json('data');

        return $this->withToken($token)
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")
            ->json('data');
    }

    public function test_plan_comptable_is_seeded_lazily(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $response = $this->withToken($token)->getJson('/api/v1/compta/comptes');

        $response->assertOk();
        $this->assertGreaterThan(50, count($response->json('data')));

        // Les comptes pivots du PCGM sont présents.
        $codes = collect($response->json('data'))->pluck('code');
        foreach (['3411', '4441', '5141', '5161', '7111', '7114', '6701'] as $code) {
            $this->assertTrue($codes->contains($code), "Compte {$code} manquant");
        }

        // Les 7 comptes par défaut sont mappés.
        $mappings = $this->withToken($token)->getJson('/api/v1/compta/mappings')->json('data');
        $this->assertCount(7, $mappings);
        $this->assertSame('3411', collect($mappings)->firstWhere('cle', 'clients')['compte_code']);
    }

    public function test_facture_validation_generates_balanced_vt_entry(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $facture = $this->createFactureValidee($token);

        $ecritures = $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=VT');
        $ecritures->assertOk()->assertJsonPath('meta.total', 1);

        $ecriture = $ecritures->json('data.0');
        $this->assertSame('VT-'.now()->year.'-00001', $ecriture['numero']);
        $this->assertTrue($ecriture['is_auto']);
        $this->assertSame($facture['code'], $ecriture['reference']);

        // Facture : 170 HT marchandises + 1000 HT services + 234 TVA = 1404 TTC.
        $lignes = collect($ecriture['lignes']);
        $this->assertSame('1404.00', $lignes->firstWhere('compte_code', '3411')['debit']);
        $this->assertSame('170.00', $lignes->firstWhere('compte_code', '7111')['credit']);
        $this->assertSame('1000.00', $lignes->firstWhere('compte_code', '7114')['credit']);
        $this->assertSame('234.00', $lignes->firstWhere('compte_code', '4441')['credit']);

        // Partie double.
        $this->assertSame(
            $lignes->sum(fn ($l) => (float) $l['debit']),
            $lignes->sum(fn ($l) => (float) $l['credit']),
        );
    }

    public function test_paiement_generates_bq_entry_with_mode_account(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $facture = $this->createFactureValidee($token);

        // Espèces → caisse 5161.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 404, 'mode' => 'especes',
        ])->assertOk();

        // Virement → banque 5141.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1000, 'mode' => 'virement',
        ])->assertOk();

        $ecritures = $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=BQ');
        $ecritures->assertJsonPath('meta.total', 2);

        $parCompte = collect($ecritures->json('data'))->flatMap(fn ($e) => $e['lignes']);
        $this->assertSame('404.00', $parCompte->firstWhere('compte_code', '5161')['debit']);
        $this->assertSame('1000.00', $parCompte->firstWhere('compte_code', '5141')['debit']);
        $this->assertSame('1404.00', number_format(
            $parCompte->where('compte_code', '3411')->sum(fn ($l) => (float) $l['credit']), 2, '.', '',
        ));
    }

    public function test_unbalanced_manual_entry_is_rejected(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $comptes = collect($this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data'));
        $banque = $comptes->firstWhere('code', '5141');
        $capital = $comptes->firstWhere('code', '1111');

        // Déséquilibrée → 422.
        $this->withToken($token)->postJson('/api/v1/compta/ecritures', [
            'libelle' => 'Apport en capital',
            'lignes' => [
                ['compte_id' => $banque['id'], 'debit' => 100000],
                ['compte_id' => $capital['id'], 'credit' => 90000],
            ],
        ])->assertUnprocessable();

        // Équilibrée → créée en OD.
        $ok = $this->withToken($token)->postJson('/api/v1/compta/ecritures', [
            'libelle' => 'Apport en capital',
            'lignes' => [
                ['compte_id' => $banque['id'], 'debit' => 100000],
                ['compte_id' => $capital['id'], 'credit' => 100000],
            ],
        ]);
        $ok->assertCreated()->assertJsonPath('data.journal', 'OD');
        $this->assertStringStartsWith('OD-', $ok->json('data.numero'));
    }

    public function test_mapping_change_is_used_by_next_entry(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Le comptable crée un sous-compte de vente dédié et le mappe.
        $nouveau = $this->withToken($token)->postJson('/api/v1/compta/comptes', [
            'code' => '71141', 'label' => 'Ventes de services — conseil',
        ])->json('data');

        $this->withToken($token)->putJson('/api/v1/compta/mappings', [
            'cle' => 'ventes_services', 'compte_id' => $nouveau['id'],
        ])->assertOk();

        $this->createFactureValidee($token);

        $ecriture = $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=VT')->json('data.0');
        $lignes = collect($ecriture['lignes']);

        // La part services est passée sur le nouveau compte.
        $this->assertSame('1000.00', $lignes->firstWhere('compte_code', '71141')['credit']);
        $this->assertNull($lignes->firstWhere('compte_code', '7114'));
    }

    public function test_balance_and_tva_reports(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $facture = $this->createFactureValidee($token);

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1404, 'mode' => 'virement',
        ])->assertOk();

        $balance = $this->withToken($token)->getJson('/api/v1/compta/balance');
        $balance->assertOk();
        $lignes = collect($balance->json('data'));

        // Clients : débit 1404 (facture), crédit 1404 (encaissement) → soldé.
        $clients = $lignes->firstWhere('code', '3411');
        $this->assertSame('1404.00', $clients['total_debit']);
        $this->assertSame('1404.00', $clients['total_credit']);
        $this->assertSame('0.00', $clients['solde_debiteur']);

        // Banque : débit 1404.
        $this->assertSame('1404.00', $lignes->firstWhere('code', '5141')['solde_debiteur']);

        // La balance est équilibrée.
        $this->assertSame($balance->json('totaux.debit'), $balance->json('totaux.credit'));

        // État TVA du mois : 234 facturée, rien de récupérable.
        $tva = $this->withToken($token)->getJson('/api/v1/compta/tva?mois='.now()->format('Y-m'));
        $tva->assertOk()
            ->assertJsonPath('tva_facturee', '234.00')
            ->assertJsonPath('tva_recuperable', '0.00')
            ->assertJsonPath('tva_due', '234.00');
    }

    public function test_compta_is_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $this->createFactureValidee($tokenA);

        $this->withToken($tokenB)->getJson('/api/v1/compta/ecritures')
            ->assertJsonPath('meta.total', 0);

        // Chaque tenant a son propre plan comptable (comptes distincts).
        $comptesA = collect($this->withToken($tokenA)->getJson('/api/v1/compta/comptes')->json('data'));
        $comptesB = collect($this->withToken($tokenB)->getJson('/api/v1/compta/comptes')->json('data'));
        $this->assertEmpty($comptesA->pluck('id')->intersect($comptesB->pluck('id')));
    }
}
