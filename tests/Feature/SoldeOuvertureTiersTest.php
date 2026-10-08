<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Compta\Models\ComptaMapping;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Models\Exercice;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\SoldeOuvertureService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\EncoursService;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le solde d'ouverture d'un tiers, saisi depuis sa fiche.
 *
 * Éprouvé : l'écriture part au journal OD — jamais AN, où rien ne la verrait —
 * sur le compte collectif MAPPÉ du tiers, contre un compte d'attente du CGNC
 * (4497 si elle est au crédit, 3497 au débit) ; elle se voit aussitôt dans la
 * liste, la vue d'ensemble et l'encours ; il n'y en a qu'UNE par tiers, même
 * à deux clics simultanés ; la garde est celle de la compta ; un exercice
 * clôturé la refuse ; et rien ne passe d'une entreprise à l'autre.
 */
class SoldeOuvertureTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->jeton = $this->inscrire('Media Desk', 'admin@mediadesk.ma');
        $this->entreprise = User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant;
        $this->dansEntreprise($this->entreprise);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function inscrire(string $societe, string $email): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => $societe, 'name' => 'Admin', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    private function dansEntreprise(Tenant $entreprise): void
    {
        app(TenantContext::class)->set($entreprise);
    }

    private function tiers(string $nom, array $attributs = []): Tiers
    {
        return app(TiersService::class)->create(array_merge([
            'name' => $nom, 'is_client' => true, 'is_supplier' => false,
        ], $attributs));
    }

    private function jetonRole(string $role): string
    {
        return User::factory()->create([
            'tenant_id' => $this->entreprise->id, 'role' => $role, 'is_active' => true,
        ])->createToken('spa')->plainTextToken;
    }

    private function saisir(Tiers $tiers, array $donnees, ?string $jeton = null): TestResponse
    {
        $reponse = $this->withToken($jeton ?? $this->jeton)
            ->postJson("/api/v1/tiers/{$tiers->id}/solde-ouverture", $donnees);

        $this->dansEntreprise($this->entreprise);

        return $reponse;
    }

    /** L'écriture marquée du tiers, avec ses lignes et leurs comptes. */
    private function ecriture(Tiers $tiers): ?Ecriture
    {
        return Ecriture::where('solde_ouverture_tiers_id', $tiers->id)->with('lignes.compte')->first();
    }

    /* ------------------------------- client ------------------------------- */

    public function test_un_client_debiteur_passe_en_od_sur_le_compte_clients_contre_4497(): void
    {
        $client = $this->tiers('WYDAD');

        $reponse = $this->saisir($client, ['montant' => 1250.5, 'sens' => 'debit', 'date' => '2026-01-01'])
            ->assertCreated()->json('data');

        $this->assertSame('1250.50', $reponse['montant']);
        $this->assertSame('debit', $reponse['sens']);
        $this->assertSame('client', $reponse['compte']);
        $this->assertSame('2026-01-01', $reponse['date']);

        $ecriture = $this->ecriture($client);
        $this->assertNotNull($ecriture);
        $this->assertSame(Ecriture::JOURNAL_DIVERS, $ecriture->journal);
        $this->assertStringStartsWith('OD-2026-', $ecriture->numero);
        $this->assertFalse($ecriture->is_auto);

        $ligneTiers = $ecriture->lignes->firstWhere('tiers_id', $client->id);
        $this->assertSame('3421', $ligneTiers->compte->code);
        $this->assertSame('1250.50', $ligneTiers->debit);
        $this->assertSame('0.00', $ligneTiers->credit);

        $attente = $ecriture->lignes->firstWhere('tiers_id', null);
        $this->assertSame('4497', $attente->compte->code);
        $this->assertSame('1250.50', $attente->credit);
        $this->assertCount(2, $ecriture->lignes);
    }

    public function test_un_client_crediteur_passe_au_credit_contre_3497(): void
    {
        $client = $this->tiers('INTER CLINIC');

        $this->saisir($client, ['montant' => 300, 'sens' => 'credit', 'date' => '2026-01-01'])->assertCreated();

        $ecriture = $this->ecriture($client);
        $ligneTiers = $ecriture->lignes->firstWhere('tiers_id', $client->id);
        $this->assertSame('3421', $ligneTiers->compte->code);
        $this->assertSame('300.00', $ligneTiers->credit);
        $this->assertSame('3497', $ecriture->lignes->firstWhere('tiers_id', null)->compte->code);

        $this->assertSame(-300.0, app(EncoursService::class)->soldesSignes([$client->id])[$client->id]);
    }

    public function test_le_solde_d_ouverture_se_voit_dans_la_liste_la_vue_d_ensemble_et_l_encours(): void
    {
        $client = $this->tiers('McKinsey');

        $this->saisir($client, ['montant' => 17539.2, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();

        $liste = collect($this->withToken($this->jeton)->getJson('/api/v1/tiers?avec_solde=1')->json('data'))->keyBy('id');
        $this->assertSame('17539.20', $liste[$client->id]['solde']);

        $vue = $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client->id}/vue-ensemble")->assertOk()->json('data');
        $this->assertSame('17539.20', $vue['compte']['creances']);
        $this->assertSame([
            'numero' => $this->ecriture($client)->numero, 'date' => '2026-01-01',
            'montant' => '17539.20', 'sens' => 'debit', 'compte' => 'client',
        ], $vue['solde_ouverture']);

        $this->dansEntreprise($this->entreprise);
        $this->assertSame(17539.2, app(EncoursService::class)->pour($client));
    }

    public function test_sans_solde_d_ouverture_la_vue_d_ensemble_le_dit_null(): void
    {
        $client = $this->tiers('Sans ouverture');

        $vue = $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client->id}/vue-ensemble")->assertOk()->json('data');

        $this->assertArrayHasKey('solde_ouverture', $vue);
        $this->assertNull($vue['solde_ouverture']);
    }

    public function test_le_compte_suit_le_mapping_de_l_entreprise_et_pas_3421_en_dur(): void
    {
        $client = $this->tiers('Remappé');
        app(ComptaService::class)->initialiserPlanComptable();
        $autre = Compte::create(['code' => '34211', 'label' => 'Clients — grands comptes', 'classe' => 3, 'is_system' => false]);
        ComptaMapping::where('cle', 'clients')->update(['compte_id' => $autre->id]);

        $this->saisir($client, ['montant' => 100, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();

        $this->assertSame('34211', $this->ecriture($client)->lignes->firstWhere('tiers_id', $client->id)->compte->code);
        // Et l'encours, qui lit le même mapping, le voit.
        $this->assertSame(100.0, app(EncoursService::class)->pour($client));
    }

    /* ---------------------------- fournisseur ---------------------------- */

    public function test_un_fournisseur_pur_passe_par_defaut_au_compte_fournisseurs(): void
    {
        $fournisseur = $this->tiers('Cimenterie Atlas', ['is_client' => false, 'is_supplier' => true]);

        $reponse = $this->saisir($fournisseur, ['montant' => 8000, 'sens' => 'credit', 'date' => '2026-01-01'])
            ->assertCreated()->json('data');
        $this->assertSame('fournisseur', $reponse['compte']);

        $ecriture = $this->ecriture($fournisseur);
        $ligne = $ecriture->lignes->firstWhere('tiers_id', $fournisseur->id);
        $this->assertSame('4411', $ligne->compte->code);
        $this->assertSame('8000.00', $ligne->credit);
        $this->assertSame('3497', $ecriture->lignes->firstWhere('tiers_id', null)->compte->code);
    }

    public function test_le_compte_doit_etre_celui_du_tiers(): void
    {
        $client = $this->tiers('Pur client');
        $fournisseur = $this->tiers('Pur fournisseur', ['is_client' => false, 'is_supplier' => true]);

        $this->saisir($client, ['montant' => 10, 'sens' => 'credit', 'date' => '2026-01-01', 'compte' => 'fournisseur'])
            ->assertUnprocessable()->assertJsonValidationErrors('compte');
        $this->saisir($fournisseur, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01', 'compte' => 'client'])
            ->assertUnprocessable()->assertJsonValidationErrors('compte');

        $this->assertSame(0, Ecriture::count());
    }

    public function test_un_tiers_client_et_fournisseur_choisit_son_compte(): void
    {
        $mixte = $this->tiers('Mixte', ['is_client' => true, 'is_supplier' => true]);

        $this->saisir($mixte, ['montant' => 50, 'sens' => 'credit', 'date' => '2026-01-01', 'compte' => 'fournisseur'])
            ->assertCreated()->assertJsonPath('data.compte', 'fournisseur');

        $this->assertSame('4411', $this->ecriture($mixte)->lignes->firstWhere('tiers_id', $mixte->id)->compte->code);
    }

    /* ------------------------------ unicité ------------------------------ */

    public function test_le_second_solde_d_ouverture_est_refuse_avec_un_message(): void
    {
        $client = $this->tiers('WYDAD');
        $this->saisir($client, ['montant' => 100, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();

        $refus = $this->saisir($client, ['montant' => 999, 'sens' => 'credit', 'date' => '2026-02-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('solde_ouverture');
        $this->assertStringContainsString('déjà été saisi', $refus->json('message'));

        $this->assertSame(1, Ecriture::where('journal', Ecriture::JOURNAL_DIVERS)->count());
        $this->assertSame(100.0, app(EncoursService::class)->pour($client));
    }

    public function test_l_index_unique_arrete_ce_que_le_controle_laisserait_passer(): void
    {
        $client = $this->tiers('WYDAD');
        $this->saisir($client, ['montant' => 100, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();

        // Ce que ferait un second clic parti avant la fin du premier : le
        // contrôle préalable est passé, seule la base peut encore dire non.
        // Dans un point de sauvegarde, pour que PostgreSQL survive à l'échec.
        $this->expectException(UniqueConstraintViolationException::class);
        DB::transaction(fn () => Ecriture::create([
            'journal' => 'OD', 'numero' => 'OD-2026-99999', 'date_ecriture' => '2026-01-01', 'libelle' => 'Doublon',
        ])->forceFill(['solde_ouverture_tiers_id' => $client->id])->save());
    }

    /**
     * Le second clic d'une course, PAR LE SERVICE : son contrôle préalable ne
     * voit pas encore l'écriture du premier (on le neutralise), l'index de la
     * marque refuse, et le refus devient le même 422 lisible.
     */
    public function test_la_course_perdue_devient_le_message_deja_saisi(): void
    {
        $client = $this->tiers('WYDAD');
        $this->saisir($client, ['montant' => 100, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();

        $this->app->bind(SoldeOuvertureService::class, fn ($app) => new class($app->make(ComptaService::class)) extends SoldeOuvertureService
        {
            protected function ecriture(Tiers $tiers): ?Ecriture
            {
                return null;
            }
        });

        $this->saisir($client, ['montant' => 999, 'sens' => 'debit', 'date' => '2026-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['solde_ouverture' => 'déjà été saisi']);

        // Et la transaction perdante n'a rien laissé derrière elle.
        $this->assertSame(1, Ecriture::where('journal', Ecriture::JOURNAL_DIVERS)->count());
        $this->assertSame(100.0, app(EncoursService::class)->pour($client));
    }

    /**
     * Une collision de NUMÉRO n'est pas un solde déjà saisi : un compteur OD
     * en retard sur la série (le cas que décrit RenumerotationService) fait
     * buter l'autre index de la transaction, et le dire « déjà saisi » à un
     * tiers qui n'en a aucun mentait. L'erreur remonte telle quelle.
     */
    public function test_une_collision_de_numero_n_est_pas_maquillee_en_deja_saisi(): void
    {
        $this->saisir($this->tiers('A'), ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();
        $this->saisir($this->tiers('B'), ['montant' => 20, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();
        $c = $this->tiers('C');

        DB::table('sequences')->where('tenant_id', $this->entreprise->id)->where('code', 'OD')->where('year', 2026)->update(['counter' => 0]);

        try {
            app(SoldeOuvertureService::class)->saisir($c, ['montant' => 30, 'sens' => 'debit', 'date' => '2026-01-01']);
            $this->fail('La collision de numéro devait remonter.');
        } catch (UniqueConstraintViolationException $collision) {
            $this->assertStringNotContainsString('solde_ouverture_tiers', ($collision->getPrevious() ?? $collision)->getMessage());
        }

        $this->assertNull(app(SoldeOuvertureService::class)->existant($c));
    }

    /* --------------------------- numérotation --------------------------- */

    /**
     * Une ouverture antidatée prend le numéro OD SUIVANT : une OD de la même
     * année datée après elle porte un numéro plus petit. On ne renumérote pas
     * d'office (d'autres écritures changeraient de numéro) : on le dit.
     */
    public function test_une_ouverture_antidatee_signale_la_serie_od_a_renumeroter(): void
    {
        $a = $this->tiers('A');
        $b = $this->tiers('B');

        $premier = $this->saisir($a, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-03-01'])->assertCreated();
        $this->assertNull($premier->json('avertissement'));

        $second = $this->saisir($b, ['montant' => 20, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();
        $this->assertStringContainsString('Le journal OD 2026', $second->json('avertissement'));
        $this->assertStringContainsString('1 écriture(s) datée(s) après le 01/01/2026', $second->json('avertissement'));

        // Rien n'a été renuméroté.
        $this->assertSame('OD-2026-00001', $this->ecriture($a)->numero);
        $this->assertSame('OD-2026-00002', $this->ecriture($b)->numero);

        // Une OD d'une AUTRE année ne compte pas.
        $this->saisir($this->tiers('C'), ['montant' => 30, 'sens' => 'debit', 'date' => '2025-12-31'])
            ->assertCreated()->assertJsonPath('avertissement', null);
    }

    /* --------------------- contexte avant la saisie --------------------- */

    private function contexte(Tiers $tiers, ?string $jeton = null): TestResponse
    {
        $reponse = $this->withToken($jeton ?? $this->jeton)->getJson("/api/v1/tiers/{$tiers->id}/solde-ouverture");
        $this->dansEntreprise($this->entreprise);

        return $reponse;
    }

    /** Une facture de vente validée, pour qu'un tiers ait déjà des mouvements. */
    private function facture(Tiers $tiers, string $date): void
    {
        $document = app(VenteService::class)->create([
            'type' => DocumentVente::TYPE_FACTURE, 'tiers_id' => $tiers->id, 'date_document' => $date,
            'lignes' => [['designation' => 'Maintenance', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ]);
        app(VenteService::class)->valider($document);
    }

    public function test_le_contexte_propose_la_veille_du_premier_mouvement_et_les_compte(): void
    {
        $neuf = $this->tiers('Neuf');
        $this->assertSame([
            'existant' => null,
            'comptes' => [['compte' => 'client', 'mouvements' => 0, 'premier' => null, 'date_proposee' => '2026-01-01']],
        ], $this->contexte($neuf)->assertOk()->json('data'));

        // Un client repris avec son historique : la veille de sa première pièce.
        $repris = $this->tiers('WYDAD');
        $this->facture($repris, '2026-02-10');
        $this->facture($repris, '2026-05-03');
        $this->assertSame(
            ['compte' => 'client', 'mouvements' => 2, 'premier' => '2026-02-10', 'date_proposee' => '2026-02-09'],
            $this->contexte($repris)->json('data.comptes.0'),
        );

        // Client ET fournisseur : les deux comptes, le client d'abord.
        $mixte = $this->tiers('Mixte', ['is_client' => true, 'is_supplier' => true]);
        $this->assertSame(['client', 'fournisseur'], array_column($this->contexte($mixte)->json('data.comptes'), 'compte'));
    }

    public function test_la_date_proposee_ne_tombe_jamais_dans_un_exercice_cloture(): void
    {
        $client = $this->tiers('Ancien');
        $this->facture($client, '2025-06-10');
        Exercice::create(['annee' => 2025, 'resultat' => 0, 'cloture_at' => now()]);

        $this->assertSame(
            ['compte' => 'client', 'mouvements' => 1, 'premier' => '2025-06-10', 'date_proposee' => '2026-01-01'],
            $this->contexte($client)->json('data.comptes.0'),
        );
    }

    public function test_le_contexte_est_sous_la_garde_de_la_compta(): void
    {
        $client = $this->tiers('WYDAD');

        $this->contexte($client, $this->jetonRole('commercial'))->assertForbidden();
        $this->contexte($client, $this->jetonRole('caissier'))->assertForbidden();
        $this->contexte($client, $this->jetonRole('comptable'))->assertOk();
        $this->contexte($client, $this->inscrire('Concurrent', 'admin@concurrent.ma'))->assertNotFound();
    }

    /* ------------------------------ clôture ------------------------------ */

    /**
     * La contrepartie d'attente doit être soldée avant la clôture : reportée
     * en à-nouveaux sans un mot, l'écart devenait permanent au bilan.
     */
    public function test_la_cloture_refuse_des_comptes_d_attente_non_soldes(): void
    {
        $client = $this->tiers('WYDAD');
        $this->saisir($client, ['montant' => 1000, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();

        $refus = $this->withToken($this->jeton)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => 2026])
            ->assertUnprocessable()->assertJsonValidationErrors('annee');
        $this->assertStringContainsString('4497 créditeur de 1 000,00', $refus->json('errors.annee.0'));
        $this->dansEntreprise($this->entreprise);
        $this->assertSame(0, Exercice::count());

        // La régularisation du comptable — ici contre le report à nouveau —
        // ramène 4497 à zéro : la clôture passe.
        $compta = app(ComptaService::class);
        $compta->ecrire(Ecriture::JOURNAL_DIVERS, '2026-12-31', 'Régularisation de l\'attente', [
            ['compte' => $compta->compteParCode('4497'), 'debit' => 1000, 'credit' => 0],
            ['compte' => $compta->compteParCode('1161'), 'debit' => 0, 'credit' => 1000],
        ]);

        $this->withToken($this->jeton)->postJson('/api/v1/compta/exercices/cloturer', ['annee' => 2026])->assertCreated();
    }

    /* ------------------------------- droits ------------------------------- */

    public function test_la_garde_est_celle_de_la_compta(): void
    {
        $client = $this->tiers('WYDAD');

        // Le commercial modifie les tiers, mais n'a aucun droit en compta.
        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01'], $this->jetonRole('commercial'))
            ->assertForbidden();
        // La lecture seule lit la compta, n'y écrit pas.
        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01'], $this->jetonRole('lecture'))
            ->assertForbidden();
        $this->assertSame(0, Ecriture::count());

        // Le comptable n'a que la LECTURE des tiers : c'est pourtant à lui de
        // passer l'écriture.
        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01'], $this->jetonRole('comptable'))
            ->assertCreated();
    }

    public function test_une_autre_entreprise_ne_peut_ni_saisir_ni_voir(): void
    {
        $client = $this->tiers('WYDAD');
        $jetonB = $this->inscrire('Concurrent', 'admin@concurrent.ma');

        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01'], $jetonB)->assertNotFound();
        $this->assertSame(0, Ecriture::count());

        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();

        // L'écriture n'existe pas chez le concurrent, ni sa marque.
        $entrepriseB = User::withoutGlobalScopes()->firstWhere('email', 'admin@concurrent.ma')->tenant;
        $this->dansEntreprise($entrepriseB);
        $this->assertSame(0, Ecriture::count());
        $this->assertNull(Ecriture::where('solde_ouverture_tiers_id', $client->id)->first());
        $this->dansEntreprise($this->entreprise);
    }

    /* ------------------------ dates et validations ------------------------ */

    public function test_un_exercice_cloture_refuse_le_solde_d_ouverture(): void
    {
        $client = $this->tiers('WYDAD');
        Exercice::create(['annee' => 2025, 'resultat' => 0, 'cloture_at' => now()]);

        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2025-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('date_ecriture');
        $this->assertNull($this->ecriture($client));

        // Le premier jour de l'exercice ouvert passe.
        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-01-01'])->assertCreated();
    }

    public function test_les_saisies_invalides_sont_refusees(): void
    {
        $client = $this->tiers('WYDAD');

        $this->saisir($client, ['montant' => 0, 'sens' => 'debit', 'date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->saisir($client, ['montant' => 10.555, 'sens' => 'debit', 'date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->saisir($client, ['montant' => 10, 'sens' => 'gauche', 'date' => '2026-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('sens');
        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->saisir($client, ['montant' => 10, 'sens' => 'debit', 'date' => '01/01/2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        $this->assertSame(0, Ecriture::count());
        $this->assertSame(0, EcritureLigne::count());
    }

    public function test_une_entreprise_seedee_avant_ces_comptes_les_recoit_par_rattrapage(): void
    {
        // Une entreprise dont le plan a été seedé avant l'ajout de 3497 et
        // 4497 : le rattrapage d'initialiserPlanComptable les lui ajoute.
        app(ComptaService::class)->initialiserPlanComptable();
        Compte::whereIn('code', ['3497', '4497'])->delete();
        $this->assertSame(0, Compte::whereIn('code', ['3497', '4497'])->count());

        app(ComptaService::class)->initialiserPlanComptable();

        $this->assertSame(3, Compte::where('code', '3497')->value('classe'));
        $this->assertSame(4, Compte::where('code', '4497')->value('classe'));
        $this->assertTrue(Compte::where('code', '4497')->value('is_system'));
    }

    /**
     * Un 4497 créé à la main (non système) suffit : le plan est complet, et
     * le rattrapage ne se rejoue pas à chaque appel. Il comptait les comptes
     * SYSTÈME : court d'un pour toujours, il refaisait ses 87 requêtes à
     * chaque liste, chaque relevé, chaque écriture.
     */
    public function test_un_compte_d_attente_cree_a_la_main_ne_relance_pas_le_rattrapage(): void
    {
        $compta = app(ComptaService::class);
        $compta->initialiserPlanComptable();
        Compte::where('code', '4497')->update(['is_system' => false]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $compta->initialiserPlanComptable();
        $compta->initialiserPlanComptable();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Deux comptages par appel (comptes, mappings), et rien d'autre.
        $this->assertSame(4, $requetes);
        $this->assertFalse(Compte::where('code', '4497')->value('is_system'));
    }

    /**
     * La migration de données pose 3497/4497 chez chaque entreprise qui a un
     * plan — pour qu'aucune lecture n'écrive après le déploiement —, sans
     * toucher à un compte que l'entreprise a déjà, ni créer de plan à qui
     * n'en a pas.
     */
    public function test_la_migration_pose_les_comptes_d_attente_sans_ecraser_l_existant(): void
    {
        app(ComptaService::class)->initialiserPlanComptable();
        Compte::where('code', '3497')->delete();
        Compte::where('code', '4497')->update(['label' => 'Attente — reprise', 'is_system' => false]);
        $this->inscrire('Sans plan', 'admin@sansplan.ma');
        // L'inscription ne crée pas de plan : il naît à la première écriture.
        $sansPlan = User::withoutGlobalScopes()->firstWhere('email', 'admin@sansplan.ma')->tenant_id;
        $this->assertSame(0, DB::table('comptes')->where('tenant_id', $sansPlan)->count());
        $this->dansEntreprise($this->entreprise);

        (require database_path('migrations/2026_10_08_100004_ajouter_comptes_attente_aux_plans.php'))->up();

        $this->assertSame(3, Compte::where('code', '3497')->value('classe'));
        $this->assertTrue(Compte::where('code', '3497')->value('is_system'));
        $this->assertSame('Attente — reprise', Compte::where('code', '4497')->value('label'));
        $this->assertSame(0, DB::table('comptes')->where('tenant_id', $sansPlan)->count());
    }
}
