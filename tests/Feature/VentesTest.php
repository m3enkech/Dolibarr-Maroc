<?php

namespace Tests\Feature;

use App\Modules\Ventes\Events\FactureValidee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class VentesTest extends TestCase
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

    private function createTiers(string $token, string $name = 'Client Test'): array
    {
        return $this->withToken($token)
            ->postJson('/api/v1/tiers', ['name' => $name])
            ->json('data');
    }

    private function createProduit(string $token, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', array_merge([
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ], $overrides))->json('data');
    }

    private function createDevis(string $token, int $tiersId, array $lignes): array
    {
        return $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis',
            'tiers_id' => $tiersId,
            'lignes' => $lignes,
        ])->json('data');
    }

    public function test_totals_are_computed_with_discount_and_tva(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $response = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis',
            'tiers_id' => $tiers['id'],
            'lignes' => [
                // 10 × 100 − 10 % = 900 HT, TVA 20 % = 180
                ['designation' => 'Prestation A', 'quantite' => 10, 'prix_unitaire' => 100, 'remise_percent' => 10, 'tva_rate' => 20],
                // 2 × 50 = 100 HT, TVA 7 % = 7
                ['designation' => 'Prestation B', 'quantite' => 2, 'prix_unitaire' => 50, 'tva_rate' => 7],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'DE-'.now()->year.'-00001')
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.total_ht', '1000.00')
            ->assertJsonPath('data.total_tva', '187.00')
            ->assertJsonPath('data.total_ttc', '1187.00');
    }

    public function test_line_defaults_come_from_produit(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);
        $produit = $this->createProduit($token, ['sell_price' => 85, 'tva_rate' => 14]);

        $devis = $this->createDevis($token, $tiers['id'], [
            ['produit_id' => $produit['id'], 'quantite' => 2],
        ]);

        $this->assertSame('Ciment 50kg', $devis['lignes'][0]['designation']);
        $this->assertSame('85.00', $devis['lignes'][0]['prix_unitaire']);
        $this->assertSame('14.00', $devis['lignes'][0]['tva_rate']);
        $this->assertSame('170.00', $devis['total_ht']);
    }

    public function test_facture_gets_definitive_number_only_at_validation(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);
        $year = now()->year;

        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->json('data');

        $this->assertSame("PROV-{$year}-00001", $facture['code']);

        $validated = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider");

        $validated->assertOk()
            ->assertJsonPath('data.code', "FA-{$year}-00001")
            ->assertJsonPath('data.statut', 'valide');
    }

    public function test_validated_documents_cannot_be_modified_or_deleted(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $devis = $this->createDevis($token, $tiers['id'], [
            ['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20],
        ]);

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/valider")->assertOk();

        $this->withToken($token)->putJson("/api/v1/ventes/documents/{$devis['id']}", [
            'notes' => 'modif interdite',
        ])->assertUnprocessable();

        $this->withToken($token)->deleteJson("/api/v1/ventes/documents/{$devis['id']}")
            ->assertUnprocessable();
    }

    public function test_devis_can_be_transformed_into_facture_with_lines_copied(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $devis = $this->createDevis($token, $tiers['id'], [
            ['designation' => 'Prestation', 'quantite' => 3, 'prix_unitaire' => 200, 'tva_rate' => 20],
        ]);

        // Un brouillon ne peut pas être transformé.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/transformer", [
            'type' => 'facture',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/valider")->assertOk();

        $facture = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devis['id']}/transformer", [
            'type' => 'facture',
        ]);

        $facture->assertOk()
            ->assertJsonPath('data.type', 'facture')
            ->assertJsonPath('data.statut', 'brouillon')
            ->assertJsonPath('data.total_ttc', '720.00')
            ->assertJsonPath('data.source.code', $devis['code'])
            ->assertJsonCount(1, 'data.lignes');

        // Le devis source passe en "accepte".
        $this->withToken($token)->getJson("/api/v1/ventes/documents/{$devis['id']}")
            ->assertJsonPath('data.statut', 'accepte');
    }

    public function test_paiements_flow_partial_then_full_then_overpay_rejected(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 1000, 'tva_rate' => 20]],
        ])->json('data');

        // Paiement sur brouillon interdit.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 100, 'mode' => 'virement',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        // Paiement partiel : 500 / 1200.
        $partial = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 500, 'mode' => 'virement', 'reference' => 'VIR-001',
        ]);
        $partial->assertOk()
            ->assertJsonPath('data.statut', 'valide')
            ->assertJsonPath('data.reste_a_payer', '700.00');

        // Dépassement rejeté.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 800, 'mode' => 'especes',
        ])->assertUnprocessable();

        // Solde : la facture passe en "paye".
        $full = $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 700, 'mode' => 'cheque',
        ]);
        $full->assertOk()
            ->assertJsonPath('data.statut', 'paye')
            ->assertJsonPath('data.reste_a_payer', '0.00');
    }

    public function test_documents_are_isolated_per_tenant_and_foreign_produit_rejected(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $tiersA = $this->createTiers($tokenA);
        $produitA = $this->createProduit($tokenA);
        $tiersB = $this->createTiers($tokenB, 'Client B');

        $devisA = $this->createDevis($tokenA, $tiersA['id'], [
            ['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 10, 'tva_rate' => 20],
        ]);

        $this->withToken($tokenB)->getJson('/api/v1/ventes/documents')
            ->assertJsonPath('meta.total', 0);
        $this->withToken($tokenB)->getJson("/api/v1/ventes/documents/{$devisA['id']}")
            ->assertNotFound();

        // Le tenant B ne peut pas référencer le tiers ou le produit du tenant A.
        $this->withToken($tokenB)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis',
            'tiers_id' => $tiersA['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('tiers_id');

        $this->withToken($tokenB)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis',
            'tiers_id' => $tiersB['id'],
            'lignes' => [['produit_id' => $produitA['id'], 'quantite' => 1]],
        ])->assertUnprocessable();
    }

    public function test_pdf_is_generated(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $devis = $this->createDevis($token, $tiers['id'], [
            ['designation' => 'Étude technique', 'quantite' => 1, 'prix_unitaire' => 5000, 'tva_rate' => 20],
        ]);

        $response = $this->withToken($token)->get("/api/v1/ventes/documents/{$devis['id']}/pdf");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_facture_validee_event_is_dispatched(): void
    {
        Event::fake([FactureValidee::class]);

        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->createTiers($token);

        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->json('data');

        Event::assertNotDispatched(FactureValidee::class);

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        Event::assertDispatched(FactureValidee::class, fn (FactureValidee $event) => $event->document->id === $facture['id']);
    }
}
