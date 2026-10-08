<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Tiers\Models\Commentaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le fil de commentaires d'un tiers.
 *
 * Éprouvé : le fil se lit du plus récent au plus ancien, au curseur, sans
 * doublon quand un collègue publie entre deux pages ; le contenu est du texte
 * brut, renvoyé tel quel et jamais interprété ; la garde est `tiers` seule
 * (lu par le caissier et le comptable, qui n'ont pas le CRM) ; supprimer est
 * réservé à l'auteur et à l'administrateur ; la suppression est douce ; un
 * auteur parti garde son nom ; et rien ne passe d'une entreprise à l'autre.
 */
class CommentairesTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private int $entreprise;

    private int $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 10:00:00');

        $inscription = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Karim Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        $this->jeton = $inscription->json('token');
        $this->entreprise = $inscription->json('tenant.id');
        $this->client = $this->creerTiers($this->jeton, 'WYDAD ATHLETIC CLUB');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function creerTiers(string $jeton, string $nom): int
    {
        return $this->withToken($jeton)->postJson('/api/v1/tiers', [
            'name' => $nom, 'is_client' => true, 'is_supplier' => false,
        ])->assertCreated()->json('data.id');
    }

    /** @return array{0: User, 1: string} */
    private function membre(string $role, string $nom = 'Membre'): array
    {
        $utilisateur = User::factory()->create([
            'tenant_id' => $this->entreprise, 'role' => $role, 'is_active' => true, 'name' => $nom,
        ]);

        return [$utilisateur, $utilisateur->createToken('spa')->plainTextToken];
    }

    private function publier(string $contenu, ?string $jeton = null, ?int $tiers = null): array
    {
        return $this->withToken($jeton ?? $this->jeton)
            ->postJson('/api/v1/tiers/'.($tiers ?? $this->client).'/commentaires', ['contenu' => $contenu])
            ->assertCreated()
            ->json('data');
    }

    private function fil(?string $jeton = null, array $params = []): array
    {
        return $this->withToken($jeton ?? $this->jeton)
            ->getJson("/api/v1/tiers/{$this->client}/commentaires?".http_build_query($params))
            ->assertOk()
            ->json();
    }

    /* ------------------------------ le fil ------------------------------- */

    public function test_le_fil_se_lit_du_plus_recent_au_plus_ancien_avec_auteur_et_date(): void
    {
        $this->publier('Ne livre que le matin.');
        Carbon::setTestNow('2026-10-08 11:00:00');
        $dernier = $this->publier("Remise de 5 % promise\nsur la prochaine commande.");

        $this->assertSame('Karim Admin', $dernier['auteur']['nom']);
        $this->assertSame('2026-10-08T11:00:00.000000Z', $dernier['created_at']);
        $this->assertTrue($dernier['peut_supprimer']);

        $fil = $this->fil();
        $this->assertSame(
            ["Remise de 5 % promise\nsur la prochaine commande.", 'Ne livre que le matin.'],
            array_column($fil['data'], 'contenu'),
        );
        $this->assertSame('Karim Admin', $fil['data'][1]['auteur']['nom']);
    }

    /**
     * Au curseur : un commentaire publié entre deux pages ne fait pas revenir
     * le dernier lu en tête de la page suivante, comme le ferait une page
     * numérotée.
     */
    public function test_la_pagination_au_curseur_ne_repete_rien_quand_un_collegue_publie(): void
    {
        foreach (['un', 'deux', 'trois'] as $texte) {
            $this->publier($texte);
        }

        $page1 = $this->fil(params: ['per_page' => 2]);
        $this->assertSame(['trois', 'deux'], array_column($page1['data'], 'contenu'));
        $this->assertNotNull($page1['meta']['next_cursor']);

        $this->publier('quatre, publié entre-temps');

        $page2 = $this->fil(params: ['per_page' => 2, 'cursor' => $page1['meta']['next_cursor']]);
        $this->assertSame(['un'], array_column($page2['data'], 'contenu'));
        $this->assertNull($page2['meta']['next_cursor']);
    }

    public function test_une_taille_de_page_hors_bornes_est_refusee(): void
    {
        $this->withToken($this->jeton)
            ->getJson("/api/v1/tiers/{$this->client}/commentaires?per_page=500")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_le_compteur_de_la_synthese_suit_le_fil(): void
    {
        $this->publier('un');
        $deux = $this->publier('deux');

        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$this->client}/synthese")
            ->assertOk()->assertJsonPath('data.commentaires', 2);

        $this->withToken($this->jeton)
            ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$deux['id']}")
            ->assertNoContent();

        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$this->client}/synthese")
            ->assertOk()->assertJsonPath('data.commentaires', 1);
    }

    /* ---------------------------- validation ----------------------------- */

    public function test_un_commentaire_vide_ou_fait_d_espaces_est_refuse(): void
    {
        foreach (['', "   \n\t  ", null] as $contenu) {
            $this->withToken($this->jeton)
                ->postJson("/api/v1/tiers/{$this->client}/commentaires", ['contenu' => $contenu])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['contenu' => 'Le commentaire est vide.']);
        }

        $this->assertSame(0, Commentaire::withoutGlobalScopes()->count());
    }

    /** La borne se compte en CARACTÈRES : 2 000 lettres arabes passent, comme 2 000 latines. */
    public function test_la_longueur_est_bornee_en_caracteres(): void
    {
        $this->publier(str_repeat('a', Commentaire::LONGUEUR_MAX));
        $this->publier(str_repeat('ب', Commentaire::LONGUEUR_MAX));

        $this->withToken($this->jeton)
            ->postJson("/api/v1/tiers/{$this->client}/commentaires", ['contenu' => str_repeat('a', Commentaire::LONGUEUR_MAX + 1)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contenu' => 'Le commentaire dépasse 2000 caractères.']);

        // Le message suit la langue de l'appelant.
        $this->withToken($this->jeton)
            ->withHeader('Accept-Language', 'ar')
            ->postJson("/api/v1/tiers/{$this->client}/commentaires", ['contenu' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contenu' => 'التعليق فارغ.']);
    }

    public function test_les_retours_a_la_ligne_sont_gardes_et_normalises(): void
    {
        $commentaire = $this->publier("Ligne 1\r\nLigne 2\rLigne 3\n\nLigne 5");

        $this->assertSame("Ligne 1\nLigne 2\nLigne 3\n\nLigne 5", $commentaire['contenu']);
    }

    /**
     * Texte BRUT : ce qui ressemble à du HTML est gardé et renvoyé à
     * l'identique, en JSON — jamais nettoyé (« prix < 100 & > 50 » est une
     * note légitime), jamais servi comme une page. C'est l'écran qui le rend
     * comme du texte : le composant n'interprète aucun HTML, ce que vérifie
     * le second bloc.
     */
    public function test_le_contenu_est_du_texte_brut_jamais_interprete(): void
    {
        $piege = '<script>alert(1)</script><img src=x onerror="alert(2)"> prix < 100 & > 50';

        $this->assertSame($piege, $this->publier($piege)['contenu']);

        $reponse = $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$this->client}/commentaires")->assertOk();
        $this->assertStringStartsWith('application/json', $reponse->headers->get('Content-Type'));
        $this->assertSame($piege, $reponse->json('data.0.contenu'));

        $composant = file_get_contents(resource_path('js/pages/tiers/CommentairesTiers.tsx'));
        $this->assertStringNotContainsString('dangerouslySetInnerHTML', $composant);
        $this->assertStringNotContainsString('innerHTML', $composant);
    }

    /* ------------------------------- droits ------------------------------ */

    /**
     * La garde est `tiers`, pas le CRM : le caissier et le comptable, qui
     * n'ont pas accès au CRM (son historique leur répond 403), lisent le fil.
     * Écrire exige `tiers` en écriture : les rôles en lecture sont refusés.
     */
    public function test_les_roles_qui_lisent_les_tiers_lisent_le_fil_sans_y_ecrire(): void
    {
        $this->publier('Paie toujours en espèces.');

        foreach (['caissier', 'comptable', 'lecture'] as $role) {
            [, $jeton] = $this->membre($role);

            $fil = $this->fil($jeton);
            $this->assertSame(['Paie toujours en espèces.'], array_column($fil['data'], 'contenu'), $role);
            $this->assertFalse($fil['data'][0]['peut_supprimer'], $role);

            $this->withToken($jeton)
                ->postJson("/api/v1/tiers/{$this->client}/commentaires", ['contenu' => 'Essai'])
                ->assertForbidden();
        }

        [, $caissier] = $this->membre('caissier');
        $this->withToken($caissier)->getJson("/api/v1/crm/tiers/{$this->client}/timeline")->assertForbidden();
    }

    /**
     * Supprimer : l'auteur, ou l'administrateur. Un autre commercial, et même
     * un manager, ne retirent pas la parole d'un collègue.
     */
    public function test_seuls_l_auteur_et_l_administrateur_suppriment(): void
    {
        [, $commercial] = $this->membre('commercial', 'Sara Commerciale');
        [, $autreCommercial] = $this->membre('commercial', 'Omar');
        [, $manager] = $this->membre('manager', 'Nadia Manager');

        $note = $this->publier('Le gérant ne répond qu’au mobile.', $commercial);
        $this->assertSame('Sara Commerciale', $note['auteur']['nom']);

        // Vu par les autres : pas de bouton, et la route refuse quand même.
        $vuParManager = $this->fil($manager)['data'][0];
        $this->assertFalse($vuParManager['peut_supprimer']);

        foreach ([$autreCommercial, $manager] as $jeton) {
            $this->withToken($jeton)
                ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$note['id']}")
                ->assertForbidden()
                ->assertJsonPath('message', "Seuls l'auteur d'un commentaire et l'administrateur peuvent le supprimer.");
        }

        // L'administrateur, lui, voit le bouton et supprime.
        $this->assertTrue($this->fil()['data'][0]['peut_supprimer']);
        $this->withToken($this->jeton)
            ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$note['id']}")
            ->assertNoContent();

        // L'auteur supprime le sien.
        $sienne = $this->publier('À rappeler lundi.', $commercial);
        $this->withToken($commercial)
            ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$sienne['id']}")
            ->assertNoContent();

        $this->assertSame([], $this->fil()['data']);
    }

    /** Un auteur repassé en lecture seule ne supprime plus, même ce qu'il a écrit. */
    public function test_un_auteur_repasse_en_lecture_ne_supprime_plus(): void
    {
        [$utilisateur, $jeton] = $this->membre('commercial');
        $note = $this->publier('Note', $jeton);

        $utilisateur->update(['role' => 'lecture']);

        $this->assertFalse($this->fil($jeton)['data'][0]['peut_supprimer']);
        $this->withToken($jeton)
            ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$note['id']}")
            ->assertForbidden();
    }

    /**
     * Le commentaire quitte le fil ; son TEXTE quitte la base — une donnée
     * personnelle collée par erreur ne survit pas dans les sauvegardes —, et
     * la trace reste : qui l'avait écrit, qui l'a supprimé, quand. Le
     * supprimer deux fois répond 404.
     */
    public function test_supprimer_efface_le_texte_et_garde_qui_l_a_supprime(): void
    {
        [$commercial, $jetonCommercial] = $this->membre('commercial', 'Sara Commerciale');
        $note = $this->publier('CIN BE123456, RIB 011780000012345678901234', $jetonCommercial);

        Carbon::setTestNow('2026-10-08 15:30:00');
        $this->withToken($this->jeton)
            ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$note['id']}")
            ->assertNoContent();

        $this->assertSoftDeleted('tiers_commentaires', ['id' => $note['id']]);
        $this->assertSame([], $this->fil()['data']);

        $trace = Commentaire::withoutGlobalScopes()->withTrashed()->findOrFail($note['id']);
        $this->assertSame('', $trace->contenu);
        $this->assertSame('Karim Admin', $trace->supprime_par_nom);
        $this->assertSame(User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->id, (int) $trace->supprime_par_id);
        $this->assertSame((int) $commercial->id, (int) $trace->user_id);
        $this->assertSame('Sara Commerciale', $trace->auteur_nom);
        $this->assertSame('2026-10-08 15:30:00', $trace->deleted_at->format('Y-m-d H:i:s'));
        // Nulle part en base, sous aucune colonne.
        $this->assertSame(0, DB::table('tiers_commentaires')->where('contenu', 'like', '%BE123456%')->count());

        $this->withToken($this->jeton)
            ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$note['id']}")
            ->assertNotFound();
    }

    /** Celui qui a supprimé quitte l'équipe : son nom reste sur la trace. */
    public function test_la_trace_de_suppression_survit_au_depart_de_celui_qui_a_supprime(): void
    {
        [$commercial, $jeton] = $this->membre('commercial', 'Omar Parti');
        $note = $this->publier('À rappeler lundi.', $jeton);
        $this->withToken($jeton)->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$note['id']}")->assertNoContent();

        $commercial->tokens()->delete();
        $commercial->delete();

        $trace = Commentaire::withoutGlobalScopes()->withTrashed()->findOrFail($note['id']);
        $this->assertNull($trace->supprime_par_id);
        $this->assertSame('Omar Parti', $trace->supprime_par_nom);
    }

    /**
     * Retirer un membre de l'équipe supprime sa ligne `users` : ses
     * commentaires restent, signés du nom qu'il portait.
     */
    public function test_un_auteur_parti_garde_son_nom(): void
    {
        [$parti, $jeton] = $this->membre('commercial', 'Youssef Parti');
        $this->publier('Client fidèle depuis 2019.', $jeton);

        $parti->tokens()->delete();
        $parti->delete();

        $commentaire = $this->fil()['data'][0];
        $this->assertSame('Youssef Parti', $commentaire['auteur']['nom']);
        $this->assertNull($commentaire['auteur']['id']);
        // L'administrateur peut toujours le retirer.
        $this->assertTrue($commentaire['peut_supprimer']);
    }

    /* ----------------------------- cloisonnement ----------------------------- */

    /** Le commentaire d'un tiers ne se supprime pas par l'URL d'un autre tiers. */
    public function test_le_commentaire_doit_appartenir_au_tiers_de_l_url(): void
    {
        $autre = $this->creerTiers($this->jeton, 'RAJA');
        $note = $this->publier('Note du WYDAD');

        $this->withToken($this->jeton)
            ->deleteJson("/api/v1/tiers/{$autre}/commentaires/{$note['id']}")
            ->assertNotFound();

        $this->assertSame(['Note du WYDAD'], array_column($this->fil()['data'], 'contenu'));
    }

    public function test_rien_ne_passe_d_une_entreprise_a_l_autre(): void
    {
        $note = $this->publier('Confidentiel : remise de 12 %.');

        $autreJeton = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Concurrent', 'name' => 'Autre', 'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $sonTiers = $this->creerTiers($autreJeton, 'SON CLIENT');

        // Le tiers d'une autre entreprise n'existe pas pour elle : lire,
        // écrire, supprimer — 404, comme un tiers inconnu.
        $this->withToken($autreJeton)->getJson("/api/v1/tiers/{$this->client}/commentaires")->assertNotFound();
        $this->withToken($autreJeton)
            ->postJson("/api/v1/tiers/{$this->client}/commentaires", ['contenu' => 'Intrus'])
            ->assertNotFound();
        $this->withToken($autreJeton)
            ->deleteJson("/api/v1/tiers/{$this->client}/commentaires/{$note['id']}")
            ->assertNotFound();

        // Par son propre tiers non plus : le commentaire est hors de son scope.
        $this->withToken($autreJeton)
            ->deleteJson("/api/v1/tiers/{$sonTiers}/commentaires/{$note['id']}")
            ->assertNotFound();

        // Son propre fil est vide, et le nôtre est intact.
        $this->withToken($autreJeton)->getJson("/api/v1/tiers/{$sonTiers}/commentaires")
            ->assertOk()->assertJsonPath('data', []);
        $this->assertSame(['Confidentiel : remise de 12 %.'], array_column($this->fil()['data'], 'contenu'));
        $this->assertSame(1, Commentaire::withoutGlobalScopes()->count());
    }
}
