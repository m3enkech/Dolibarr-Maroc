<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MontePortail;
use Tests\TestCase;

/**
 * Limitation de débit des routes exposées à Internet.
 *
 * Les compteurs vivent dans le cache, remis à zéro entre deux tests
 * (CACHE_STORE=array, un conteneur neuf par test) : chaque test part donc de
 * seaux vides.
 */
class LimitationDebitTest extends TestCase
{
    use MontePortail, RefreshDatabase;

    private const MAUVAIS = ['email' => 'cible@test.ma', 'password' => 'pas-le-bon'];

    private function tenter(array $identifiants = self::MAUVAIS)
    {
        return $this->postJson('/api/portail/v1/auth/connexion', $identifiants);
    }

    /* ---------------------------------------------------------------- */

    public function test_la_connexion_se_ferme_apres_cinq_echecs(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->tenter()->assertUnprocessable();
        }

        $this->tenter()
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'Trop de tentatives.'));
    }

    /**
     * LE point de la conception : on compte les ÉCHECS, pas les tentatives.
     *
     * Sans cela, dix employés d'un même magasin qui se connectent correctement
     * le matin depuis une seule adresse épuiseraient le seau avant 9 heures.
     */
    public function test_une_connexion_reussie_ne_consomme_aucun_jeton(): void
    {
        $this->acheteur('vrai@test.ma');
        $bons = ['email' => 'vrai@test.ma', 'password' => 'password123'];

        // Dix fois le double du plafond d'échecs : si le seau se remplissait
        // aussi sur les succès, la sixième serait refusée.
        for ($i = 1; $i <= 10; $i++) {
            $this->tenter($bons)->assertOk();
        }
    }

    /**
     * RÉGRESSION — une panne ne doit pas remplir le seau de sa victime.
     *
     * « Échec » veut dire IDENTIFIANTS REFUSÉS. Compter tout ce qui dépasse 400
     * paraissait plus prudent ; c'est l'inverse. Quand le service tombe — base
     * verrouillée, déploiement en cours, dépendance absente — il répond 500, et
     * l'utilisateur qui réessaie pendant la panne remplit son PROPRE seau. Au
     * rétablissement il trouve porte close pour une heure, pour des erreurs dont
     * il n'est pas l'auteur : la panne se prolonge d'un blocage qu'elle a
     * elle-même fabriqué.
     *
     * Observé en vrai le 2026-09-18 : une reprise Zoho a gardé le fichier SQLite
     * pendant une heure, la connexion répondait 500, et chaque essai comptait.
     */
    public function test_une_panne_du_service_ne_consomme_aucun_jeton(): void
    {
        // Une route en panne, sous LE MÊME limiteur nommé et avec la même clé
        // (l'empreinte de l'adresse présentée) que la vraie connexion.
        \Illuminate\Support\Facades\Route::post('/panne-simulee', function () {
            throw new \RuntimeException('base verrouillée');
        })->middleware(['api', 'throttle:connexion-portail']);

        // Dix fois le double du plafond, pendant la panne.
        for ($i = 1; $i <= 10; $i++) {
            $this->postJson('/panne-simulee', self::MAUVAIS)->assertStatus(500);
        }

        // Le service est réparé. L'utilisateur doit retrouver ses cinq essais
        // intacts : si les 500 avaient compté, celui-ci serait déjà un 429.
        for ($i = 1; $i <= 5; $i++) {
            $this->tenter()->assertUnprocessable();
        }

        // Et le sixième referme, preuve que le seau fonctionne toujours.
        $this->tenter()->assertStatus(429);
    }

    /**
     * RÉGRESSION — le piège qui rend une limitation naïve inutilisable.
     *
     * La clé par défaut de Laravel ne contient PAS le chemin de la route : quatre
     * `throttle:5,1` posés sur quatre routes du même hôte partagent UN seul seau.
     * Un acheteur qui se trompe deux fois de mot de passe ne pourrait alors plus
     * créer de compte, et personne ne pourrait plus demander un lien de
     * réinitialisation. On croirait avoir posé quatre garde-fous.
     */
    public function test_chaque_route_garde_son_propre_seau(): void
    {
        // On ferme la connexion du portail.
        for ($i = 1; $i <= 6; $i++) {
            $this->tenter();
        }
        $this->tenter()->assertStatus(429);

        // L'inscription au portail, elle, reste ouverte.
        $this->postJson('/api/portail/v1/auth/inscription', [
            'name' => 'Épicerie', 'email' => 'nouveau@test.ma', 'password' => 'password123',
        ])->assertCreated();

        // La connexion de l'ERP aussi : c'est un autre limiteur.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'inconnu@erp.ma', 'password' => 'peu-importe',
        ])->assertUnprocessable();
    }

    /**
     * Le seau étroit porte sur le COUPLE compte + source.
     *
     * Compter par adresse seule punirait tout un quartier : une bonne part du
     * trafic mobile marocain sort en NAT partagé.
     */
    public function test_le_blocage_d_un_compte_ne_ferme_pas_celui_du_voisin(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->tenter();
        }
        $this->tenter()->assertStatus(429);

        // Même adresse IP, autre compte : toujours ouvert.
        $this->tenter(['email' => 'voisin@test.ma', 'password' => 'pas-le-bon'])
            ->assertUnprocessable();
    }

    public function test_le_refus_parle_la_langue_de_l_appelant(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->withHeader('Accept-Language', 'ar')->tenter();
        }

        $this->withHeader('Accept-Language', 'ar')->tenter()
            ->assertStatus(429)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'محاولات كثيرة'));
    }

    /**
     * L'inscription anonyme est la route la plus chère de l'application : elle
     * enchaîne deux hachages bcrypt, et côté ERP elle fabrique une entreprise
     * entière sans la moindre vérification d'adresse.
     */
    public function test_la_creation_de_compte_anonyme_est_bornee(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/portail/v1/auth/inscription', [
                'name' => 'Épicerie', 'email' => "acheteur{$i}@test.ma", 'password' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/portail/v1/auth/inscription', [
            'name' => 'Épicerie', 'email' => 'acheteur4@test.ma', 'password' => 'password123',
        ])->assertStatus(429);
    }

    public function test_la_creation_d_entreprise_est_bornee(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'company_name' => "Société {$i}", 'name' => 'Patron',
                'email' => "patron{$i}@test.ma", 'password' => 'password123',
            ])->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Société 4', 'name' => 'Patron',
            'email' => 'patron4@test.ma', 'password' => 'password123',
        ])->assertStatus(429);
    }

    /**
     * Chaque demande de réinitialisation ÉCRASE le jeton précédent. Sans
     * plafond, on appelle la route en boucle sur une victime : chaque lien
     * qu'elle reçoit est invalidé par le suivant, et elle ne reprend jamais la
     * main sur son compte. Déni de service sur la récupération de compte.
     */
    public function test_la_demande_de_reinitialisation_est_bornee(): void
    {
        $this->grossiste('Société', 'patron@test.ma');

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'patron@test.ma'])
                ->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'patron@test.ma'])
            ->assertStatus(429);
    }

    /**
     * La normalisation se fait DANS le limiteur : les services normalisent
     * aussi, mais après lui. Sans cela, la casse suffirait à contourner la
     * limite en fabriquant un compteur neuf à chaque variante.
     */
    public function test_la_casse_de_l_adresse_ne_fabrique_pas_un_compteur_neuf(): void
    {
        foreach (['cible@test.ma', 'Cible@Test.ma', 'CIBLE@TEST.MA', 'cIbLe@test.ma', 'cible@TEST.ma'] as $variante) {
            $this->tenter(['email' => $variante, 'password' => 'pas-le-bon'])->assertUnprocessable();
        }

        $this->tenter(['email' => 'CiBlE@tEsT.mA', 'password' => 'pas-le-bon'])
            ->assertStatus(429);
    }
}
