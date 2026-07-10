<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Mot de passe oublié / réinitialisation + changement de mot de passe + profil.
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $email = 'admin@acme.ma', string $password = 'password123'): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme',
            'name' => 'Admin',
            'email' => $email,
            'password' => $password,
        ])->assertCreated()->json('token');
    }

    /** Déclenche l'oubli et renvoie le jeton en clair extrait de l'email. */
    private function demanderReset(string $email): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertOk();

        $token = null;
        Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token) {
            preg_match('#/reinitialiser/([^?]+)#', $mail->url, $m);
            $token = $m[1] ?? null;

            return true;
        });

        return $token;
    }

    public function test_flux_complet_mot_de_passe_oublie(): void
    {
        Mail::fake();
        $this->register('user@acme.ma', 'ancienpass1');

        $token = $this->demanderReset('user@acme.ma');
        $this->assertNotNull($token);

        // Réinitialisation avec le jeton.
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@acme.ma', 'token' => $token, 'password' => 'nouveaupass1',
        ])->assertOk();

        // L'ancien mot de passe ne marche plus, le nouveau oui.
        $this->postJson('/api/v1/auth/login', ['email' => 'user@acme.ma', 'password' => 'ancienpass1'])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'user@acme.ma', 'password' => 'nouveaupass1'])
            ->assertOk();
    }

    public function test_reponse_generique_pour_email_inconnu(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@nulle.part'])
            ->assertOk(); // pas de fuite d'information

        Mail::assertNothingSent();
    }

    public function test_jeton_invalide_refuse(): void
    {
        Mail::fake();
        $this->register('user@acme.ma');
        $this->demanderReset('user@acme.ma');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@acme.ma', 'token' => 'faux-jeton', 'password' => 'nouveaupass1',
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_jeton_expire_refuse(): void
    {
        Mail::fake();
        $this->register('user@acme.ma');
        $token = $this->demanderReset('user@acme.ma');

        // On vieillit le jeton de 2 heures.
        DB::table('password_reset_tokens')->where('email', 'user@acme.ma')
            ->update(['created_at' => now()->subHours(2)]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'user@acme.ma', 'token' => $token, 'password' => 'nouveaupass1',
        ])->assertStatus(422);
    }

    public function test_changer_mot_de_passe_connecte(): void
    {
        $token = $this->register('user@acme.ma', 'ancienpass1');

        // Mauvais mot de passe actuel.
        $this->withToken($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'faux', 'password' => 'nouveaupass1',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        // Bon mot de passe actuel.
        $this->withToken($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'ancienpass1', 'password' => 'nouveaupass1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'user@acme.ma', 'password' => 'nouveaupass1'])
            ->assertOk();
    }

    public function test_mise_a_jour_du_profil(): void
    {
        $token = $this->register('user@acme.ma');

        $this->withToken($token)->putJson('/api/v1/auth/profile', ['name' => 'Nouveau Nom'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Nouveau Nom');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertJsonPath('user.name', 'Nouveau Nom');
    }

    public function test_compte_desactive_ne_recoit_pas_de_lien(): void
    {
        Mail::fake();
        $token = $this->register('admin@acme.ma');
        $tenantId = $this->withToken($token)->getJson('/api/v1/auth/me')->json('user.tenant_id');

        User::create([
            'tenant_id' => $tenantId, 'name' => 'Inactif', 'email' => 'inactif@acme.ma',
            'password' => 'password123', 'role' => 'lecture', 'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'inactif@acme.ma'])->assertOk();
        Mail::assertNothingSent();
    }
}
