<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Les actions groupées de la liste des tiers (désactiver, réactiver) et
 * l'export CSV.
 *
 * Éprouvé : tout ou rien — un id inconnu de l'entreprise annule l'action
 * entière, sans dire s'il existe ailleurs ; la garde est `tiers` (écriture
 * pour les actions, lecture pour l'export) ; l'export suit les filtres de la
 * liste ou la sélection, au format d'Excel en français, en flux, avec le
 * solde de la liste — et neutralise toute cellule qu'un tableur prendrait
 * pour une formule.
 */
class ActionsGroupeesTiersTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private int $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-08 10:00:00');

        $inscription = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        $this->jeton = $inscription->json('token');
        $this->entreprise = $inscription->json('tenant.id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tiers(string $nom, array $attributs = [], ?string $jeton = null): int
    {
        return $this->withToken($jeton ?? $this->jeton)->postJson('/api/v1/tiers', array_merge([
            'name' => $nom, 'is_client' => true, 'is_supplier' => false,
        ], $attributs))->assertCreated()->json('data.id');
    }

    private function jetonDuRole(string $role): string
    {
        return User::factory()->create([
            'tenant_id' => $this->entreprise, 'role' => $role, 'is_active' => true,
        ])->createToken('spa')->plainTextToken;
    }

    private function agir(array $ids, string $action, ?string $jeton = null): TestResponse
    {
        return $this->withToken($jeton ?? $this->jeton)->postJson('/api/v1/tiers/actions', ['ids' => $ids, 'action' => $action]);
    }

    /** @return array<int, bool> */
    private function etats(array $ids): array
    {
        // Trié : PostgreSQL rend les lignes mises à jour dans un autre ordre.
        return Tiers::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('id')->pluck('is_active', 'id')
            ->map(fn ($actif) => (bool) $actif)->all();
    }

    /** Le CSV produit, décodé en lignes de cellules (BOM vérifié puis retiré). */
    private function csv(array $params = [], ?string $jeton = null): array
    {
        $reponse = $this->withToken($jeton ?? $this->jeton)->get('/api/v1/tiers/export?'.http_build_query($params));
        $reponse->assertOk();

        $contenu = $reponse->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $contenu);

        $lignes = preg_split("/\r\n/", rtrim(substr($contenu, 3), "\r\n"));

        return array_map(fn (string $ligne) => str_getcsv($ligne, ';', '"', ''), $lignes);
    }

    /** @return list<string> la colonne « Nom » des lignes de données */
    private function noms(array $lignes): array
    {
        return array_column(array_slice($lignes, 1), 1);
    }

    /* ----------------------------- actions ----------------------------- */

    public function test_desactiver_puis_reactiver_une_selection(): void
    {
        $ids = [$this->tiers('A'), $this->tiers('B'), $this->tiers('C')];

        $this->agir($ids, 'desactiver')->assertOk()
            ->assertJsonPath('data.action', 'desactiver')
            ->assertJsonPath('data.modifies', 3)
            ->assertJsonPath('data.inchanges', 0);
        $this->assertSame(array_fill_keys($ids, false), $this->etats($ids));

        $this->agir([$ids[0], $ids[1]], 'reactiver')->assertOk()->assertJsonPath('data.modifies', 2);
        $this->assertSame([$ids[0] => true, $ids[1] => true, $ids[2] => false], $this->etats($ids));
    }

    /** Un tiers déjà dans l'état demandé n'est pas une erreur : il est compté à part. */
    public function test_les_tiers_deja_dans_l_etat_sont_comptes_a_part(): void
    {
        $actif = $this->tiers('ACTIF');
        $inactif = $this->tiers('INACTIF', ['is_active' => false]);

        $this->agir([$actif, $inactif], 'desactiver')->assertOk()
            ->assertJsonPath('data.modifies', 1)
            ->assertJsonPath('data.inchanges', 1);
    }

    /**
     * TOUT OU RIEN : un id d'une autre entreprise, inexistant ou supprimé fait
     * échouer l'action entière, avec le MÊME message — la réponse ne dit pas
     * ce qui existe ailleurs.
     */
    public function test_un_id_hors_de_l_entreprise_annule_toute_l_action(): void
    {
        $nos = [$this->tiers('NOTRE A'), $this->tiers('NOTRE B')];

        $autreJeton = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Concurrent', 'name' => 'Autre', 'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $etranger = $this->tiers('LEUR CLIENT', [], $autreJeton);

        $supprime = $this->tiers('SUPPRIMÉ');
        $this->withToken($this->jeton)->deleteJson("/api/v1/tiers/{$supprime}")->assertOk();

        $messages = [];
        foreach ([$etranger, 999999, $supprime] as $intrus) {
            $messages[] = $this->agir([...$nos, $intrus], 'desactiver')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('ids')
                ->json('errors.ids.0');
        }

        $this->assertCount(1, array_unique($messages));
        $this->assertStringContainsString("Rien n'a été modifié", $messages[0]);

        // Rien n'a bougé, ni chez nous ni chez l'autre.
        $this->assertSame(array_fill_keys($nos, true), $this->etats($nos));
        $this->assertSame([$etranger => true], $this->etats([$etranger]));
    }

    public function test_les_demandes_mal_formees_sont_refusees(): void
    {
        $id = $this->tiers('A');

        $this->agir([], 'desactiver')->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->agir([$id], 'supprimer')->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->agir([$id, $id], 'desactiver')->assertUnprocessable()->assertJsonValidationErrors('ids.1');
        $this->agir(['abc'], 'desactiver')->assertUnprocessable()->assertJsonValidationErrors('ids.0');
        // Une page de la liste au plus.
        $this->agir(range(1, 501), 'desactiver')->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    /** Écrire sur les tiers : les rôles qui ne font que les lire sont refusés, le commercial passe. */
    public function test_les_actions_exigent_le_droit_d_ecrire_sur_les_tiers(): void
    {
        $id = $this->tiers('A');

        foreach (['lecture', 'comptable', 'caissier'] as $role) {
            $this->agir([$id], 'desactiver', $this->jetonDuRole($role))->assertForbidden();
        }
        $this->assertSame([$id => true], $this->etats([$id]));

        $this->agir([$id], 'desactiver', $this->jetonDuRole('commercial'))->assertOk();
        $this->assertSame([$id => false], $this->etats([$id]));
    }

    /* ------------------------------ export ------------------------------ */

    public function test_l_export_est_un_csv_pour_excel_en_francais_ecrit_en_flux(): void
    {
        $this->tiers('ZAHRA', ['ice' => '001234567000089', 'if_number' => '1234567', 'rc' => 'RC 998',
            'phone' => '0522000000', 'email' => 'contact@zahra.ma', 'city' => 'Casablanca']);
        $this->tiers('ATLAS EPICERIE', ['is_client' => false, 'is_supplier' => true, 'forme' => 'particulier', 'is_active' => false]);

        $reponse = $this->withToken($this->jeton)->get('/api/v1/tiers/export');
        $reponse->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $reponse->baseResponse);
        $this->assertSame('text/csv; charset=UTF-8', $reponse->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=tiers-2026-10-08.csv', $reponse->headers->get('Content-Disposition'));

        $lignes = $this->csv();
        $this->assertSame(
            ['Code', 'Nom', 'Type', 'Forme', 'ICE', 'IF', 'RC', 'Téléphone', 'E-mail', 'Ville', 'Actif', 'Solde (MAD)'],
            $lignes[0],
        );
        // L'ordre de la liste : par nom.
        $this->assertSame(['ATLAS EPICERIE', 'ZAHRA'], $this->noms($lignes));

        [, $atlas, $zahra] = $lignes;
        $this->assertSame(['Fournisseur', 'Particulier', 'Non', ''], [$atlas[2], $atlas[3], $atlas[10], $atlas[11]]);
        $this->assertSame(
            ['Client', 'Entreprise', '001234567000089', '1234567', 'RC 998', '0522000000', 'contact@zahra.ma', 'Casablanca', 'Oui', '0,00'],
            array_slice($zahra, 2),
        );
    }

    public function test_l_export_suit_les_filtres_de_la_liste(): void
    {
        $this->tiers('CLIENT ACTIF');
        $this->tiers('CLIENT INACTIF', ['is_active' => false]);
        $this->tiers('FOURNISSEUR', ['is_client' => false, 'is_supplier' => true]);

        $this->assertSame(['FOURNISSEUR'], $this->noms($this->csv(['type' => 'fournisseur'])));
        $this->assertSame(['CLIENT INACTIF'], $this->noms($this->csv(['actif' => '0'])));
        // Recherche insensible à la casse, comme la liste (whereLike).
        $this->assertSame(['CLIENT ACTIF', 'CLIENT INACTIF'], $this->noms($this->csv(['search' => 'client'])));
    }

    /**
     * Avec `ids[]`, la sélection seule — filtres ignorés —, et un id d'une
     * autre entreprise disparaît sous le scope sans faire échouer l'export.
     */
    public function test_l_export_de_la_selection_ignore_les_filtres_et_les_ids_etrangers(): void
    {
        $a = $this->tiers('A CLIENT');
        $this->tiers('B CLIENT');
        $f = $this->tiers('C FOURNISSEUR', ['is_client' => false, 'is_supplier' => true]);

        $autreJeton = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Concurrent', 'name' => 'Autre', 'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $etranger = $this->tiers('LEUR SECRET', [], $autreJeton);

        $lignes = $this->csv(['ids' => [$f, $a, $etranger], 'type' => 'client']);
        $this->assertSame(['A CLIENT', 'C FOURNISSEUR'], $this->noms($lignes));

        // Une page de la liste au plus, comme les actions.
        $this->withToken($this->jeton)->getJson('/api/v1/tiers/export?'.http_build_query(['ids' => range(1, 501)]))
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    /** L'export d'une entreprise ne contient rien d'une autre. */
    public function test_l_export_reste_dans_l_entreprise(): void
    {
        $this->tiers('NOTRE CLIENT');

        $autreJeton = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Concurrent', 'name' => 'Autre', 'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
        $this->tiers('LEUR CLIENT', [], $autreJeton);

        $this->assertSame(['NOTRE CLIENT'], $this->noms($this->csv()));
        $this->assertSame(['LEUR CLIENT'], $this->noms($this->csv([], $autreJeton)));
    }

    /**
     * Toute cellule qu'un tableur prendrait pour une formule est neutralisée
     * par une apostrophe : « = », « + », « - », « @ », tabulation, retour
     * chariot, et des blancs suivis de l'un d'eux. Créés hors API : le
     * middleware de l'API rognerait les blancs et tabulations de tête.
     */
    public function test_l_export_neutralise_l_injection_de_formules(): void
    {
        $tiers = [
            '=HYPERLINK("http://x.test";"Cliquez")' => 'egal',
            '+33 SARL' => 'plus',
            '-2+3' => 'moins',
            '@SUM(A1:A9)' => 'arobase',
            "\t=1+1" => 'tabulation',
            "\r=1+1" => 'retour',
            '   =1+1' => 'blancs',
            'SARL NORMALE' => 'normal',
            'Prix = 3' => 'egal-au-milieu',
        ];

        app(TenantContext::class)->set(Tenant::find($this->entreprise));
        foreach ($tiers as $nom => $code) {
            Tiers::create(['code' => $code, 'name' => $nom, 'is_client' => true, 'phone' => '+212522000000']);
        }

        $parCode = [];
        foreach (array_slice($this->csv(), 1) as $ligne) {
            $parCode[$ligne[0]] = $ligne;
        }

        foreach (['egal', 'plus', 'moins', 'arobase', 'tabulation', 'retour', 'blancs'] as $code) {
            $nom = array_search($code, $tiers, true);
            $this->assertSame("'".$nom, $parCode[$code][1], "Cellule « {$code} » non neutralisée.");
        }
        $this->assertSame('SARL NORMALE', $parCode['normal'][1]);
        $this->assertSame('Prix = 3', $parCode['egal-au-milieu'][1]);
        // Le téléphone international aussi : sans l'apostrophe, Excel le
        // calculerait et perdrait le « + ».
        $this->assertSame("'+212522000000", $parCode['normal'][7]);
    }

    /**
     * Le solde est celui de la liste, signé et écrit à la française — un
     * solde négatif n'est PAS neutralisé : il sort d'un calcul, et
     * l'apostrophe en ferait du texte qu'aucune somme ne compterait.
     */
    public function test_l_export_porte_le_solde_de_la_liste(): void
    {
        $debiteur = $this->tiers('DEBITEUR');
        $crediteur = $this->tiers('CREDITEUR');
        $this->tiers('FOURNISSEUR PUR', ['is_client' => false, 'is_supplier' => true]);

        $this->withToken($this->jeton)->postJson("/api/v1/tiers/{$debiteur}/solde-ouverture", [
            'montant' => 1250.5, 'sens' => 'debit', 'date' => '2026-01-01',
        ])->assertCreated();
        $this->withToken($this->jeton)->postJson("/api/v1/tiers/{$crediteur}/solde-ouverture", [
            'montant' => 300, 'sens' => 'credit', 'date' => '2026-01-01',
        ])->assertCreated();

        $soldes = array_column(array_slice($this->csv(), 1), 11, 1);
        $this->assertSame(['CREDITEUR' => '-300,00', 'DEBITEUR' => '1250,50', 'FOURNISSEUR PUR' => ''], $soldes);

        // Le même chiffre que la liste.
        $liste = $this->withToken($this->jeton)->getJson('/api/v1/tiers?avec_solde=1')->assertOk()->json('data');
        $this->assertSame(['-300.00', '1250.50', null], array_column($liste, 'solde'));
    }

    /** Lire les tiers suffit : le caissier et le rôle lecture exportent ce qu'ils voient déjà. */
    public function test_l_export_se_lit_avec_les_tiers(): void
    {
        $this->tiers('A');

        foreach (['lecture', 'caissier'] as $role) {
            $this->assertSame(['A'], $this->noms($this->csv([], $this->jetonDuRole($role))), $role);
        }

        $this->flushHeaders()->getJson('/api/v1/tiers/export')->assertUnauthorized();
    }
}
