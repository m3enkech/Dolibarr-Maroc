<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LettrageTest extends TestCase
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

    /** Facture client validée de 1200 TTC (1000 HT à 20 %). */
    private function createFactureVente(string $token, int $tiersId): array
    {
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiersId,
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => 1000, 'tva_rate' => 20]],
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        return $facture;
    }

    private function compteId(string $token, string $code): int
    {
        $comptes = $this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data');

        return collect($comptes)->firstWhere('code', $code)['id'];
    }

    public function test_auto_lettrage_matches_facture_and_paiements_by_reference(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->createFactureVente($token, $tiers['id']);
        $compte3411 = $this->compteId($token, '3411');

        // Paiement partiel : l'auto-lettrage n'a rien à lettrer (groupe déséquilibré).
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 500, 'mode' => 'virement',
        ])->assertOk();

        $rien = $this->withToken($token)->postJson('/api/v1/compta/lettrage/auto', ['compte_id' => $compte3411]);
        $rien->assertOk()->assertJsonPath('groupes', 0);

        // Solde : le groupe (facture 1200 D + paiements 500 C + 700 C) s'équilibre.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 700, 'mode' => 'especes',
        ])->assertOk();

        $auto = $this->withToken($token)->postJson('/api/v1/compta/lettrage/auto', ['compte_id' => $compte3411]);
        $auto->assertOk()
            ->assertJsonPath('groupes', 1)
            ->assertJsonPath('lignes', 3);

        // Les 3 lignes portent la même lettre, le tiers est renseigné, solde non lettré = 0.
        $lignes = $this->withToken($token)->getJson("/api/v1/compta/lettrage?compte_id={$compte3411}&statut=lettres");
        $codes = collect($lignes->json('data'))->pluck('lettrage')->unique();
        $this->assertCount(1, $codes);
        $this->assertSame('AAA', $codes->first());
        $this->assertSame('Client X', $lignes->json('data.0.tiers'));

        $this->withToken($token)->getJson("/api/v1/compta/lettrage?compte_id={$compte3411}")
            ->assertJsonPath('solde_non_lettre', '0.00');
    }

    public function test_manual_lettrage_requires_balance_and_same_compte(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->createFactureVente($token, $tiers['id']);
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement',
        ])->assertOk();

        $compte3411 = $this->compteId($token, '3411');
        $lignes = collect(
            $this->withToken($token)->getJson("/api/v1/compta/lettrage?compte_id={$compte3411}")->json('data'),
        );
        $debit = $lignes->first(fn ($l) => (float) $l['debit'] > 0);
        $credit = $lignes->first(fn ($l) => (float) $l['credit'] > 0);

        // Une seule ligne → refusé.
        $this->withToken($token)->postJson('/api/v1/compta/lettrage', [
            'ligne_ids' => [$debit['id']],
        ])->assertUnprocessable();

        // Débit seul + ligne d'un autre compte → refusé (comptes différents).
        $compte5141 = $this->compteId($token, '5141');
        $banque = collect(
            $this->withToken($token)->getJson("/api/v1/compta/lettrage?compte_id={$compte5141}")->json('data'),
        )->first();
        $this->withToken($token)->postJson('/api/v1/compta/lettrage', [
            'ligne_ids' => [$debit['id'], $banque['id']],
        ])->assertUnprocessable();

        // Équilibré → lettré AAA.
        $ok = $this->withToken($token)->postJson('/api/v1/compta/lettrage', [
            'ligne_ids' => [$debit['id'], $credit['id']],
        ]);
        $ok->assertOk()->assertJsonPath('code', 'AAA')->assertJsonPath('lignes', 2);

        // Re-lettrer les mêmes lignes → refusé.
        $this->withToken($token)->postJson('/api/v1/compta/lettrage', [
            'ligne_ids' => [$debit['id'], $credit['id']],
        ])->assertUnprocessable();
    }

    public function test_lettre_codes_increment_per_compte(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $compte3411 = $this->compteId($token, '3411');

        foreach ([1, 2] as $i) {
            $facture = $this->createFactureVente($token, $tiers['id']);
            $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
                'montant' => 1200, 'mode' => 'virement',
            ])->assertOk();
        }

        $this->withToken($token)->postJson('/api/v1/compta/lettrage/auto', ['compte_id' => $compte3411])
            ->assertJsonPath('groupes', 2);

        $codes = collect(
            $this->withToken($token)->getJson("/api/v1/compta/lettrage?compte_id={$compte3411}&statut=lettres")->json('data'),
        )->pluck('lettrage')->unique()->sort()->values();

        $this->assertSame(['AAA', 'AAB'], $codes->all());
    }

    public function test_delettrage_restores_lines(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');
        $facture = $this->createFactureVente($token, $tiers['id']);
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement',
        ])->assertOk();

        $compte3411 = $this->compteId($token, '3411');
        $this->withToken($token)->postJson('/api/v1/compta/lettrage/auto', ['compte_id' => $compte3411])->assertOk();

        $this->withToken($token)->postJson('/api/v1/compta/lettrage/delettrer', [
            'compte_id' => $compte3411, 'code' => 'AAA',
        ])->assertOk()->assertJsonPath('lignes', 2);

        // Tout est redevenu non lettré.
        $this->withToken($token)->getJson("/api/v1/compta/lettrage?compte_id={$compte3411}&statut=lettres")
            ->assertJsonCount(0, 'data');

        // Un code inexistant → 422.
        $this->withToken($token)->postJson('/api/v1/compta/lettrage/delettrer', [
            'compte_id' => $compte3411, 'code' => 'ZZZ',
        ])->assertUnprocessable();
    }

    public function test_fournisseur_lettrage_works_on_4411(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur Y', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');
        $entrepot = $this->withToken($token)->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture',
            'tiers_id' => $fournisseur['id'],
            'entrepot_id' => $entrepot['id'],
            'lignes' => [['designation' => 'Fournitures', 'quantite' => 1, 'prix_unitaire' => 800, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/paiements", [
            'montant' => 960, 'mode' => 'virement',
        ])->assertOk();

        $compte4411 = $this->compteId($token, '4411');
        $this->withToken($token)->postJson('/api/v1/compta/lettrage/auto', ['compte_id' => $compte4411])
            ->assertJsonPath('groupes', 1)
            ->assertJsonPath('lignes', 2);

        $this->withToken($token)->getJson("/api/v1/compta/lettrage?compte_id={$compte4411}")
            ->assertJsonPath('solde_non_lettre', '0.00');
    }

    public function test_lettrage_is_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $tiersA = $this->withToken($tokenA)->postJson('/api/v1/tiers', ['name' => 'Client A'])->json('data');
        $facture = $this->createFactureVente($tokenA, $tiersA['id']);
        $this->withToken($tokenA)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement',
        ])->assertOk();

        $compte3411A = $this->compteId($tokenA, '3411');
        $ligneIds = collect(
            $this->withToken($tokenA)->getJson("/api/v1/compta/lettrage?compte_id={$compte3411A}")->json('data'),
        )->pluck('id');

        // Le tenant B ne peut pas lettrer les lignes du tenant A : introuvables.
        $this->withToken($tokenB)->getJson('/api/v1/compta/comptes')->assertOk(); // seed B
        $this->withToken($tokenB)->postJson('/api/v1/compta/lettrage', [
            'ligne_ids' => $ligneIds->all(),
        ])->assertUnprocessable();

        // Et son propre compte 3411 (id différent) est vide.
        $compte3411B = $this->compteId($tokenB, '3411');
        $this->withToken($tokenB)->getJson("/api/v1/compta/lettrage?compte_id={$compte3411B}")
            ->assertJsonCount(0, 'data');
    }
}
