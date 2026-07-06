<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Identité société : renseigner l'ICE/IF/RC… de sa propre entreprise
 * (emplacements que les PDF, l'e-facture UBL et l'export SIMPL-TVA lisent déjà).
 */
class SocieteTest extends TestCase
{
    use RefreshDatabase;

    private function nouvelleEntreprise(): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme',
            'name' => 'Admin',
            'email' => 'admin@acme.ma',
            'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), $res->json('tenant.id')];
    }

    public function test_identite_societe_persiste_dans_les_settings(): void
    {
        [$token] = $this->nouvelleEntreprise();

        $this->withToken($token)->putJson('/api/v1/parametres/societe', [
            'name' => 'Acme SARL',
            'ice' => '001234567000089',
            'if' => '12345678',
            'rc' => 'RC 4567',
            'city' => 'Casablanca',
        ])->assertOk()
            ->assertJsonPath('data.societe.ice', '001234567000089')
            ->assertJsonPath('data.name', 'Acme SARL');

        // Persisté et relu.
        $this->withToken($token)->getJson('/api/v1/parametres')
            ->assertJsonPath('data.societe.if', '12345678')
            ->assertJsonPath('data.societe.city', 'Casablanca');
    }

    public function test_un_champ_vide_efface_la_valeur(): void
    {
        [$token] = $this->nouvelleEntreprise();

        $this->withToken($token)->putJson('/api/v1/parametres/societe', ['ice' => '001234567000089'])->assertOk();
        $this->withToken($token)->putJson('/api/v1/parametres/societe', ['ice' => ''])
            ->assertOk()
            ->assertJsonPath('data.societe.ice', null);
    }

    public function test_seul_admin_peut_modifier_identite_societe(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise();
        $comptable = User::factory()->create([
            'tenant_id' => $tenantId, 'role' => 'comptable', 'is_active' => true,
        ])->createToken('spa')->plainTextToken;

        $this->withToken($comptable)->putJson('/api/v1/parametres/societe', [
            'ice' => '001234567000089',
        ])->assertForbidden();
    }
}
