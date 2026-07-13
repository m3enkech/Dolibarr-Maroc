<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Effets / traites (LCN) : à recevoir (3411→3412) et à payer (4411→4412),
 * lettrage de la facture, encaissement/paiement, impayé, gate par feature flag.
 */
class EffetTest extends TestCase
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

    /** Le module effets est désactivé par défaut : on l'active. */
    private function activerEffets(string $token): void
    {
        $this->withToken($token)->putJson('/api/v1/parametres', ['features' => ['effets' => true]])->assertOk();
    }

    private function factureVente(string $token, int $tiersId, float $ht, string $echeance): array
    {
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $tiersId,
            'date_document' => now()->subDays(50)->toDateString(), 'date_echeance' => $echeance,
            'lignes' => [['designation' => 'Marchandise', 'quantite' => 1, 'prix_unitaire' => $ht, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        return $facture;
    }

    private function ecritures(string $token): \Illuminate\Support\Collection
    {
        return collect($this->withToken($token)->getJson('/api/v1/compta/ecritures')->json('data'));
    }

    public function test_effet_a_recevoir_transfers_receivable_and_letters_invoice(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerEffets($token);
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->factureVente($token, $client['id'], 1000, now()->subDays(20)->toDateString());

        // Échue et impayée → présente dans les relances avant l'effet.
        $this->withToken($token)->getJson('/api/v1/relances/a-relancer')->assertJsonCount(1, 'data');

        $effet = $this->withToken($token)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'], 'date_echeance' => now()->addDays(60)->toDateString(),
        ]);
        $effet->assertCreated()
            ->assertJsonPath('data.type', 'recevoir')
            ->assertJsonPath('data.montant', '1200.00')
            ->assertJsonPath('data.statut', 'portefeuille');
        $this->assertStringStartsWith('EFR-', $effet->json('data.code'));

        // Écriture OD : débit 3412 / crédit 3411 pour 1200.
        $od = $this->ecritures($token)->firstWhere('journal', 'OD');
        $lignes = collect($od['lignes']);
        $this->assertSame('1200.00', $lignes->firstWhere('compte_code', '3425')['debit']);
        $this->assertSame('1200.00', $lignes->firstWhere('compte_code', '3421')['credit']);

        // La créance a quitté la balance âgée clients (facture lettrée) et les relances.
        $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=clients')
            ->assertJsonPath('totaux.total', '0.00');
        $this->withToken($token)->getJson('/api/v1/relances/a-relancer')->assertJsonCount(0, 'data');
    }

    public function test_encaissement_of_effet_a_recevoir(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerEffets($token);
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->factureVente($token, $client['id'], 1000, now()->addDays(30)->toDateString());
        $effet = $this->withToken($token)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'], 'date_echeance' => now()->addDays(60)->toDateString(),
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/effets/{$effet['id']}/encaisser")
            ->assertOk()->assertJsonPath('data.statut', 'encaisse');

        // Écriture BQ : débit 5141 banque / crédit 3412.
        $bq = $this->ecritures($token)->firstWhere('journal', 'BQ');
        $lignes = collect($bq['lignes']);
        $this->assertSame('1200.00', $lignes->firstWhere('compte_code', '5141')['debit']);
        $this->assertSame('1200.00', $lignes->firstWhere('compte_code', '3425')['credit']);

        // Un effet encaissé ne se ré-encaisse pas.
        $this->withToken($token)->postJson("/api/v1/effets/{$effet['id']}/encaisser")->assertUnprocessable();
    }

    public function test_impaye_returns_the_receivable(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerEffets($token);
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->factureVente($token, $client['id'], 1000, now()->subDays(20)->toDateString());
        $effet = $this->withToken($token)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'], 'date_echeance' => now()->addDays(60)->toDateString(),
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/effets/{$effet['id']}/impaye")
            ->assertOk()->assertJsonPath('data.statut', 'impaye');

        // La créance revient sur 3411 → réapparaît dans la balance âgée.
        $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=clients')
            ->assertJsonPath('totaux.total', '1200.00');
    }

    public function test_effet_a_payer_full_cycle(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerEffets($token);
        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur Y', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture', 'tiers_id' => $fournisseur['id'],
            'lignes' => [['designation' => 'Fournitures', 'quantite' => 1, 'prix_unitaire' => 2000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();

        $effet = $this->withToken($token)->postJson('/api/v1/effets', [
            'type' => 'payer', 'facture_id' => $facture['id'], 'date_echeance' => now()->addDays(45)->toDateString(),
        ]);
        $effet->assertCreated()->assertJsonPath('data.type', 'payer')->assertJsonPath('data.montant', '2400.00');
        $this->assertStringStartsWith('EFP-', $effet->json('data.code'));

        // La dette a quitté la balance âgée fournisseurs.
        $this->withToken($token)->getJson('/api/v1/compta/balance-agee?type=fournisseurs')
            ->assertJsonPath('totaux.total', '0.00');

        // Paiement à l'échéance : débit 4412 / crédit 5141.
        $this->withToken($token)->postJson("/api/v1/effets/{$effet->json('data.id')}/payer")
            ->assertOk()->assertJsonPath('data.statut', 'paye');
        $bq = $this->ecritures($token)->firstWhere('journal', 'BQ');
        $lignes = collect($bq['lignes']);
        $this->assertSame('2400.00', $lignes->firstWhere('compte_code', '4415')['debit']);
        $this->assertSame('2400.00', $lignes->firstWhere('compte_code', '5141')['credit']);
    }

    public function test_module_is_gated_by_feature_flag(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Désactivé par défaut → 403.
        $this->withToken($token)->getJson('/api/v1/effets')->assertForbidden();

        // Activé → accessible.
        $this->activerEffets($token);
        $this->withToken($token)->getJson('/api/v1/effets')->assertOk();
    }

    public function test_effets_are_tenant_scoped(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $this->activerEffets($tokenA);
        $this->activerEffets($tokenB);
        $client = $this->withToken($tokenA)->postJson('/api/v1/tiers', ['name' => 'Client A'])->json('data');
        $facture = $this->factureVente($tokenA, $client['id'], 1000, now()->addDays(30)->toDateString());
        $this->withToken($tokenA)->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'], 'date_echeance' => now()->addDays(60)->toDateString(),
        ])->assertCreated();

        $this->withToken($tokenB)->getJson('/api/v1/effets')->assertJsonCount(0, 'data');
    }
}
