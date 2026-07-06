<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM — pipeline d'opportunités : kanban par étape, déplacement, gagné/perdu,
 * statistiques, gate par le feature flag.
 */
class CrmTest extends TestCase
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

    private function activerCrm(string $token): void
    {
        $this->withToken($token)->putJson('/api/v1/parametres', ['features' => ['crm' => true]])->assertOk();
    }

    private function client(string $token, string $name = 'Client X'): array
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', ['name' => $name])->json('data');
    }

    private function opportunite(string $token, int $tiersId, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/crm/opportunites', array_merge([
            'tiers_id' => $tiersId, 'titre' => 'Projet site web', 'montant_estime' => 10000, 'probabilite' => 50,
        ], $overrides))->json('data');
    }

    public function test_pipeline_board_groups_by_stage_with_stats(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->client($token);

        $opp = $this->opportunite($token, $client['id'], ['montant_estime' => 20000, 'probabilite' => 25]);
        $this->opportunite($token, $client['id'], ['titre' => 'Maintenance', 'montant_estime' => 5000, 'probabilite' => 80, 'etape' => 'qualifie']);

        $board = $this->withToken($token)->getJson('/api/v1/crm/opportunites');
        $board->assertOk()
            ->assertJsonPath('stats.ouvertes', 2)
            ->assertJsonPath('stats.total_pipeline', '25000.00')
            // forecast pondéré = 20000×0.25 + 5000×0.80 = 5000 + 4000 = 9000
            ->assertJsonPath('stats.forecast_pondere', '9000.00');

        $this->assertCount(1, $board->json('colonnes.nouveau'));
        $this->assertCount(1, $board->json('colonnes.qualifie'));
        $this->assertSame($opp['id'], $board->json('colonnes.nouveau.0.id'));
        $this->assertSame('OPP-'.now()->year.'-00001', $opp['code']);
    }

    public function test_moving_opportunity_between_stages(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $opp = $this->opportunite($token, $this->client($token)['id']);

        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$opp['id']}/deplacer", [
            'etape' => 'proposition',
        ])->assertOk()->assertJsonPath('data.etape', 'proposition');

        $board = $this->withToken($token)->getJson('/api/v1/crm/opportunites');
        $this->assertCount(0, $board->json('colonnes.nouveau'));
        $this->assertCount(1, $board->json('colonnes.proposition'));

        // Étape invalide refusée.
        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$opp['id']}/deplacer", [
            'etape' => 'inexistante',
        ])->assertUnprocessable();
    }

    public function test_winning_and_losing_opportunities(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->client($token);
        $gagnee = $this->opportunite($token, $client['id'], ['montant_estime' => 15000]);
        $perdue = $this->opportunite($token, $client['id'], ['titre' => 'Autre', 'montant_estime' => 8000]);

        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$gagnee['id']}/cloturer", [
            'statut' => 'gagnee',
        ])->assertOk()->assertJsonPath('data.statut', 'gagnee')->assertJsonPath('data.probabilite', 100);

        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$perdue['id']}/cloturer", [
            'statut' => 'perdue',
        ])->assertOk()->assertJsonPath('data.probabilite', 0);

        // Le board ne montre plus que les ouvertes ; gagnées comptées à part.
        $this->withToken($token)->getJson('/api/v1/crm/opportunites')
            ->assertJsonPath('stats.ouvertes', 0)
            ->assertJsonPath('stats.gagnees_montant', '15000.00');

        // Une opportunité clôturée ne se déplace plus.
        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$gagnee['id']}/deplacer", [
            'etape' => 'qualifie',
        ])->assertUnprocessable();

        // Historique des clôturées.
        $this->withToken($token)->getJson('/api/v1/crm/opportunites/closes?statut=gagnee')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_module_is_gated_by_feature_flag(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        // Désactivé par défaut → 403.
        $this->withToken($token)->getJson('/api/v1/crm/opportunites')->assertForbidden();

        $this->activerCrm($token);
        $this->withToken($token)->getJson('/api/v1/crm/opportunites')->assertOk();
    }

    public function test_generating_a_devis_from_an_opportunity(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->client($token);
        $opp = $this->opportunite($token, $client['id'], ['titre' => 'Refonte', 'montant_estime' => 30000]);

        $response = $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$opp['id']}/devis");
        $response->assertCreated();
        $devisId = $response->json('devis_id');
        $this->assertStringStartsWith('DE-', $response->json('devis_code'));

        // Le devis existe, pour le bon client, avec une ligne au montant estimé.
        $devis = $this->withToken($token)->getJson("/api/v1/ventes/documents/{$devisId}")->json('data');
        $this->assertSame('devis', $devis['type']);
        $this->assertSame($client['id'], $devis['tiers_id']);
        $this->assertSame('30000.00', $devis['total_ht']);
        $this->assertSame('Refonte', $devis['lignes'][0]['designation']);

        // L'opportunité a avancé à l'étape « proposition ».
        $board = $this->withToken($token)->getJson('/api/v1/crm/opportunites');
        $this->assertCount(1, $board->json('colonnes.proposition'));
    }

    public function test_client_timeline_aggregates_activities_opportunities_documents(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->client($token);

        $this->opportunite($token, $client['id'], ['titre' => 'Affaire A']);
        $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'type' => 'appel', 'sujet' => 'Premier contact', 'fait' => true,
        ]);
        $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'Étude', 'quantite' => 1, 'prix_unitaire' => 5000, 'tva_rate' => 20]],
        ]);

        $timeline = $this->withToken($token)->getJson("/api/v1/crm/tiers/{$client['id']}/timeline");
        $timeline->assertOk();
        $kinds = collect($timeline->json('data'))->pluck('kind');
        $this->assertCount(3, $kinds);
        $this->assertEqualsCanonicalizing(['activite', 'opportunite', 'document'], $kinds->all());
    }

    public function test_timeline_is_gated_and_scoped(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $client = $this->client($tokenA);

        // CRM off → 403.
        $this->withToken($tokenA)->getJson("/api/v1/crm/tiers/{$client['id']}/timeline")->assertForbidden();
    }

    public function test_opportunities_are_tenant_scoped(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $this->activerCrm($tokenA);
        $this->activerCrm($tokenB);
        $this->opportunite($tokenA, $this->client($tokenA)['id']);

        $board = $this->withToken($tokenB)->getJson('/api/v1/crm/opportunites');
        $board->assertJsonPath('stats.ouvertes', 0);
        $this->assertCount(0, $board->json('colonnes.nouveau'));
    }
}
