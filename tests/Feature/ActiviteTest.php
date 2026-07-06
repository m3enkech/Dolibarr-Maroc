<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM — activités : interactions et tâches (à faire), timeline par tiers,
 * gate par le feature flag.
 */
class ActiviteTest extends TestCase
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

    public function test_logging_an_interaction_and_planning_a_task(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->client($token);

        // Interaction passée (journalisée, faite).
        $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'type' => 'appel', 'sujet' => 'Appel de découverte', 'fait' => true,
        ])->assertCreated()->assertJsonPath('data.fait', true);

        // Tâche planifiée en retard.
        $tache = $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'type' => 'tache', 'sujet' => 'Envoyer la proposition',
            'date_prevue' => now()->subDays(2)->toDateString(),
        ]);
        $tache->assertCreated()
            ->assertJsonPath('data.fait', false)
            ->assertJsonPath('data.en_retard', true);
    }

    public function test_a_faire_lists_pending_tasks_by_due_date(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->client($token);

        $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'type' => 'tache', 'sujet' => 'Rappeler demain', 'date_prevue' => now()->addDay()->toDateString(),
        ]);
        $urgent = $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'type' => 'tache', 'sujet' => 'Relancer aujourd\'hui', 'date_prevue' => now()->toDateString(),
        ])->json('data');
        // Une note déjà faite ne doit pas apparaître dans le « à faire ».
        $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'type' => 'note', 'sujet' => 'RAS', 'fait' => true,
        ]);

        $aFaire = $this->withToken($token)->getJson('/api/v1/crm/activites?a_faire=1');
        $aFaire->assertOk()->assertJsonCount(2, 'data');
        // La plus proche échéance en premier.
        $this->assertSame($urgent['id'], $aFaire->json('data.0.id'));
    }

    public function test_toggling_task_done(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->client($token);
        $tache = $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'type' => 'tache', 'sujet' => 'À faire', 'date_prevue' => now()->toDateString(),
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/crm/activites/{$tache['id']}/fait")
            ->assertOk()->assertJsonPath('data.fait', true);

        // Elle quitte le « à faire ».
        $this->withToken($token)->getJson('/api/v1/crm/activites?a_faire=1')->assertJsonCount(0, 'data');

        // On peut la rouvrir.
        $this->withToken($token)->postJson("/api/v1/crm/activites/{$tache['id']}/fait")
            ->assertOk()->assertJsonPath('data.fait', false);
    }

    public function test_timeline_filtered_by_tiers(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->activerCrm($token);
        $a = $this->client($token, 'Client A');
        $b = $this->client($token, 'Client B');

        $this->withToken($token)->postJson('/api/v1/crm/activites', ['tiers_id' => $a['id'], 'type' => 'note', 'sujet' => 'Note A']);
        $this->withToken($token)->postJson('/api/v1/crm/activites', ['tiers_id' => $b['id'], 'type' => 'note', 'sujet' => 'Note B']);

        $this->withToken($token)->getJson("/api/v1/crm/activites?tiers_id={$a['id']}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sujet', 'Note A');
    }

    public function test_module_is_gated_by_feature_flag(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $this->withToken($token)->getJson('/api/v1/crm/activites')->assertForbidden();
        $this->activerCrm($token);
        $this->withToken($token)->getJson('/api/v1/crm/activites')->assertOk();
    }
}
