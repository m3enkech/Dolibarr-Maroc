<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClotureTest extends TestCase
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

    /** Promeut l'utilisateur d'un email donné en superadmin plateforme. */
    private function makeSuperadmin(string $email): void
    {
        User::withoutGlobalScopes()->where('email', $email)->update(['is_superadmin' => true]);
    }

    /** Facture client validée et payée : 1000 HT / 1200 TTC, encaissée en virement. */
    private function venteEncaissee(string $token): void
    {
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $tiers['id'],
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => 1000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement',
        ])->assertOk();
    }

    /** Facture fournisseur validée non payée : 300 HT / 360 TTC. */
    private function achatValide(string $token): void
    {
        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur Y', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');

        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture',
            'tiers_id' => $fournisseur['id'],
            'lignes' => [['designation' => 'Fournitures', 'quantite' => 1, 'prix_unitaire' => 300, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();
    }

    public function test_cloture_generates_resultat_and_a_nouveaux(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->venteEncaissee($token);  // produits 1000
        $this->achatValide($token);     // charges 300
        $annee = now()->year;

        // Avant clôture : l'exercice courant est ouvert avec le bon résultat prévisionnel.
        $avant = $this->withToken($token)->getJson('/api/v1/compta/exercices');
        $exercice = collect($avant->json('data'))->firstWhere('annee', $annee);
        $this->assertSame('ouvert', $exercice['statut']);
        $this->assertSame('1000.00', $exercice['produits']);
        $this->assertSame('300.00', $exercice['charges']);
        $this->assertSame('700.00', $exercice['resultat']);

        $cloture = $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee]);
        $cloture->assertCreated()->assertJsonPath('data.resultat', '700.00');

        // Écriture de résultat : 7114 débité de 1000, 6117 crédité de 300 (ligne
        // libre d'achat → services), 1161 crédité de 700.
        $od = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=OD&search=CLOTURE')->json('data'))->first();
        $lignes = collect($od['lignes']);
        $this->assertSame('1000.00', $lignes->firstWhere('compte_code', '7124')['debit']);
        $this->assertSame('300.00', $lignes->firstWhere('compte_code', '6126')['credit']);
        $this->assertSame('700.00', $lignes->firstWhere('compte_code', '1191')['credit']);

        // À-nouveaux au 01/01 suivant : bilan uniquement, équilibrés, sans classes 6/7.
        $an = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=AN')->json('data'))->first();
        $this->assertSame(($annee + 1).'-01-01', $an['date_ecriture']);
        $lignesAn = collect($an['lignes']);
        $this->assertSame(
            $lignesAn->sum(fn ($l) => (float) $l['debit']),
            $lignesAn->sum(fn ($l) => (float) $l['credit']),
        );
        $this->assertNull($lignesAn->first(fn ($l) => in_array($l['compte_code'][0], ['6', '7'], true)));
        // Banque 1200 D, fournisseurs 360 C, TVA facturée 200 C, TVA récup 60 D, résultat 700 C.
        $this->assertSame('1200.00', $lignesAn->firstWhere('compte_code', '5141')['debit']);
        $this->assertSame('360.00', $lignesAn->firstWhere('compte_code', '4411')['credit']);
        $this->assertSame('700.00', $lignesAn->firstWhere('compte_code', '1191')['credit']);

        // L'exercice apparaît clôturé.
        $apres = $this->withToken($token)->getJson('/api/v1/compta/exercices');
        $this->assertSame('cloture', collect($apres->json('data'))->firstWhere('annee', $annee)['statut']);
    }

    public function test_perte_goes_to_1162(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->achatValide($token); // charges 300, aucun produit

        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => now()->year])
            ->assertCreated()->assertJsonPath('data.resultat', '-300.00');

        $od = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=OD&search=CLOTURE')->json('data'))->first();
        $this->assertSame('300.00', collect($od['lignes'])->firstWhere('compte_code', '1199')['debit']);
    }

    public function test_cloture_locks_the_exercice(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->venteEncaissee($token);
        $annee = now()->year;
        $comptes = collect($this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data'));

        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])->assertCreated();

        // OD manuelle datée dans l'exercice clos → refusée.
        $this->withToken($token)->postJson('/api/v1/compta/ecritures', [
            'libelle' => 'Tentative', 'date_ecriture' => "{$annee}-06-15",
            'lignes' => [
                ['compte_id' => $comptes->firstWhere('code', '5141')['id'], 'debit' => 100],
                ['compte_id' => $comptes->firstWhere('code', '1111')['id'], 'credit' => 100],
            ],
        ])->assertUnprocessable();

        // Valider une facture datée dans l'exercice clos → refusé aussi (l'écriture est bloquée).
        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client Z'])->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $tiers['id'], 'date_document' => "{$annee}-11-20",
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")
            ->assertUnprocessable();

        // Datée dans le nouvel exercice → acceptée.
        $ok = $this->withToken($token)->postJson('/api/v1/compta/ecritures', [
            'libelle' => 'Nouvel exercice', 'date_ecriture' => ($annee + 1).'-01-15',
            'lignes' => [
                ['compte_id' => $comptes->firstWhere('code', '5141')['id'], 'debit' => 100],
                ['compte_id' => $comptes->firstWhere('code', '1111')['id'], 'credit' => 100],
            ],
        ]);
        $ok->assertCreated();
    }

    public function test_double_cloture_and_future_year_rejected(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->venteEncaissee($token);
        $annee = now()->year;

        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee + 1])
            ->assertUnprocessable();
    }

    public function test_chronological_closing_is_enforced(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $annee = now()->year;
        $comptes = collect($this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data'));

        // Une écriture l'année précédente…
        $this->withToken($token)->postJson('/api/v1/compta/ecritures', [
            'libelle' => 'Apport initial', 'date_ecriture' => ($annee - 1).'-03-10',
            'lignes' => [
                ['compte_id' => $comptes->firstWhere('code', '5141')['id'], 'debit' => 50000],
                ['compte_id' => $comptes->firstWhere('code', '1111')['id'], 'credit' => 50000],
            ],
        ])->assertCreated();

        // …bloque la clôture de l'année courante.
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])
            ->assertUnprocessable();

        // On clôture N-1 puis N : OK. Les à-nouveaux de N-1 se reportent dans N.
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee - 1])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])->assertCreated();

        // Balance du nouvel exercice : la banque démarre à 50 000 via les AN.
        $balance = $this->withToken($token)->getJson('/api/v1/compta/balance?du='.($annee + 1).'-01-01');
        $this->assertSame(
            '50000.00',
            collect($balance->json('data'))->firstWhere('code', '5141')['solde_debiteur'],
        );
    }

    public function test_company_account_cannot_reopen(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->venteEncaissee($token);
        $annee = now()->year;

        // L'admin de tenant reste un compte entreprise : réouverture interdite (403).
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])->assertCreated();
        $this->withToken($token)->deleteJson("/api/v1/compta/exercices/{$annee}")->assertForbidden();

        // La clôture est intacte.
        $this->withToken($token)->getJson('/api/v1/compta/exercices')
            ->assertJsonPath('data.'.($this->indexAnnee($token, $annee)).'.statut', 'cloture');
    }

    private function indexAnnee(string $token, int $annee): int
    {
        $data = collect($this->withToken($token)->getJson('/api/v1/compta/exercices')->json('data'));

        return $data->search(fn ($e) => $e['annee'] === $annee);
    }

    public function test_reopen_removes_closure_and_lifts_lock(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->makeSuperadmin('a@test.ma');
        $this->venteEncaissee($token);
        $annee = now()->year;
        $comptes = collect($this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data'));

        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])->assertCreated();

        // Les écritures de clôture existent.
        $this->assertSame(1, $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=AN')->json('meta.total'));

        // Réouverture.
        $this->withToken($token)->deleteJson("/api/v1/compta/exercices/{$annee}")->assertOk();

        // L'exercice redevient ouvert, les écritures de clôture ont disparu.
        $exercice = collect($this->withToken($token)->getJson('/api/v1/compta/exercices')->json('data'))
            ->firstWhere('annee', $annee);
        $this->assertSame('ouvert', $exercice['statut']);
        $this->assertSame(0, $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=AN')->json('meta.total'));
        $this->assertSame(0, $this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=OD&search=CLOTURE')->json('meta.total'));

        // Le verrou est levé : on peut de nouveau saisir dans l'exercice.
        $this->withToken($token)->postJson('/api/v1/compta/ecritures', [
            'libelle' => 'Après réouverture', 'date_ecriture' => "{$annee}-06-15",
            'lignes' => [
                ['compte_id' => $comptes->firstWhere('code', '5141')['id'], 'debit' => 100],
                ['compte_id' => $comptes->firstWhere('code', '1111')['id'], 'credit' => 100],
            ],
        ])->assertCreated();
    }

    public function test_cannot_reopen_older_year_before_latest(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->makeSuperadmin('a@test.ma');
        $annee = now()->year;
        $comptes = collect($this->withToken($token)->getJson('/api/v1/compta/comptes')->json('data'));

        // Une écriture N-1 puis clôture N-1 et N.
        $this->withToken($token)->postJson('/api/v1/compta/ecritures', [
            'libelle' => 'Apport', 'date_ecriture' => ($annee - 1).'-03-10',
            'lignes' => [
                ['compte_id' => $comptes->firstWhere('code', '5141')['id'], 'debit' => 5000],
                ['compte_id' => $comptes->firstWhere('code', '1111')['id'], 'credit' => 5000],
            ],
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee - 1])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => $annee])->assertCreated();

        // On ne peut pas rouvrir N-1 tant que N est clos.
        $this->withToken($token)->deleteJson('/api/v1/compta/exercices/'.($annee - 1))->assertUnprocessable();

        // Rouvrir N d'abord, puis N-1 : OK.
        $this->withToken($token)->deleteJson("/api/v1/compta/exercices/{$annee}")->assertOk();
        $this->withToken($token)->deleteJson('/api/v1/compta/exercices/'.($annee - 1))->assertOk();
    }

    public function test_exercices_are_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');

        $this->venteEncaissee($tokenA);
        $this->withToken($tokenA)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => now()->year])->assertCreated();

        // Le tenant B n'est pas verrouillé par la clôture du tenant A.
        $this->venteEncaissee($tokenB);
        $exercices = $this->withToken($tokenB)->getJson('/api/v1/compta/exercices');
        $this->assertSame('ouvert', collect($exercices->json('data'))->firstWhere('annee', now()->year)['statut']);
    }
}
