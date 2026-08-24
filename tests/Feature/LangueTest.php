<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MontePortail;
use Tests\TestCase;

/**
 * Langue des réponses de l'API : elle suit l'en-tête `Accept-Language` posé par
 * les clients HTTP, et retombe sur le français en son absence.
 */
class LangueTest extends TestCase
{
    use MontePortail, RefreshDatabase;

    public function test_sans_en_tete_les_messages_sont_en_francais(): void
    {
        $this->postJson('/api/portail/v1/auth/connexion', [
            'email' => 'inconnu@test.ma', 'password' => 'password123',
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'Identifiants incorrects.');
    }

    public function test_en_arabe_les_messages_metier_sont_traduits(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/portail/v1/auth/connexion', [
                'email' => 'inconnu@test.ma', 'password' => 'password123',
            ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'معطيات الدخول غير صحيحة.');
    }

    /**
     * Les messages du CADRIciel aussi : sans fichier de langue, une adresse
     * invalide répondait « The email field must be a valid email address. »
     * au milieu d'une interface française.
     */
    public function test_les_messages_de_validation_suivent_la_langue(): void
    {
        $this->postJson('/api/portail/v1/auth/inscription', ['name' => 'X', 'password' => 'password123'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Le champ email est obligatoire.');

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/portail/v1/auth/inscription', ['name' => 'X', 'password' => 'password123'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'email مطلوب.');
    }

    /** Une langue non gérée ne casse rien : on sert le français. */
    public function test_une_langue_inconnue_retombe_sur_le_francais(): void
    {
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
            ->postJson('/api/portail/v1/auth/connexion', [
                'email' => 'inconnu@test.ma', 'password' => 'password123',
            ])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Identifiants incorrects.');
    }

    public function test_la_langue_ne_change_rien_aux_donnees_servies(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $this->rattacher($token, $g, 'e@test.ma');

        // Le contenu métier (codes, montants) reste identique : seule la
        // langue des messages change.
        $this->withHeader('Accept-Language', 'ar')
            ->withToken($token)
            ->getJson("/api/portail/v1/grossistes/{$g['slug']}/mon-compte")
            ->assertOk()
            ->assertJsonPath('data.encours', '0.00');
    }
}
