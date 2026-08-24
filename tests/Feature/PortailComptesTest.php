<?php

namespace Tests\Feature;

use App\Modules\Portail\Models\Acheteur;
use App\Modules\Portail\Models\AcheteurTiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portail acheteur — comptes, rattachement aux grossistes et étanchéité vis-à-vis
 * de l'ERP.
 */
class PortailComptesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{token: string, slug: string, user_email: string} */
    private function grossiste(string $company, string $email): array
    {
        $r = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company, 'name' => 'Patron', 'email' => $email, 'password' => 'password123',
        ])->assertCreated();

        return ['token' => $r->json('token'), 'slug' => $r->json('tenant.slug'), 'user_email' => $email];
    }

    private function acheteur(string $email = 'epicier@test.ma'): string
    {
        return $this->postJson('/api/portail/v1/auth/inscription', [
            'name' => 'Épicerie Nour', 'email' => $email, 'password' => 'password123', 'phone' => '0600000000',
        ])->assertCreated()->json('token');
    }

    /* ---------------------------------------------------------------- */
    /* Comptes                                                           */
    /* ---------------------------------------------------------------- */

    public function test_inscription_et_connexion_d_un_acheteur(): void
    {
        $token = $this->acheteur();
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/portail/v1/auth/moi')
            ->assertOk()->assertJsonPath('data.email', 'epicier@test.ma');

        // Reconnexion.
        $this->postJson('/api/portail/v1/auth/connexion', [
            'email' => 'epicier@test.ma', 'password' => 'password123',
        ])->assertOk()->assertJsonPath('acheteur.name', 'Épicerie Nour');

        // Mauvais mot de passe.
        $this->postJson('/api/portail/v1/auth/connexion', [
            'email' => 'epicier@test.ma', 'password' => 'faux',
        ])->assertUnprocessable();
    }

    public function test_un_acheteur_ne_consomme_pas_de_siege_du_grossiste(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $avant = $this->withToken($g['token'])->getJson('/api/v1/equipe')->json('subscription.seats_used');

        $this->acheteur();

        $apres = $this->withToken($g['token'])->getJson('/api/v1/equipe')->json('subscription.seats_used');
        $this->assertSame($avant, $apres, 'Un acheteur du portail ne doit pas consommer de siège facturable.');
    }

    /* ---------------------------------------------------------------- */
    /* Étanchéité entre les deux mondes                                  */
    /* ---------------------------------------------------------------- */

    public function test_un_jeton_d_acheteur_n_ouvre_aucune_route_de_l_erp(): void
    {
        $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur();

        foreach (['/api/v1/produits', '/api/v1/tiers', '/api/v1/ventes/documents', '/api/v1/compta/comptes'] as $route) {
            $this->withToken($token)->getJson($route)->assertUnauthorized();
        }
    }

    public function test_un_jeton_de_l_erp_n_ouvre_aucune_route_du_portail(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');

        $this->withToken($g['token'])->getJson('/api/portail/v1/auth/moi')->assertUnauthorized();
        $this->withToken($g['token'])->getJson('/api/portail/v1/mes-grossistes')->assertUnauthorized();
    }

    /* ---------------------------------------------------------------- */
    /* Rattachement                                                      */
    /* ---------------------------------------------------------------- */

    public function test_demande_d_acces_puis_approbation_par_le_grossiste(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur();

        // L'acheteur demande l'accès.
        $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $g['slug']])
            ->assertCreated()->assertJsonPath('data.statut', 'en_attente');

        $this->withToken($token)->getJson('/api/portail/v1/mes-grossistes')
            ->assertOk()->assertJsonPath('data.0.statut', 'en_attente');

        // Le grossiste voit la demande et l'approuve : un compte client est créé.
        $demandes = $this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')->assertOk();
        $demandes->assertJsonPath('data.0.acheteur.email', 'epicier@test.ma')
            ->assertJsonPath('data.0.statut', 'en_attente');

        $id = $demandes->json('data.0.id');
        $this->withToken($g['token'])->postJson("/api/v1/portail/adhesions/{$id}/approuver")->assertOk();

        $this->withToken($token)->getJson('/api/portail/v1/mes-grossistes')
            ->assertJsonPath('data.0.statut', 'approuve');

        // Le compte client existe bien chez le grossiste.
        $this->withToken($g['token'])->getJson('/api/v1/tiers?search=Épicerie')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_approbation_sur_un_client_existant(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur();

        $client = $this->withToken($g['token'])
            ->postJson('/api/v1/tiers', ['name' => 'Épicerie Nour (compte existant)'])->json('data');

        $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $g['slug']])->assertCreated();
        $id = $this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')->json('data.0.id');

        $this->withToken($g['token'])->postJson("/api/v1/portail/adhesions/{$id}/approuver", [
            'tiers_id' => $client['id'],
        ])->assertOk();

        $this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')
            ->assertJsonPath('data.0.client.id', $client['id']);
    }

    public function test_refus_puis_nouvelle_demande_impossible(): void
    {
        $g = $this->grossiste('Grossiste A', 'a@gros.ma');
        $token = $this->acheteur();

        $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $g['slug']])->assertCreated();
        $id = $this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')->json('data.0.id');
        $this->withToken($g['token'])->postJson("/api/v1/portail/adhesions/{$id}/refuser")->assertOk();

        $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $g['slug']])
            ->assertUnprocessable()->assertJsonValidationErrors('grossiste');
    }

    /* ---------------------------------------------------------------- */
    /* Isolation entre grossistes                                        */
    /* ---------------------------------------------------------------- */

    public function test_un_grossiste_ne_voit_que_ses_propres_demandes(): void
    {
        $a = $this->grossiste('Grossiste A', 'a@gros.ma');
        $b = $this->grossiste('Grossiste B', 'b@gros.ma');
        $token = $this->acheteur();

        // L'acheteur demande l'accès aux DEUX grossistes.
        $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $a['slug']])->assertCreated();
        $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $b['slug']])->assertCreated();

        // Chacun ne voit que la sienne.
        $this->withToken($a['token'])->getJson('/api/v1/portail/adhesions')->assertJsonCount(1, 'data');
        $this->withToken($b['token'])->getJson('/api/v1/portail/adhesions')->assertJsonCount(1, 'data');

        // Et ne peut pas toucher celle de l'autre.
        $idChezB = $this->withToken($b['token'])->getJson('/api/v1/portail/adhesions')->json('data.0.id');
        $this->withToken($a['token'])->postJson("/api/v1/portail/adhesions/{$idChezB}/approuver")->assertNotFound();
    }

    public function test_un_acheteur_peut_etre_rattache_a_plusieurs_grossistes(): void
    {
        $a = $this->grossiste('Grossiste A', 'a@gros.ma');
        $b = $this->grossiste('Grossiste B', 'b@gros.ma');
        $token = $this->acheteur();

        foreach ([$a, $b] as $g) {
            $this->withToken($token)->postJson('/api/portail/v1/demander-acces', ['slug' => $g['slug']])->assertCreated();
            $id = $this->withToken($g['token'])->getJson('/api/v1/portail/adhesions')->json('data.0.id');
            $this->withToken($g['token'])->postJson("/api/v1/portail/adhesions/{$id}/approuver")->assertOk();
        }

        $mes = $this->withToken($token)->getJson('/api/portail/v1/mes-grossistes')->assertOk();
        $this->assertCount(2, $mes->json('data'));
        $this->assertSame(['approuve', 'approuve'], collect($mes->json('data'))->pluck('statut')->all());

        // Chaque grossiste a son PROPRE compte client pour cet acheteur.
        $this->assertSame(
            2,
            AcheteurTiers::where('acheteur_id', Acheteur::first()->id)->whereNotNull('tiers_id')->count(),
        );
    }

    public function test_compte_desactive_ne_peut_plus_se_connecter(): void
    {
        $this->acheteur();
        Acheteur::first()->update(['is_active' => false]);

        $this->postJson('/api/portail/v1/auth/connexion', [
            'email' => 'epicier@test.ma', 'password' => 'password123',
        ])->assertUnprocessable();
    }
}
