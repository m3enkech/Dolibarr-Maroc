<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /auth/me est relu par l'interface à CHAQUE chargement
 * (resources/js/lib/auth.tsx) et remplace le profil gardé depuis la connexion.
 *
 * Deux garanties en dépendent :
 *  - un changement fait APRÈS la connexion (statut superadmin, rôle) y
 *    apparaît — c'est la raison d'être de la relecture ;
 *  - la réponse a la MÊME forme que celle de la connexion : un champ présent à
 *    la connexion mais absent ici disparaîtrait de l'interface au premier
 *    rechargement, sans erreur.
 */
class ProfilRelectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_statut_accorde_apres_la_connexion_apparait_a_la_relecture(): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Admin', 'email' => 'admin@acme.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');

        // Ce que fait `php artisan superadmin:set`, une fois la session ouverte.
        User::withoutGlobalScopes()->where('email', 'admin@acme.ma')->update(['is_superadmin' => true]);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.is_superadmin', true);
    }

    public function test_la_relecture_a_la_forme_de_la_reponse_de_connexion(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Admin', 'email' => 'admin@acme.ma', 'password' => 'password123',
        ])->assertCreated();

        $connexion = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@acme.ma', 'password' => 'password123',
        ])->assertOk();

        $relecture = $this->withToken($connexion->json('token'))->getJson('/api/v1/auth/me')->assertOk();

        foreach (['user', 'tenant', 'permissions'] as $bloc) {
            $attendu = array_keys($connexion->json($bloc));
            $obtenu = array_keys($relecture->json($bloc));
            sort($attendu);
            sort($obtenu);

            $this->assertSame($attendu, $obtenu, "Le bloc « {$bloc} » de /auth/me n'a pas les champs de la connexion.");
        }
    }
}
