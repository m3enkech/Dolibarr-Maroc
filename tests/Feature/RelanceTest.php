<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Relances / recouvrement : worklist des factures échues non soldées,
 * historique par facture, lettre PDF, et gate par le feature flag.
 */
class RelanceTest extends TestCase
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

    /** Facture validée avec date de pièce et échéance données. */
    private function facture(string $token, int $tiersId, float $ht, string $dateDoc, string $echeance): array
    {
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiersId,
            'date_document' => $dateDoc,
            'date_echeance' => $echeance,
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => $ht, 'tva_rate' => 20]],
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        return $facture;
    }

    public function test_worklist_lists_only_overdue_unpaid_invoices(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');

        // Échue depuis 30 j, impayée → doit apparaître.
        $echue = $this->facture($token, $client['id'], 1000, now()->subDays(60)->toDateString(), now()->subDays(30)->toDateString());
        // Pas encore échue → exclue.
        $this->facture($token, $client['id'], 500, now()->toDateString(), now()->addDays(30)->toDateString());
        // Échue mais payée → exclue.
        $payee = $this->facture($token, $client['id'], 800, now()->subDays(50)->toDateString(), now()->subDays(20)->toDateString());
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$payee['id']}/paiements", [
            'montant' => 960, 'mode' => 'virement',
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/v1/relances/a-relancer');
        $response->assertOk();
        $data = collect($response->json('data'));

        $this->assertCount(1, $data);
        $ligne = $data->first();
        $this->assertSame($echue['id'], $ligne['document_vente_id']);
        $this->assertSame(30, $ligne['jours_retard']);
        $this->assertSame('1200.00', $ligne['reste_a_payer']);
        $this->assertSame(0, $ligne['nb_relances']);
        $this->assertNull($ligne['dernier_niveau']);
    }

    public function test_recording_a_relance_updates_history_and_worklist(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->facture($token, $client['id'], 1000, now()->subDays(60)->toDateString(), now()->subDays(40)->toDateString());

        $this->withToken($token)->postJson('/api/v1/relances', [
            'document_vente_id' => $facture['id'], 'niveau' => 1, 'canal' => 'email',
        ])->assertCreated()->assertJsonPath('data.niveau_label', 'Rappel');

        $this->withToken($token)->postJson('/api/v1/relances', [
            'document_vente_id' => $facture['id'], 'niveau' => 2, 'note' => 'Relance téléphonique aussi',
        ])->assertCreated();

        // Historique : 2 relances, la plus récente d'abord.
        $historique = $this->withToken($token)->getJson("/api/v1/relances/{$facture['id']}/historique")->json('data');
        $this->assertCount(2, $historique);
        $this->assertSame(2, $historique[0]['niveau']);

        // Worklist : dernier niveau = 2, nb = 2.
        $ligne = collect($this->withToken($token)->getJson('/api/v1/relances/a-relancer')->json('data'))->first();
        $this->assertSame(2, $ligne['dernier_niveau']);
        $this->assertSame(2, $ligne['nb_relances']);
    }

    public function test_cannot_relance_a_settled_invoice(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->facture($token, $client['id'], 1000, now()->subDays(60)->toDateString(), now()->subDays(40)->toDateString());
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/relances', [
            'document_vente_id' => $facture['id'], 'niveau' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('document_vente_id');
    }

    public function test_lettre_pdf_is_generated(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->facture($token, $client['id'], 1000, now()->subDays(60)->toDateString(), now()->subDays(40)->toDateString());

        $response = $this->withToken($token)->get("/api/v1/relances/{$facture['id']}/lettre?niveau=3");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_module_is_gated_by_feature_flag(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Activé par défaut : accessible.
        $this->withToken($token)->getJson('/api/v1/relances/a-relancer')->assertOk();

        // Désactivé dans les paramètres → 403.
        $this->withToken($token)->putJson('/api/v1/parametres', ['features' => ['relances' => false]])->assertOk();
        $this->withToken($token)->getJson('/api/v1/relances/a-relancer')->assertForbidden();
    }

    public function test_relances_are_tenant_scoped(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $client = $this->withToken($tokenA)->postJson('/api/v1/tiers', ['name' => 'Client A'])->json('data');
        $this->facture($tokenA, $client['id'], 1000, now()->subDays(60)->toDateString(), now()->subDays(40)->toDateString());

        $this->withToken($tokenB)->getJson('/api/v1/relances/a-relancer')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}
