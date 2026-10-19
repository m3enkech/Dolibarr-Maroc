<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Communications\Models\Communication;
use App\Modules\Communications\Services\JournalEnvois;
use App\Modules\Relances\Models\Relance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\MonteEnvoisWhatsApp;
use Tests\TestCase;

/**
 * Journal des envois WhatsApp : ce que le navigateur déclare au moment où il
 * ouvre WhatsApp, la Relance créée avec, et qui peut lire ou écrire quoi.
 */
class JournalEnvoisWhatsAppTest extends TestCase
{
    use MonteEnvoisWhatsApp, RefreshDatabase;

    /** @var array{token: string, tenant_id: int, user_id: int} */
    private array $atlas;

    private int $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->atlas = $this->entreprise('Atlas Distribution', 'patron@atlas.ma');
        $this->client = $this->client($this->atlas['token'], 'Client X', '0661000000');
    }

    /**
     * Les lignes du journal, toutes entreprises confondues : la vérification
     * se fait en base, hors de tout contexte — celui de la dernière requête
     * simulée traîne encore dans le conteneur.
     */
    private function journal(): Collection
    {
        return Communication::withoutGlobalScopes()->orderBy('id')->get();
    }

    private function relances(): Collection
    {
        return Relance::withoutGlobalScopes()->orderBy('id')->get();
    }

    public function test_journaliser_l_envoi_d_une_facture(): void
    {
        $facture = $this->piece($this->atlas['token'], $this->client);

        $reponse = $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'facture',
            'numero' => '06 61 00 00 00',
            'langue' => 'ar',
            'message' => 'Texte retouché par l\'utilisateur',
        ])->assertCreated();

        $reponse->assertJsonPath('data.canal', 'whatsapp')
            ->assertJsonPath('data.type', 'facture')
            ->assertJsonPath('data.destinataire', '212661000000')
            ->assertJsonPath('data.destinataire_affichage', '+212 6 61 00 00 00')
            ->assertJsonPath('data.langue', 'ar')
            ->assertJsonPath('data.message', 'Texte retouché par l\'utilisateur')
            ->assertJsonPath('data.auteur.nom', 'Patron Atlas Distribution')
            // Honnêteté : WhatsApp a été OUVERT, rien ne dit que le client a reçu.
            ->assertJsonPath('data.etat', 'whatsapp_ouvert')
            ->assertJsonPath('data.etat_libelle', 'WhatsApp ouvert avec le message prêt — envoi non confirmé')
            ->assertJsonPath('data.lien_whatsapp', 'https://wa.me/212661000000?text=Texte%20retouch%C3%A9%20par%20l%27utilisateur');

        $ligne = $this->journal()->sole();
        $this->assertSame($this->atlas['tenant_id'], (int) $ligne->tenant_id);
        $this->assertSame($this->atlas['user_id'], (int) $ligne->user_id);
        $this->assertSame('Patron Atlas Distribution', $ligne->auteur_nom);
        $this->assertSame($this->client, (int) $ligne->tiers_id);
        $this->assertSame($facture['id'], (int) $ligne->document_vente_id);
        $this->assertNull($ligne->relance_id);

        $liste = $this->withToken($this->atlas['token'])->getJson("/api/v1/ventes/documents/{$facture['id']}/envois")
            ->assertOk()->json('data');
        $this->assertCount(1, $liste);
        $this->assertSame($ligne->id, $liste[0]['id']);
    }

    public function test_sans_numero_whatsapp_laisse_choisir_le_destinataire(): void
    {
        $devis = $this->piece($this->atlas['token'], $this->client, 'devis');

        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$devis['id']}/whatsapp", [
            'type' => 'devis', 'langue' => 'fr', 'message' => 'Bonjour',
        ])->assertCreated()
            ->assertJsonPath('data.destinataire', null)
            ->assertJsonPath('data.lien_whatsapp', 'https://wa.me/?text=Bonjour');
    }

    public function test_journaliser_un_paiement_verifie_qu_il_regle_ce_document(): void
    {
        $facture = $this->piece($this->atlas['token'], $this->client);
        $autre = $this->piece($this->atlas['token'], $this->client);
        $paiement = $this->paiement($this->atlas['token'], $facture['id'], 300);
        $paiementAutre = $this->paiement($this->atlas['token'], $autre['id'], 300);

        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'paiement', 'paiement_id' => $paiementAutre, 'langue' => 'fr', 'message' => 'Merci',
        ])->assertUnprocessable()->assertJsonValidationErrors('paiement_id');

        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'paiement', 'paiement_id' => $paiement, 'numero' => '0661000000', 'langue' => 'fr', 'message' => 'Merci',
        ])->assertCreated()->assertJsonPath('data.paiement_id', $paiement);

        $this->assertSame($paiement, (int) $this->journal()->sole()->paiement_id);
    }

    public function test_les_regles_de_la_preparation_sont_rejouees_au_journal(): void
    {
        $brouillon = $this->piece($this->atlas['token'], $this->client, valider: false);
        $devis = $this->piece($this->atlas['token'], $this->client, 'devis');

        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$brouillon['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Bonjour',
        ])->assertUnprocessable()->assertJsonValidationErrors('document');

        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$devis['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Bonjour',
        ])->assertUnprocessable()->assertJsonValidationErrors('document');

        // La relance ne se journalise pas par la route des ventes.
        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$devis['id']}/whatsapp", [
            'type' => 'relance', 'langue' => 'fr', 'message' => 'Bonjour',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->assertCount(0, $this->journal());
    }

    public function test_le_corps_de_la_requete_est_valide(): void
    {
        $facture = $this->piece($this->atlas['token'], $this->client);
        $url = "/api/v1/ventes/documents/{$facture['id']}/whatsapp";

        $this->withToken($this->atlas['token'])->postJson($url, [
            'type' => 'facture', 'numero' => '0522 30 30 30 poste 12', 'langue' => 'fr', 'message' => 'Bonjour',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.numero.0', 'Le numéro contient du texte : corrigez la fiche.');

        $this->withToken($this->atlas['token'])->postJson($url, [
            'type' => 'facture', 'langue' => 'en', 'message' => 'Hello',
        ])->assertUnprocessable()->assertJsonValidationErrors('langue');

        $this->withToken($this->atlas['token'])->postJson($url, [
            'type' => 'facture', 'langue' => 'fr', 'message' => str_repeat('a', 2001),
        ])->assertUnprocessable()->assertJsonValidationErrors('message');

        $this->withToken($this->atlas['token'])->postJson($url, [
            'type' => 'facture', 'langue' => 'fr',
        ])->assertUnprocessable()->assertJsonValidationErrors('message');

        // 2 000 caractères arabes passent : la borne compte des caractères,
        // pas des octets.
        $this->withToken($this->atlas['token'])->postJson($url, [
            'type' => 'facture', 'langue' => 'ar', 'message' => str_repeat('ب', 2000),
        ])->assertCreated();
    }

    public function test_un_numero_qui_n_est_pas_une_chaine_est_refuse_sans_planter(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);

        // Un tableau faisait lever « Array to string conversion » dans la règle
        // du numéro : 500 au lieu de 422.
        foreach ([['0661000000'], ['+212' => '661000000'], 661000000, true] as $numero) {
            $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
                'type' => 'facture', 'numero' => $numero, 'langue' => 'fr', 'message' => 'Bonjour',
            ])->assertUnprocessable()->assertJsonValidationErrors('numero');

            $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
                'niveau' => 1, 'numero' => $numero, 'langue' => 'fr', 'message' => 'Rappel',
            ])->assertUnprocessable()->assertJsonValidationErrors('numero');
        }

        $this->assertCount(0, $this->journal());
        $this->assertCount(0, $this->relances());
    }

    public function test_reessayer_avec_la_cle_du_geste_ne_compte_pas_deux_relances(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);
        $cle = '3f2b8c1e-9d4a-4e6b-8a1f-0c2d3e4f5a6b';
        $corps = ['niveau' => 1, 'numero' => '0661000000', 'langue' => 'fr', 'message' => 'Rappel', 'cle' => $cle];
        $url = "/api/v1/relances/{$facture['id']}/whatsapp";

        $premier = $this->withToken($this->atlas['token'])->postJson($url, $corps)->assertCreated();

        // La réponse s'est perdue (WhatsApp au premier plan sur le téléphone),
        // l'utilisateur réessaie : la même ligne revient, rien ne s'ajoute.
        $second = $this->withToken($this->atlas['token'])->postJson($url, $corps)->assertOk();
        $this->assertSame($premier->json('data.id'), $second->json('data.id'));
        $this->assertSame($premier->json('data.relance.id'), $second->json('data.relance.id'));
        $this->assertSame($premier->json('data.lien_whatsapp'), $second->json('data.lien_whatsapp'));

        // La casse ne fait pas une autre clé (PostgreSQL compare un uuid sans elle).
        $this->withToken($this->atlas['token'])->postJson($url, ['cle' => strtoupper($cle)] + $corps)->assertOk();

        // Rejouable même si la facture a été soldée entre-temps : l'envoi a eu lieu.
        $this->paiement($this->atlas['token'], $facture['id'], 1200);
        $this->withToken($this->atlas['token'])->postJson($url, $corps)->assertOk()
            ->assertJsonPath('data.id', $premier->json('data.id'));

        $this->assertCount(1, $this->journal());
        $this->assertCount(1, $this->relances());
        $this->assertSame($cle, $this->journal()->sole()->cle);

        // Sans clé, chaque appel reste un envoi distinct (appels qui n'en ont pas).
        $autre = $this->factureEchue($this->atlas['token'], $this->client);
        $sansCle = ['niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel'];
        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$autre['id']}/whatsapp", $sansCle)->assertCreated();
        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$autre['id']}/whatsapp", $sansCle)->assertCreated();
        $this->assertCount(3, $this->relances());
    }

    public function test_la_cle_du_geste_ne_sert_qu_un_message_et_reste_dans_son_entreprise(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);
        $autre = $this->piece($this->atlas['token'], $this->client);
        $cle = '7a1c2e3f-4b5d-4c6e-9f80-a1b2c3d4e5f6';
        $corps = ['type' => 'facture', 'langue' => 'fr', 'message' => 'Votre facture', 'cle' => $cle];

        $premier = $this->withToken($this->atlas['token'])
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", $corps)->assertCreated();
        $this->withToken($this->atlas['token'])
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", $corps)->assertOk()
            ->assertJsonPath('data.id', $premier->json('data.id'));

        // La même clé pour une autre pièce, ou pour la relance de celle-ci :
        // appel incohérent, refusé plutôt que de renvoyer l'envoi d'un autre.
        $this->withToken($this->atlas['token'])
            ->postJson("/api/v1/ventes/documents/{$autre['id']}/whatsapp", $corps)
            ->assertUnprocessable()
            ->assertJsonPath('errors.cle.0', 'Cette clé d\'envoi a déjà servi pour un autre message.');
        $this->withToken($this->atlas['token'])
            ->postJson("/api/v1/relances/{$facture['id']}/whatsapp", ['niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel', 'cle' => $cle])
            ->assertUnprocessable()->assertJsonValidationErrors('cle');

        $this->withToken($this->atlas['token'])
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", ['cle' => 'pas-une-cle'] + $corps)
            ->assertUnprocessable()->assertJsonValidationErrors('cle');

        // Une autre entreprise qui tire la même clé n'apprend rien de la
        // première : son envoi est écrit chez elle, sans refus révélateur.
        $concurrent = $this->entreprise('Concurrent', 'patron@concurrent.ma');
        $factureConcurrent = $this->piece($concurrent['token'], $this->client($concurrent['token'], 'Client Y'));
        $this->withToken($concurrent['token'])
            ->postJson("/api/v1/ventes/documents/{$factureConcurrent['id']}/whatsapp", $corps)->assertCreated();

        $this->assertSame(
            [$this->atlas['tenant_id'], $concurrent['tenant_id']],
            $this->journal()->pluck('tenant_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_journaliser_une_relance_cree_la_relance_canal_whatsapp(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);

        $reponse = $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
            'niveau' => 2,
            'numero' => '+212 661 00 00 00',
            'langue' => 'fr',
            'message' => 'Second rappel',
            'note' => 'Promet un virement vendredi',
        ])->assertCreated();

        $relance = $this->relances()->sole();
        $this->assertSame('whatsapp', $relance->canal);
        $this->assertSame(2, $relance->niveau);
        $this->assertSame('Promet un virement vendredi', $relance->note);
        $this->assertSame($this->atlas['user_id'], (int) $relance->user_id);

        $ligne = $this->journal()->sole();
        $this->assertSame('relance', $ligne->type);
        $this->assertSame(2, $ligne->niveau);
        $this->assertSame($relance->id, (int) $ligne->relance_id);

        $reponse->assertJsonPath('data.relance.id', $relance->id)
            ->assertJsonPath('data.relance.canal', 'whatsapp')
            ->assertJsonPath('data.relance.niveau_label', 'Relance ferme')
            ->assertJsonPath('data.relance_id', $relance->id);

        // L'historique et la liste « à relancer » la voient comme toute relance.
        $historique = $this->withToken($this->atlas['token'])->getJson("/api/v1/relances/{$facture['id']}/historique")
            ->assertOk()->json('data');
        $this->assertSame('whatsapp', $historique[0]['canal']);
        $this->assertSame('WhatsApp', $historique[0]['canal_label']);

        $ligneARelancer = collect($this->withToken($this->atlas['token'])->getJson('/api/v1/relances/a-relancer')->json('data'))
            ->firstWhere('document_vente_id', $facture['id']);
        $this->assertSame(2, $ligneARelancer['dernier_niveau']);
        $this->assertSame('whatsapp', $ligneARelancer['dernier_canal']);

        $envois = $this->withToken($this->atlas['token'])->getJson("/api/v1/relances/{$facture['id']}/envois")
            ->assertOk()->json('data');
        $this->assertCount(1, $envois);
        $this->assertSame('relance', $envois[0]['type']);
    }

    public function test_relance_et_journal_dans_la_meme_transaction(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);

        // Le journal tombe en panne APRÈS la création de la Relance : elle
        // doit disparaître avec lui. La panne note d'abord ce qu'elle voit,
        // pour prouver que la Relance existait bien au moment de l'échec — et
        // que le test n'échoue pas pour une autre raison.
        $vu = new \ArrayObject;
        $this->app->bind(JournalEnvois::class, fn () => new class($vu) extends JournalEnvois
        {
            public function __construct(private \ArrayObject $vu) {}

            public function enregistrer(...$arguments): Communication
            {
                $this->vu['relances'] = Relance::withoutGlobalScopes()->count();

                throw new \RuntimeException('Panne simulée du journal.');
            }
        });

        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
            'niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel',
        ])->assertServerError();

        $this->assertSame(1, $vu['relances'] ?? null, 'La Relance devait exister au moment de la panne.');
        $this->assertCount(0, $this->relances(), 'Une relance sans trace de son message ne doit pas rester.');
        $this->assertCount(0, $this->journal());
    }

    public function test_une_relance_soldee_ou_prematuree_ne_se_journalise_pas(): void
    {
        $soldee = $this->factureEchue($this->atlas['token'], $this->client);
        $this->paiement($this->atlas['token'], $soldee['id'], 1200);
        $aVenir = $this->piece($this->atlas['token'], $this->client, 'facture', null, now()->addDays(10)->toDateString());

        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$soldee['id']}/whatsapp", [
            'niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel',
        ])->assertUnprocessable()->assertJsonPath('errors.document.0', 'Cette facture est déjà soldée.');

        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$aVenir['id']}/whatsapp", [
            'niveau' => 3, 'langue' => 'fr', 'message' => 'Mise en demeure',
        ])->assertUnprocessable()->assertJsonValidationErrors('niveau');

        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$aVenir['id']}/whatsapp", [
            'niveau' => 9, 'langue' => 'fr', 'message' => 'Rappel',
        ])->assertUnprocessable()->assertJsonValidationErrors('niveau');

        $this->assertCount(0, $this->relances());
        $this->assertCount(0, $this->journal());
    }

    public function test_le_canal_whatsapp_est_accepte_par_la_relance_manuelle(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);

        $this->withToken($this->atlas['token'])->postJson('/api/v1/relances', [
            'document_vente_id' => $facture['id'], 'niveau' => 1, 'canal' => 'whatsapp',
        ])->assertCreated();

        $this->withToken($this->atlas['token'])->postJson('/api/v1/relances', [
            'document_vente_id' => $facture['id'], 'niveau' => 1, 'canal' => 'pigeon',
        ])->assertUnprocessable()->assertJsonValidationErrors('canal');
    }

    public function test_la_liste_des_ventes_montre_les_relances_a_qui_peut_les_lire(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);
        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Votre facture',
        ])->assertCreated();
        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
            'niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel',
        ])->assertCreated();

        $types = fn () => array_column(
            $this->withToken($this->atlas['token'])->getJson("/api/v1/ventes/documents/{$facture['id']}/envois")->assertOk()->json('data'),
            'type',
        );

        // Le plus récent d'abord.
        $this->assertSame(['relance', 'facture'], $types());

        // Module de recouvrement désactivé : les relances sortent de la liste.
        $this->withToken($this->atlas['token'])->putJson('/api/v1/parametres', ['features' => ['relances' => false]])->assertOk();
        $this->assertSame(['facture'], $types());
        $this->withToken($this->atlas['token'])->getJson("/api/v1/relances/{$facture['id']}/envois")->assertForbidden();
        $this->withToken($this->atlas['token'])->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
            'niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel',
        ])->assertForbidden();
    }

    public function test_la_lecture_seule_ne_journalise_pas(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);
        $lecture = $this->jetonPourRole($this->atlas['tenant_id'], 'lecture');

        $this->withToken($lecture)->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Bonjour',
        ])->assertForbidden();
        $this->withToken($lecture)->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
            'niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel',
        ])->assertForbidden();

        // Mais elle lit les envois.
        $this->withToken($lecture)->getJson("/api/v1/ventes/documents/{$facture['id']}/envois")->assertOk();
        $this->withToken($lecture)->getJson("/api/v1/relances/{$facture['id']}/envois")->assertOk();

        $this->assertCount(0, $this->journal());
        $this->assertCount(0, $this->relances());
    }

    public function test_le_comptable_relance_sans_pouvoir_envoyer_une_facture(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);
        $comptable = $this->jetonPourRole($this->atlas['tenant_id'], 'comptable');

        // Les ventes en lecture seule : pas d'envoi de facture.
        $this->withToken($comptable)->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Bonjour',
        ])->assertForbidden();

        // Le recouvrement en écriture : la relance passe.
        $this->withToken($comptable)->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
            'niveau' => 1, 'langue' => 'fr', 'message' => 'Rappel',
        ])->assertCreated();
    }

    public function test_les_envois_sont_cloisonnes_par_entreprise(): void
    {
        $facture = $this->factureEchue($this->atlas['token'], $this->client);
        $this->withToken($this->atlas['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Secret d\'Atlas',
        ])->assertCreated();

        $autre = $this->entreprise('Concurrent', 'patron@concurrent.ma');
        $clientAutre = $this->client($autre['token'], 'Client Y');
        $factureAutre = $this->factureEchue($autre['token'], $clientAutre);

        // Le document d'Atlas n'existe pas pour le concurrent : ni lecture, ni écriture.
        $this->withToken($autre['token'])->getJson("/api/v1/ventes/documents/{$facture['id']}/envois")->assertNotFound();
        $this->withToken($autre['token'])->getJson("/api/v1/relances/{$facture['id']}/envois")->assertNotFound();
        $this->withToken($autre['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Intrusion',
        ])->assertNotFound();
        $this->withToken($autre['token'])->postJson("/api/v1/relances/{$facture['id']}/whatsapp", [
            'niveau' => 1, 'langue' => 'fr', 'message' => 'Intrusion',
        ])->assertNotFound();

        // Son propre envoi est rangé chez lui, et sa liste ne montre que lui.
        $this->withToken($autre['token'])->postJson("/api/v1/ventes/documents/{$factureAutre['id']}/whatsapp", [
            'type' => 'facture', 'langue' => 'fr', 'message' => 'Message du concurrent',
        ])->assertCreated();

        $liste = $this->withToken($autre['token'])->getJson("/api/v1/ventes/documents/{$factureAutre['id']}/envois")->json('data');
        $this->assertSame(['Message du concurrent'], array_column($liste, 'message'));

        $parEntreprise = $this->journal()->groupBy('tenant_id')->map->count();
        $this->assertSame(1, $parEntreprise[$this->atlas['tenant_id']]);
        $this->assertSame(1, $parEntreprise[$autre['tenant_id']]);

        // Au niveau du modèle : le scope ne laisse voir que l'entreprise posée.
        $contexte = app(TenantContext::class);
        $this->assertSame(['Secret d\'Atlas'], $contexte->runAs(
            Tenant::find($this->atlas['tenant_id']),
            fn () => Communication::query()->pluck('message')->all(),
        ));
        $this->assertSame(['Message du concurrent'], $contexte->runAs(
            Tenant::find($autre['tenant_id']),
            fn () => Communication::query()->pluck('message')->all(),
        ));
    }

    public function test_l_auteur_parti_reste_nomme(): void
    {
        $facture = $this->piece($this->atlas['token'], $this->client);
        $commercial = User::factory()->create([
            'tenant_id' => $this->atlas['tenant_id'], 'role' => 'commercial', 'is_active' => true, 'name' => 'Yassine Commercial',
        ]);

        $this->withToken($commercial->createToken('spa')->plainTextToken)
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/whatsapp", [
                'type' => 'facture', 'langue' => 'fr', 'message' => 'Bonjour',
            ])->assertCreated();

        // Retiré de l'équipe par la vraie route : sa ligne `users` disparaît.
        $this->withToken($this->atlas['token'])->deleteJson("/api/v1/equipe/users/{$commercial->id}")->assertSuccessful();
        $this->assertNull(User::find($commercial->id));

        $liste = $this->withToken($this->atlas['token'])->getJson("/api/v1/ventes/documents/{$facture['id']}/envois")->json('data');
        $this->assertNull($liste[0]['auteur']['id']);
        $this->assertSame('Yassine Commercial', $liste[0]['auteur']['nom']);
    }
}
