<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tableau de bord agrégé : structure des KPIs, séries, alertes, et
 * adaptation du contenu aux droits de l'utilisateur.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email = 'admin@acme.ma'): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme',
            'name' => 'Admin',
            'email' => $email,
            'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), $res->json('tenant.id')];
    }

    /** Crée une facture validée d'un montant HT donné et renvoie son id. */
    private function factureValidee(string $token, int $tiersId, int $produitId, float $prix): void
    {
        $doc = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiersId,
            'lignes' => [
                ['produit_id' => $produitId, 'designation' => 'Ligne', 'quantite' => 1, 'prix_unitaire' => $prix, 'tva_rate' => 20],
            ],
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$doc['id']}/valider")->assertOk();
    }

    public function test_structure_et_ca_du_mois(): void
    {
        [$token, $tenantId] = $this->register();
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client A'])->json('data');
        $produit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment', 'type' => 'product', 'sell_price' => 100, 'tva_rate' => 20,
        ])->json('data');

        $this->factureValidee($token, $tiers['id'], $produit['id'], 5000);

        $res = $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk();

        // Admin : tous les blocs.
        $res->assertJsonPath('data.capabilities.ventes', true)
            ->assertJsonPath('data.capabilities.compta', true)
            ->assertJsonPath('data.kpis.ca_mois.value', 5000)
            ->assertJsonPath('data.repartition_ventes.factures', 1);

        // Série 12 mois complète, dernier mois = CA courant.
        $serie = $res->json('data.ventes_12_mois');
        $this->assertCount(12, $serie);
        $this->assertSame(5000.0, (float) end($serie)['ca']);

        // Top clients / produits alimentés.
        $this->assertSame('Client A', $res->json('data.top_clients.0.name'));
        $this->assertSame('Ciment', $res->json('data.top_produits.0.name'));

        // KPIs comptables présents pour l'admin.
        $this->assertArrayHasKey('resultat', $res->json('data.kpis'));
        $this->assertArrayHasKey('creances', $res->json('data.kpis'));
        $this->assertArrayHasKey('stock_sous_seuil', $res->json('data.alertes'));
    }

    public function test_variation_vs_mois_precedent(): void
    {
        [$token] = $this->register();
        $res = $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk();

        // Sans historique : structure de variation présente (valeur nulle possible).
        $this->assertArrayHasKey('variation_pct', $res->json('data.kpis.ca_mois'));
        $this->assertArrayHasKey('previous', $res->json('data.kpis.ca_mois'));
    }

    public function test_commercial_ne_voit_pas_les_kpis_comptables(): void
    {
        [, $tenantId] = $this->register();
        $token = User::factory()->create([
            'tenant_id' => $tenantId, 'role' => 'commercial', 'is_active' => true,
        ])->createToken('spa')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk();

        $res->assertJsonPath('data.capabilities.ventes', true)
            ->assertJsonPath('data.capabilities.compta', false);

        $kpis = $res->json('data.kpis');
        $this->assertArrayHasKey('ca_mois', $kpis);
        $this->assertArrayNotHasKey('resultat', $kpis);
        $this->assertArrayNotHasKey('tresorerie', $kpis);
        $this->assertArrayNotHasKey('creances', $kpis);
    }

    public function test_caissier_tableau_minimal(): void
    {
        [, $tenantId] = $this->register();
        $token = User::factory()->create([
            'tenant_id' => $tenantId, 'role' => 'caissier', 'is_active' => true,
        ])->createToken('spa')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk();

        $res->assertJsonPath('data.capabilities.ventes', false)
            ->assertJsonPath('data.capabilities.compta', false);
        $this->assertArrayNotHasKey('ca_mois', $res->json('data.kpis'));
        $this->assertEmpty($res->json('data.ventes_12_mois'));
    }
}
