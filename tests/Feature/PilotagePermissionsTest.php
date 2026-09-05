<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'écran de suivi croise ventes, achats et stock. `permission:` étant un ET
 * logique, une garde de route fermerait la page au commercial (achats: none) et
 * au caissier (ni ventes ni achats). Ces tests gardent la décision : la page
 * s'ouvre pour tout le monde, et chacun ne reçoit que ce qu'il a le droit de
 * voir.
 */
class PilotagePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function nouvelleEntreprise(): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Admin',
            'email' => 'admin@acme.ma', 'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), $res->json('tenant.id')];
    }

    private function tokenPourRole(int $tenantId, string $role): string
    {
        return User::factory()->create([
            'tenant_id' => $tenantId, 'role' => $role, 'is_active' => true,
        ])->createToken('spa')->plainTextToken;
    }

    /* ---------------------------------------------------------------- */

    public function test_le_caissier_ouvre_la_page_sans_voir_ventes_ni_achats(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise();
        $token = $this->tokenPourRole($tenantId, 'caissier');

        $reponse = $this->withToken($token)->getJson('/api/v1/pilotage/flux')->assertOk();

        $reponse->assertJsonPath('data.capabilities.ventes', false)
            ->assertJsonPath('data.capabilities.achats', false)
            ->assertJsonPath('data.capabilities.stock', true);

        // ABSENTS, pas à zéro : afficher « 0 facture impayée » à qui n'a pas
        // accès aux ventes serait un mensonge, pas une restriction.
        $reponse->assertJsonMissingPath('data.ventes')
            ->assertJsonMissingPath('data.achats');

        // Le stock, lui, est bien servi : c'est son domaine.
        $reponse->assertJsonStructure(['data' => ['stock' => ['references_actives', 'en_rupture', 'sous_seuil']]]);
    }

    public function test_le_commercial_voit_les_ventes_mais_pas_les_achats(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise();
        $token = $this->tokenPourRole($tenantId, 'commercial');

        $this->withToken($token)->getJson('/api/v1/pilotage/flux')->assertOk()
            ->assertJsonPath('data.capabilities.ventes', true)
            ->assertJsonPath('data.capabilities.achats', false)
            ->assertJsonStructure(['data' => ['ventes' => ['commandes_ouvertes', 'factures_impayees']]])
            ->assertJsonMissingPath('data.achats');
    }

    public function test_le_comptable_et_le_role_lecture_voient_les_deux_blocs(): void
    {
        [, $tenantId] = $this->nouvelleEntreprise();

        foreach (['comptable', 'lecture'] as $role) {
            $this->withToken($this->tokenPourRole($tenantId, $role))
                ->getJson('/api/v1/pilotage/flux')->assertOk()
                ->assertJsonPath('data.capabilities.ventes', true)
                ->assertJsonPath('data.capabilities.achats', true);
        }
    }

    public function test_l_administrateur_voit_tout(): void
    {
        [$token] = $this->nouvelleEntreprise();

        $this->withToken($token)->getJson('/api/v1/pilotage/flux')->assertOk()
            ->assertJsonStructure([
                'data' => ['capabilities', 'genere_a', 'indisponible', 'ventes', 'achats', 'stock'],
            ]);
    }

    /**
     * Les chiffres qu'on refuse de publier voyagent avec leur raison. Un test
     * les fige : les retirer doit être un choix explicite, pas un oubli qui
     * laisserait quelqu'un les remplacer un jour par une approximation.
     */
    public function test_les_indicateurs_non_calculables_sont_annonces_avec_leur_raison(): void
    {
        [$token] = $this->nouvelleEntreprise();

        $indisponible = $this->withToken($token)->getJson('/api/v1/pilotage/flux')
            ->assertOk()->json('data.indisponible');

        $cles = array_column($indisponible, 'cle');
        $this->assertContains('commandes_facturees', $cles);
        $this->assertContains('livraisons_en_transit', $cles);

        foreach ($indisponible as $entree) {
            $this->assertNotEmpty($entree['raison'], "L'indicateur {$entree['cle']} doit dire pourquoi il manque.");
        }
    }

    public function test_un_visiteur_non_authentifie_n_entre_pas(): void
    {
        $this->getJson('/api/v1/pilotage/flux')->assertUnauthorized();
    }
}
