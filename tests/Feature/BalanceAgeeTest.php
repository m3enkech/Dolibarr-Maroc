<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Balance âgée : solde ouvert (non lettré) des clients/fournisseurs ventilé
 * par tranche d'ancienneté (0-30 / 31-60 / 61-90 / +90 jours).
 */
class BalanceAgeeTest extends TestCase
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

    private function factureVente(string $token, int $tiersId, float $ht, string $date): array
    {
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiersId,
            'date_document' => $date,
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => $ht, 'tva_rate' => 20]],
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        return $facture;
    }

    public function test_receivables_are_bucketed_by_age(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client Atlas'])->json('data');

        // 3 factures de 1200 TTC à des âges différents.
        $this->factureVente($token, $client['id'], 1000, now()->toDateString());            // 0-30
        $this->factureVente($token, $client['id'], 1000, now()->subDays(45)->toDateString()); // 31-60
        $this->factureVente($token, $client['id'], 1000, now()->subDays(100)->toDateString()); // +90

        $response = $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=clients');

        $response->assertOk()
            ->assertJsonPath('type', 'clients')
            ->assertJsonPath('totaux.total', '3600.00')
            ->assertJsonPath('totaux.t0_30', '1200.00')
            ->assertJsonPath('totaux.t31_60', '1200.00')
            ->assertJsonPath('totaux.t61_90', '0.00')
            ->assertJsonPath('totaux.t90_plus', '1200.00');

        $ligne = collect($response->json('data'))->firstWhere('tiers_id', $client['id']);
        $this->assertSame('Client Atlas', $ligne['name']);
        $this->assertSame('3600.00', $ligne['total']);
        $this->assertSame('1200.00', $ligne['t90_plus']);
    }

    public function test_paid_invoice_drops_out_of_open_balance(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Bon payeur'])->json('data');

        $facture = $this->factureVente($token, $client['id'], 1000, now()->subDays(50)->toDateString());

        // Solde ouvert avant paiement : 1200 dans la tranche 31-60.
        $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=clients')
            ->assertJsonPath('totaux.total', '1200.00');

        // Encaissement total puis lettrage automatique du compte clients.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement',
        ])->assertOk();
        $compte3411 = collect($this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data'))
            ->firstWhere('code', '3411')['id'];
        $this->withToken($token)->postJson('/api/v1/compta/lettrage/auto', ['compte_id' => $compte3411])->assertOk();

        // Plus rien d'ouvert : le client disparaît de la balance âgée.
        $response = $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=clients');
        $response->assertJsonPath('totaux.total', '0.00');
        $this->assertCount(0, $response->json('data'));
    }

    public function test_partial_payment_leaves_remaining_open_balance(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client partiel'])->json('data');
        $facture = $this->factureVente($token, $client['id'], 1000, now()->toDateString());

        // Acompte de 500 (non lettré : groupe déséquilibré) → reste ouvert 700.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 500, 'mode' => 'especes',
        ])->assertOk();

        $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=clients')
            ->assertJsonPath('totaux.total', '700.00')
            ->assertJsonPath('totaux.t0_30', '700.00');
    }

    public function test_supplier_aged_balance(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur Rif', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture',
            'tiers_id' => $fournisseur['id'],
            'date_document' => now()->subDays(70)->toDateString(),
            'lignes' => [['designation' => 'Fournitures', 'quantite' => 1, 'prix_unitaire' => 2000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();

        // Dette fournisseur 2400 TTC dans la tranche 61-90.
        $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=fournisseurs')
            ->assertOk()
            ->assertJsonPath('type', 'fournisseurs')
            ->assertJsonPath('totaux.total', '2400.00')
            ->assertJsonPath('totaux.t61_90', '2400.00');

        // Le type clients ne renvoie pas cette dette.
        $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=clients')
            ->assertJsonPath('totaux.total', '0.00');
    }

    public function test_aged_balance_is_tenant_scoped(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $client = $this->withToken($tokenA)->postJson('/api/v1/tiers', ['name' => 'Client A'])->json('data');
        $this->factureVente($tokenA, $client['id'], 1000, now()->toDateString());

        $this->withToken($tokenB)->getJson('/api/v1/compta/balance-agee?type=clients')
            ->assertOk()
            ->assertJsonPath('totaux.total', '0.00')
            ->assertJsonCount(0, 'data');
    }
}
