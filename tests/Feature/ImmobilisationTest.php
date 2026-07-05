<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImmobilisationTest extends TestCase
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

    private function creerImmo(string $token, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/compta/immobilisations', array_merge([
            'label' => 'Ordinateur portable',
            'category' => 'materiel_informatique',
            'date_acquisition' => '2024-01-01',
            'valeur_acquisition' => 12000,
            'duree_annees' => 5,
        ], $overrides))->json('data');
    }

    private function compteId(string $token, string $code): int
    {
        $comptes = $this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data');

        return collect($comptes)->firstWhere('code', $code)['id'];
    }

    public function test_category_defaults_accounts_and_duration(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $immo = $this->withToken($token)->postJson('/api/v1/compta/immobilisations', [
            'label' => 'Camionnette',
            'category' => 'materiel_transport',
            'date_acquisition' => '2024-06-15',
            'valeur_acquisition' => 200000,
        ]);

        $immo->assertCreated()
            ->assertJsonPath('data.code', 'IM-'.now()->year.'-00001')
            ->assertJsonPath('data.duree_annees', 5)      // défaut transport
            ->assertJsonPath('data.compte_immo', '2340')
            ->assertJsonPath('data.compte_amort', '2924')
            ->assertJsonPath('data.vna', '200000.00');    // rien encore amorti
    }

    public function test_plan_full_year_when_acquired_january_first(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $immo = $this->creerImmo($token); // 12000 sur 5 ans, acquis 01/01/2024

        $detail = $this->withToken($token)->getJson("/api/v1/compta/immobilisations/{$immo['id']}");
        $plan = collect($detail->json('plan'));

        // 5 annuités pleines de 2400, cumul final = 12000, VNA finale = 0.
        $this->assertCount(5, $plan);
        $this->assertSame('2400.00', $plan->first()['dotation']);
        $this->assertSame('2024', (string) $plan->first()['annee']);
        $this->assertSame('12000.00', $plan->last()['cumul']);
        $this->assertSame('0.00', $plan->last()['vna']);
    }

    public function test_plan_prorata_first_year_when_acquired_mid_year(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        // Acquis 01/10/2024 : 3 mois en 2024 (oct, nov, déc).
        $immo = $this->creerImmo($token, ['date_acquisition' => '2024-10-01']);

        $plan = collect($this->withToken($token)->getJson("/api/v1/compta/immobilisations/{$immo['id']}")->json('plan'));

        // Mensualité = 12000/60 = 200. Première année : 3 × 200 = 600.
        $this->assertSame('600.00', $plan->firstWhere('annee', 2024)['dotation']);
        $this->assertSame('2400.00', $plan->firstWhere('annee', 2025)['dotation']);
        // Le plan s'étale sur 6 années civiles (2024→2029), la dernière solde le reste.
        $this->assertCount(6, $plan);
        $this->assertSame('12000.00', $plan->last()['cumul']);
        $this->assertSame('0.00', $plan->last()['vna']);
        // Somme des dotations = valeur d'acquisition.
        $this->assertSame(12000.0, round($plan->sum(fn ($l) => (float) $l['dotation']), 2));
    }

    public function test_annual_dotation_posts_balanced_entry_and_is_idempotent(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->creerImmo($token); // informatique 12000/5, dotation 2024 = 2400
        $this->creerImmo($token, [
            'label' => 'Bureau', 'category' => 'mobilier_bureau',
            'date_acquisition' => '2024-01-01', 'valeur_acquisition' => 10000, 'duree_annees' => 10,
        ]); // mobilier 10000/10, dotation 2024 = 1000

        $gen = $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2024]);
        $gen->assertOk()
            ->assertJsonPath('immobilisations', 2)
            ->assertJsonPath('total', '3400.00');

        // Écriture OD : débit 6161 = 3400 ; crédits 2926 = 2400 et 2925 = 1000.
        $od = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=OD&search=DOT')->json('data'))->first();
        $lignes = collect($od['lignes']);
        $this->assertSame('3400.00', $lignes->firstWhere('compte_code', '6161')['debit']);
        $this->assertSame('2400.00', $lignes->firstWhere('compte_code', '2926')['credit']);
        $this->assertSame('1000.00', $lignes->firstWhere('compte_code', '2925')['credit']);

        // Relancer la même année ne crée aucune nouvelle dotation.
        $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2024])
            ->assertOk()->assertJsonPath('immobilisations', 0);

        $ods = $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=OD&search=DOT');
        $this->assertSame(1, $ods->json('meta.total'));
    }

    public function test_vna_reflects_posted_dotations(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $immo = $this->creerImmo($token); // 12000/5, 2400/an

        $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2024])->assertOk();
        $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2025])->assertOk();

        $detail = $this->withToken($token)->getJson("/api/v1/compta/immobilisations/{$immo['id']}");
        $detail->assertJsonPath('data.cumul_amortissement', '4800.00')
            ->assertJsonPath('data.vna', '7200.00');
    }

    public function test_cession_with_plus_value(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $immo = $this->creerImmo($token); // 12000/5

        // 2 années dotées → cumul 4800, VNA 7200.
        $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2024])->assertOk();
        $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2025])->assertOk();

        // Cédé 8000 → plus-value (prix > VNA).
        $cede = $this->withToken($token)->postJson("/api/v1/compta/immobilisations/{$immo['id']}/ceder", [
            'date_cession' => '2026-03-31', 'valeur_cession' => 8000,
        ]);
        $cede->assertOk()->assertJsonPath('data.statut', 'cede');

        // Sortie de l'actif (OD) : 2926 débit 4800, 6511 débit 7200, 2355 crédit 12000.
        $sortie = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=OD&search=CESSION')->json('data'))->first();
        $lignesSortie = collect($sortie['lignes']);
        $this->assertSame('4800.00', $lignesSortie->firstWhere('compte_code', '2926')['debit']);
        $this->assertSame('7200.00', $lignesSortie->firstWhere('compte_code', '6511')['debit']);
        $this->assertSame('12000.00', $lignesSortie->firstWhere('compte_code', '2355')['credit']);
        $this->assertSame(
            $lignesSortie->sum(fn ($l) => (float) $l['debit']),
            $lignesSortie->sum(fn ($l) => (float) $l['credit']),
        );

        // Produit de cession (BQ) : 5141 débit 8000, 7511 crédit 8000.
        $produit = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=BQ&search=CESSION')->json('data'))->first();
        $this->assertSame('8000.00', collect($produit['lignes'])->firstWhere('compte_code', '7511')['credit']);

        // Une immobilisation cédée ne peut plus l'être.
        $this->withToken($token)->postJson("/api/v1/compta/immobilisations/{$immo['id']}/ceder", [
            'date_cession' => '2026-04-01', 'valeur_cession' => 100,
        ])->assertUnprocessable();
    }

    public function test_cession_of_fully_amortized_asset_at_zero(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $immo = $this->creerImmo($token, ['duree_annees' => 1]); // amorti en 1 an

        $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2024])->assertOk();

        $detail = $this->withToken($token)->getJson("/api/v1/compta/immobilisations/{$immo['id']}");
        $detail->assertJsonPath('data.vna', '0.00');

        // Mise au rebut (prix 0) : sortie 2926 débit 12000 / 2355 crédit 12000, pas de 6511 ni produit.
        $this->withToken($token)->postJson("/api/v1/compta/immobilisations/{$immo['id']}/ceder", [
            'date_cession' => '2025-06-01', 'valeur_cession' => 0,
        ])->assertOk();

        $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=BQ&search=CESSION')
            ->assertJsonPath('meta.total', 0); // aucun produit
        $sortie = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=OD&search=CESSION')->json('data'))->first();
        $this->assertNull(collect($sortie['lignes'])->firstWhere('compte_code', '6511'));
    }

    public function test_dotation_blocked_on_closed_exercice(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->creerImmo($token);

        // Clôturer 2024 verrouille toute dotation datée au 31/12/2024.
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => 2024])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/compta/immobilisations/dotations', ['annee' => 2024])
            ->assertUnprocessable();
    }

    public function test_immobilisations_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $immoA = $this->creerImmo($tokenA);

        $this->withToken($tokenB)->getJson('/api/v1/compta/immobilisations')
            ->assertJsonPath('meta.total', 0);
        $this->withToken($tokenB)->getJson("/api/v1/compta/immobilisations/{$immoA['id']}")
            ->assertNotFound();
    }
}
