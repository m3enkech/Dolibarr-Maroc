<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Services\AchatService;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\LettrageService;
use App\Modules\Effets\Services\EffetService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\EncoursService;
use App\Modules\Tiers\Services\ReleveService;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Le relevé de compte d'un tiers, à l'écran et en PDF.
 *
 * L'égalité qui le fonde : le solde arrêté à aujourd'hui EST le solde
 * d'EncoursService — celui de la liste, de la caisse et de la vue d'ensemble —
 * lettrage, effets et à-nouveaux compris. Éprouvés aussi : le solde reporté au
 * premier jour, le cumul ligne à ligne, les bornes incluses, le compte
 * fournisseur et ses droits, le plafond de lignes, et l'étanchéité entre
 * entreprises sur une table (ecriture_lignes) qui n'a pas d'entreprise.
 */
class ReleveTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        // Mi-octobre 2026 : le défaut couvre du 1er janvier au 15 octobre.
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

    /** Facture (ou avoir) validée d'une ligne : HT + 20 % de TVA. */
    private function piece(Tiers $tiers, string $type, float $prixHt, string $date): DocumentVente
    {
        $document = app(VenteService::class)->create([
            'type' => $type, 'tiers_id' => $tiers->id, 'date_document' => $date,
            'lignes' => [['designation' => 'Maintenance', 'quantite' => 1, 'prix_unitaire' => $prixHt, 'tva_rate' => 20]],
        ]);

        return app(VenteService::class)->valider($document);
    }

    private function payer(DocumentVente $facture, float $montant, string $date): void
    {
        app(VenteService::class)->ajouterPaiement($facture, ['montant' => $montant, 'mode' => 'virement', 'date_paiement' => $date]);
    }

    /** @return array<string, mixed> */
    private function releve(Tiers $tiers, string $parametres = '', ?string $jeton = null): array
    {
        $donnees = $this->withToken($jeton ?? $this->jeton)
            ->getJson("/api/v1/tiers/{$tiers->id}/releve".($parametres !== '' ? "?{$parametres}" : ''))
            ->assertOk()
            ->json('data');

        $this->dansEntreprise($this->entreprise);

        return $donnees;
    }

    private function encours(Tiers $tiers): string
    {
        return number_format(app(EncoursService::class)->soldesSignes([$tiers->id])[$tiers->id] ?? 0.0, 2, '.', '');
    }

    /* ------------------------------ le calcul ------------------------------ */

    public function test_report_cumul_et_cloture_sur_la_periode_par_defaut(): void
    {
        $client = $this->tiers('WYDAD');

        $ancienne = $this->piece($client, DocumentVente::TYPE_FACTURE, 1000, '2025-11-20'); // 1 200 TTC
        $this->payer($ancienne, 200, '2025-12-15');
        $facture = $this->piece($client, DocumentVente::TYPE_FACTURE, 500, '2026-03-10'); // 600 TTC
        $this->payer($facture, 600, '2026-04-02');
        $this->piece($client, DocumentVente::TYPE_AVOIR, 100, '2026-05-05'); // 120 TTC

        $releve = $this->releve($client);

        $this->assertSame('client', $releve['compte']);
        $this->assertSame(['3421', '3425'], $releve['comptes']);
        $this->assertSame('2026-01-01', $releve['du']);
        $this->assertSame('2026-10-15', $releve['au']);
        // Ce qui restait dû au 31 décembre : 1 200 − 200. Le report est daté
        // de ce soir-là, pas du 1er janvier, dont les mouvements sont DANS la
        // période.
        $this->assertSame('2025-12-31', $releve['report_au']);
        $this->assertSame('1000.00', $releve['solde_initial']);

        $this->assertSame(3, $releve['nb_lignes']);
        $this->assertSame(
            [
                ['2026-03-10', $facture->code, '600.00', '0.00', '1600.00'],
                ['2026-04-02', $facture->code, '0.00', '600.00', '1000.00'],
                ['2026-05-05', DocumentVente::where('type', 'avoir')->value('code'), '0.00', '120.00', '880.00'],
            ],
            array_map(fn ($l) => [$l['date'], $l['piece'], $l['debit'], $l['credit'], $l['solde']], $releve['lignes']),
        );
        $this->assertSame('600.00', $releve['total_debit']);
        $this->assertSame('720.00', $releve['total_credit']);
        $this->assertSame('880.00', $releve['solde_final']);

        // La pièce de vente porte son lien ; le libellé est celui de l'écriture.
        $this->assertSame(['id' => $facture->id, 'type' => 'facture'], $releve['lignes'][0]['document']);
        $this->assertStringContainsString($facture->code, $releve['lignes'][0]['libelle']);

        // L'égalité qui fonde le relevé.
        $this->assertSame($this->encours($client), $releve['solde_final']);
    }

    public function test_la_cloture_egale_l_encours_avec_lettrage_effet_et_a_nouveaux(): void
    {
        $client = $this->tiers('INTER CLINIC');

        // Une facture réglée puis LETTRÉE : ses lignes sortent de l'encours
        // (non lettré), pas du relevé — et le total ne change pas, un groupe
        // lettré étant équilibré.
        $reglee = $this->piece($client, DocumentVente::TYPE_FACTURE, 1000, '2026-02-01');
        $this->payer($reglee, 1200, '2026-02-20');
        $clients = app(ComptaService::class)->compteParDefaut('clients');
        app(LettrageService::class)->lettrageAuto($clients->id);

        // Une facture tirée en traite : la créance passe de 3421 à 3425.
        $traite = $this->piece($client, DocumentVente::TYPE_FACTURE, 2000, '2026-03-01'); // 2 400 TTC
        app(EffetService::class)->creerARecevoir($traite, '2026-12-31');

        // Un à-nouveau qui porterait ce tiers : exclu des deux côtés.
        app(ComptaService::class)->ecrire(Ecriture::JOURNAL_A_NOUVEAUX, '2026-01-01', 'Report', [
            ['compte' => $clients, 'debit' => 5000, 'credit' => 0, 'tiers_id' => $client->id],
            ['compte' => app(ComptaService::class)->compteParCode('1161'), 'debit' => 0, 'credit' => 5000],
        ]);

        $releve = $this->releve($client);

        $this->assertSame('2400.00', $releve['solde_final']);
        $this->assertSame($this->encours($client), $releve['solde_final']);
        // Facture, règlement, facture, transfert 3421 → 3425 (deux lignes) :
        // tout est montré, rien de l'à-nouveau.
        $this->assertSame(5, $releve['nb_lignes']);
        $this->assertNotContains('AN', array_column($releve['lignes'], 'journal'));
    }

    public function test_le_solde_d_ouverture_saisi_ouvre_le_releve(): void
    {
        $client = $this->tiers('McKinsey');
        $this->withToken($this->jeton)->postJson("/api/v1/tiers/{$client->id}/solde-ouverture", [
            'montant' => 17539.2, 'sens' => 'debit', 'date' => '2026-01-01',
        ])->assertCreated();
        $this->dansEntreprise($this->entreprise);
        $this->piece($client, DocumentVente::TYPE_FACTURE, 100, '2026-02-01');

        $releve = $this->releve($client);

        $this->assertSame('0.00', $releve['solde_initial']);
        $this->assertSame("Solde d'ouverture", $releve['lignes'][0]['libelle']);
        $this->assertSame("OUV-{$client->code}", $releve['lignes'][0]['piece']);
        $this->assertNull($releve['lignes'][0]['document']);
        $this->assertSame('17539.20', $releve['lignes'][0]['solde']);
        $this->assertSame('17659.20', $releve['solde_final']);
        $this->assertSame('17539.20', $releve['solde_ouverture']['montant']);

        // Reporté, il entre dans le solde initial d'une période qui le suit.
        $this->assertSame('17539.20', $this->releve($client, 'du=2026-01-02')['solde_initial']);
    }

    public function test_les_bornes_sont_incluses_et_la_veille_part_au_report(): void
    {
        $client = $this->tiers('Bornes');
        $this->piece($client, DocumentVente::TYPE_FACTURE, 100, '2026-03-31'); // veille : report
        $this->piece($client, DocumentVente::TYPE_FACTURE, 200, '2026-04-01'); // premier jour
        $this->piece($client, DocumentVente::TYPE_FACTURE, 300, '2026-04-30'); // dernier jour
        $this->piece($client, DocumentVente::TYPE_FACTURE, 400, '2026-05-01'); // lendemain : hors

        $releve = $this->releve($client, 'du=2026-04-01&au=2026-04-30');

        $this->assertSame('120.00', $releve['solde_initial']);
        $this->assertSame(['2026-04-01', '2026-04-30'], array_column($releve['lignes'], 'date'));
        $this->assertSame('720.00', $releve['solde_final']);

        // Une seule journée : le report est celui de la VEILLE au soir, le
        // solde final celui du jour — deux dates, pas deux soldes à la même.
        $jour = $this->releve($client, 'du=2026-04-30&au=2026-04-30');
        $this->assertSame(['2026-04-30'], array_column($jour['lignes'], 'date'));
        $this->assertSame('2026-04-29', $jour['report_au']);
        $this->assertSame('360.00', $jour['solde_initial']);
        $this->assertSame('720.00', $jour['solde_final']);

        // « au » seul : la période part du 1er janvier de SON année.
        $this->assertSame('2025-01-01', $this->releve($client, 'au=2025-06-30')['du']);
    }

    public function test_des_bornes_inversees_ou_mal_ecrites_sont_refusees(): void
    {
        $client = $this->tiers('Bornes');

        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client->id}/releve?du=2026-05-01&au=2026-04-01")
            ->assertUnprocessable()->assertJsonValidationErrors('du');
        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client->id}/releve?du=01/04/2026")
            ->assertUnprocessable()->assertJsonValidationErrors('du');
        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client->id}/releve?compte=banque")
            ->assertUnprocessable()->assertJsonValidationErrors('compte');
    }

    /* ---------------------------- fournisseur ---------------------------- */

    private function factureAchat(Tiers $fournisseur, float $prixHt, string $date): DocumentAchat
    {
        $facture = app(AchatService::class)->create([
            'type' => DocumentAchat::TYPE_FACTURE, 'tiers_id' => $fournisseur->id, 'date_document' => $date,
            'lignes' => [['designation' => 'Transport', 'quantite' => 1, 'prix_unitaire' => $prixHt, 'tva_rate' => 20]],
        ]);

        return app(AchatService::class)->valider($facture);
    }

    public function test_le_releve_fournisseur_se_lit_dans_l_autre_sens(): void
    {
        $fournisseur = $this->tiers('Cimenterie Atlas', ['is_client' => false, 'is_supplier' => true]);
        $facture = $this->factureAchat($fournisseur, 1000, '2026-02-01'); // 1 200 TTC au crédit
        app(AchatService::class)->ajouterPaiement($facture, ['montant' => 500, 'mode' => 'virement', 'date_paiement' => '2026-03-01']);

        // Fournisseur pur : son compte par défaut.
        $releve = $this->releve($fournisseur);

        $this->assertSame('fournisseur', $releve['compte']);
        $this->assertContains('4411', $releve['comptes']);
        $this->assertSame(['1200.00', '700.00'], array_column($releve['lignes'], 'solde'));
        $this->assertSame('700.00', $releve['solde_final']);
        $this->assertSame($facture->code, $releve['lignes'][0]['piece']);
    }

    public function test_le_compte_fournisseur_exige_le_droit_achats_et_un_fournisseur(): void
    {
        $fournisseur = $this->tiers('Cimenterie Atlas', ['is_client' => false, 'is_supplier' => true]);
        $client = $this->tiers('Pur client');

        // Le commercial lit les tiers, pas les achats.
        $this->withToken($this->jetonRole('commercial'))->getJson("/api/v1/tiers/{$fournisseur->id}/releve")
            ->assertForbidden();
        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client->id}/releve?compte=fournisseur")
            ->assertUnprocessable()->assertJsonValidationErrors('compte');
    }

    public function test_le_caissier_lit_ce_que_doit_le_client(): void
    {
        $client = $this->tiers('Comptoir');
        $this->piece($client, DocumentVente::TYPE_FACTURE, 100, '2026-02-01');

        // Ce que doit le client se lit avec les tiers, comme sur la fiche.
        $releve = $this->releve($client, '', $this->jetonRole('caissier'));
        $this->assertSame('120.00', $releve['solde_final']);
    }

    /**
     * Le solde d'ouverture d'un FOURNISSEUR dit ce qu'on lui doit : il ne
     * sort ni par la vue d'ensemble ni par le relevé client vers un rôle à
     * qui le relevé fournisseur est refusé. Celui d'un client, si.
     */
    public function test_l_ouverture_fournisseur_ne_sort_pas_vers_qui_n_a_ni_achats_ni_compta(): void
    {
        $fournisseur = $this->tiers('2AFC PC', ['is_client' => false, 'is_supplier' => true]);
        $this->withToken($this->jeton)->postJson("/api/v1/tiers/{$fournisseur->id}/solde-ouverture", [
            'montant' => 125000, 'sens' => 'credit', 'date' => '2026-01-01',
        ])->assertCreated();
        $this->dansEntreprise($this->entreprise);

        foreach (['commercial', 'caissier'] as $role) {
            $jeton = $this->jetonRole($role);

            $this->withToken($jeton)->getJson("/api/v1/tiers/{$fournisseur->id}/releve?compte=fournisseur")->assertForbidden();

            $vue = $this->withToken($jeton)->getJson("/api/v1/tiers/{$fournisseur->id}/vue-ensemble")->assertOk()->json('data');
            $this->assertNull($vue['solde_ouverture'], $role);

            $this->assertNull($this->releve($fournisseur, 'compte=client', $jeton)['solde_ouverture'], $role);
        }

        // Le comptable lit les achats (et le grand livre) : il la voit.
        $comptable = $this->jetonRole('comptable');
        $this->assertSame('125000.00', $this->releve($fournisseur, 'compte=fournisseur', $comptable)['solde_ouverture']['montant']);

        // L'ouverture d'un CLIENT reste lisible avec les tiers, caissier compris.
        $client = $this->tiers('Comptoir');
        $this->withToken($this->jeton)->postJson("/api/v1/tiers/{$client->id}/solde-ouverture", [
            'montant' => 300, 'sens' => 'debit', 'date' => '2026-01-01',
        ])->assertCreated();
        $this->dansEntreprise($this->entreprise);
        $this->assertSame('300.00', $this->releve($client, '', $this->jetonRole('caissier'))['solde_ouverture']['montant']);
    }

    /* --------------------- lettrage : un seul tiers --------------------- */

    /** Une ligne au compte clients, contre la banque, au journal OD. */
    private function ligneClients(?Tiers $tiers, float $debit, float $credit, string $date, string $journal = Ecriture::JOURNAL_DIVERS, ?string $reference = null): int
    {
        $compta = app(ComptaService::class);
        $ecriture = $compta->ecrire($journal, $date, 'Mouvement', [
            ['compte' => $compta->compteParDefaut('clients'), 'debit' => $debit, 'credit' => $credit, 'tiers_id' => $tiers?->id],
            ['compte' => $compta->compteParCode('5141'), 'debit' => $credit, 'credit' => $debit],
        ], $reference);

        return $ecriture->lignes->firstWhere('compte.code', '3421')->id;
    }

    /**
     * Équilibré PAR COMPTE ne suffit pas : un groupe qui mêle deux tiers, ou
     * un tiers et une ligne sans tiers, soldait le client dans la liste quand
     * son relevé disait autre chose. Refusé — et le relevé reste égal à
     * l'encours.
     */
    public function test_le_lettrage_refuse_deux_tiers_ou_une_ligne_sans_tiers_et_le_releve_reste_l_encours(): void
    {
        $lettrage = app(LettrageService::class);
        $d = $this->tiers('D');
        $e = $this->tiers('E');
        $f = $this->tiers('F');

        // (a) Une ouverture globale au journal AN, sans tiers, et le règlement de D.
        $an = $this->ligneClients(null, 5000, 0, '2026-01-01', Ecriture::JOURNAL_A_NOUVEAUX);
        $reglementD = $this->ligneClients($d, 0, 5000, '2026-02-01');

        try {
            $lettrage->lettrer([$an, $reglementD]);
            $this->fail('Une ligne sans tiers lettrée avec celle de D.');
        } catch (ValidationException $refus) {
            $this->assertStringContainsString('sans tiers', $refus->errors()['lignes'][0]);
        }

        // (b) La facture de E et le règlement reçu de F.
        $factureE = $this->ligneClients($e, 1200, 0, '2026-03-01');
        $reglementF = $this->ligneClients($f, 0, 1200, '2026-03-02');

        try {
            $lettrage->lettrer([$factureE, $reglementF]);
            $this->fail('Deux tiers lettrés ensemble.');
        } catch (ValidationException $refus) {
            $this->assertSame('Toutes les lignes doivent concerner le même tiers.', $refus->errors()['lignes'][0]);
        }

        $this->assertSame(0, EcritureLigne::whereNotNull('lettrage')->count());

        foreach ([[$d, '-5000.00'], [$e, '1200.00'], [$f, '-1200.00']] as [$tiers, $attendu]) {
            $releve = $this->releve($tiers);
            $this->assertSame($attendu, $releve['solde_final'], $tiers->name);
            $this->assertSame($this->encours($tiers), $releve['solde_final'], $tiers->name);
        }

        // Le lettrage d'UN tiers passe toujours.
        $factureD = $this->ligneClients($d, 5000, 0, '2026-02-15');
        $this->assertSame('AAA', $lettrage->lettrer([$reglementD, $factureD])['code']);
        $this->assertSame($this->encours($d), $this->releve($d)['solde_final']);
    }

    public function test_le_lettrage_automatique_ne_reunit_pas_deux_tiers_sous_une_meme_reference(): void
    {
        $e = $this->tiers('E');
        $f = $this->tiers('F');
        // Une référence saisie à la main, partagée par deux tiers : le groupe
        // s'équilibre sur le compte, pas chez l'un ni chez l'autre.
        $this->ligneClients($e, 700, 0, '2026-03-01', reference: 'REPRISE');
        $this->ligneClients($f, 0, 700, '2026-03-02', reference: 'REPRISE');

        $clients = app(ComptaService::class)->compteParDefaut('clients');
        $this->assertSame(['groupes' => 0, 'lignes' => 0], app(LettrageService::class)->lettrageAuto($clients->id));

        $this->assertSame($this->encours($e), $this->releve($e)['solde_final']);
        $this->assertSame('700.00', $this->releve($e)['solde_final']);
    }

    /** Les groupes posés AVANT la règle : recensés, en lecture seule. */
    public function test_le_diagnostic_recense_les_groupes_lettres_qui_melent_des_tiers(): void
    {
        $d = $this->tiers('D');
        $an = $this->ligneClients(null, 5000, 0, '2026-01-01', Ecriture::JOURNAL_A_NOUVEAUX);
        $reglementD = $this->ligneClients($d, 0, 5000, '2026-02-01');
        // Posé directement, comme l'ancien lettrage le permettait.
        EcritureLigne::whereIn('id', [$an, $reglementD])->update(['lettrage' => 'AAA']);

        $this->artisan('compta:verifier-lettrage', ['email' => 'admin@mediadesk.ma'])
            ->expectsOutputToContain('Compte 3421, lettrage AAA (2 lignes)')
            ->expectsOutputToContain('(sans tiers)')
            ->expectsOutputToContain('écart 5 000,00 créditeur')
            ->expectsOutputToContain('1 groupe(s) mêlent plusieurs tiers.')
            ->assertSuccessful();

        // Rien n'a bougé.
        $this->assertSame(2, EcritureLigne::where('lettrage', 'AAA')->count());

        EcritureLigne::whereIn('id', [$an, $reglementD])->update(['lettrage' => null]);
        $this->artisan('compta:verifier-lettrage', ['email' => 'admin@mediadesk.ma'])
            ->expectsOutputToContain('Aucun groupe lettré ne mêle plusieurs tiers')
            ->assertSuccessful();
    }

    /* ---------------------------- étanchéité ---------------------------- */

    public function test_une_autre_entreprise_ne_voit_rien_et_ne_pollue_rien(): void
    {
        $client = $this->tiers('WYDAD');
        $this->piece($client, DocumentVente::TYPE_FACTURE, 100, '2026-02-01');

        $jetonB = $this->inscrire('Concurrent', 'admin@concurrent.ma');
        $this->withToken($jetonB)->getJson("/api/v1/tiers/{$client->id}/releve")->assertNotFound();

        // Une ligne d'écriture du concurrent qui porterait NOTRE tiers — la
        // table des lignes n'a pas d'entreprise : seul le filtre par
        // l'écriture l'écarte.
        $entrepriseB = User::withoutGlobalScopes()->firstWhere('email', 'admin@concurrent.ma')->tenant;
        $this->dansEntreprise($entrepriseB);
        $compta = app(ComptaService::class);
        $compta->ecrire(Ecriture::JOURNAL_DIVERS, '2026-03-01', 'Intrusion', [
            ['compte' => $compta->compteParDefaut('clients'), 'debit' => 9999, 'credit' => 0, 'tiers_id' => $client->id],
            ['compte' => $compta->compteParCode('5141'), 'debit' => 0, 'credit' => 9999],
        ]);
        $this->dansEntreprise($this->entreprise);

        $releve = $this->releve($client);
        $this->assertSame(1, $releve['nb_lignes']);
        $this->assertSame('120.00', $releve['solde_final']);
    }

    /* -------------------------------- PDF -------------------------------- */

    public function test_le_pdf_se_genere_pour_un_client_et_un_fournisseur(): void
    {
        $client = $this->tiers('WYDAD', ['address' => '12 rue de Fès', 'city' => 'Rabat', 'ice' => '001234567000089']);
        $facture = $this->piece($client, DocumentVente::TYPE_FACTURE, 1000, '2026-02-01');
        $this->payer($facture, 300, '2026-03-01');

        $pdf = $this->withToken($this->jeton)->get("/api/v1/tiers/{$client->id}/releve?format=pdf&du=2026-01-01&au=2026-10-15")
            ->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString("releve-{$client->code}-2026-01-01-2026-10-15.pdf", (string) $pdf->headers->get('content-disposition'));

        $this->dansEntreprise($this->entreprise);
        $fournisseur = $this->tiers('Cimenterie Atlas', ['is_client' => false, 'is_supplier' => true]);
        $this->factureAchat($fournisseur, 500, '2026-02-01');
        $this->assertStringStartsWith(
            '%PDF',
            $this->withToken($this->jeton)->get("/api/v1/tiers/{$fournisseur->id}/releve?format=pdf")->assertOk()->getContent(),
        );
    }

    public function test_le_pdf_porte_l_en_tete_le_destinataire_les_totaux_et_les_mentions(): void
    {
        $this->entreprise->update(['settings' => array_merge($this->entreprise->settings ?? [], [
            'address' => '5 avenue Hassan II', 'city' => 'Casablanca', 'ice' => '009876543000012', 'rc' => '445566',
        ])]);
        $client = $this->tiers('WYDAD', ['address' => '12 rue de Fès', 'city' => 'Rabat']);
        $this->piece($client, DocumentVente::TYPE_FACTURE, 1000, '2026-02-01');

        $releve = app(ReleveService::class)->pour(
            $client, 'client', Carbon::parse('2026-01-01')->toImmutable(), Carbon::parse('2026-10-15')->toImmutable(),
        );
        $html = view('pdf.releve-client', ['tiers' => $client->load('tenant'), 'releve' => $releve, 'edite' => now()->toImmutable()])->render();

        foreach (['Relevé de compte', 'Media Desk', '5 avenue Hassan II', 'Destinataire', 'WYDAD', '12 rue de Fès',
            'Du 01/01/2026 au 15/10/2026', 'Solde au 31/12/2025 (report)', 'Totaux de la période', 'Solde dû',
            '1 200,00', 'ICE : 009876543000012', 'R.C : 445566'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
        // La règle DomPDF : jamais de remise à zéro globale, qui effacerait
        // les marges de @page.
        $this->assertDoesNotMatchRegularExpression('/(^|\n)\s*\*\s*\{/', $html);
        $this->assertDoesNotMatchRegularExpression('/(^|\n)\s*(html|body)\s*(,\s*(html|body)\s*)?\{[^}]*margin\s*:\s*0/', $html);
        $this->assertStringContainsString('@page { margin: 22px 26px 78px 26px; }', $html);
    }

    public function test_trop_de_lignes_le_releve_donne_les_soldes_et_le_pdf_refuse(): void
    {
        // Plafond abaissé à deux lignes pour l'épreuve.
        $this->app->bind(ReleveService::class, fn ($app) => new class($app->make(EncoursService::class), $app->make(ComptaService::class)) extends ReleveService
        {
            public const LIGNES_MAX = 2;
        });

        $client = $this->tiers('Client comptoir');
        foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $date) {
            $this->piece($client, DocumentVente::TYPE_FACTURE, 100, $date);
        }

        $releve = $this->releve($client);
        $this->assertTrue($releve['trop_de_lignes']);
        $this->assertSame(3, $releve['nb_lignes']);
        $this->assertSame([], $releve['lignes']);
        // Les soldes, eux, restent justes.
        $this->assertSame('360.00', $releve['solde_final']);

        $this->withToken($this->jeton)->getJson("/api/v1/tiers/{$client->id}/releve?format=pdf")
            ->assertUnprocessable()->assertJsonValidationErrors('du');
    }
}
