<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Integrations\Zoho\ImportTiersZoho;
use App\Modules\Integrations\Zoho\ZohoBooksClient;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Import des clients et fournisseurs depuis Zoho Books.
 *
 * Tout se joue sur le rapprochement : un import qui duplique une base de
 * quatre cents tiers coûte des heures à nettoyer, et un import qui écrase des
 * corrections faites à la main détruit du travail. Ces deux cas sont couverts
 * ici avant de toucher aux vraies données.
 *
 * Zoho n'est jamais appelé : le client est remplacé par une doublure qui rend
 * des contacts choisis.
 */
class ImportTiersZohoTest extends TestCase
{
    use RefreshDatabase;

    /** Un contact Books, réduit aux champs dont l'import se sert. */
    private function contact(array $valeurs = []): array
    {
        return array_merge([
            'contact_id' => '238926000000000001',
            'contact_name' => 'ACME SARL',
            'company_name' => 'ACME SARL',
            'cf_ice' => '001234567000089',
            'email' => '',
            'phone' => '',
            'mobile' => '',
            'website' => '',
            'first_name' => '',
            'last_name' => '',
            'status' => 'active',
            'billing_address' => ['address' => '', 'city' => '', 'zip' => '', 'country' => ''],
        ], $valeurs);
    }

    /**
     * Remplace le client Zoho par une doublure.
     *
     * @param  array<string, list<array<string, mixed>>>  $parType
     */
    private function zohoRend(array $parType): ImportTiersZoho
    {
        $doublure = new class($parType) extends ZohoBooksClient
        {
            public function __construct(private array $parType) {}

            public function estConfigure(): bool
            {
                return true;
            }

            public function contacts(string $type): Generator
            {
                yield from $this->parType[$type] ?? [];
            }
        };

        return new ImportTiersZoho($doublure, app(TiersService::class));
    }

    /** Crée une entreprise et s'y place, comme le ferait la commande. */
    private function dansUneEntreprise(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        app(TenantContext::class)->set(User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant);
    }

    /* ---------------------------------------------------------------- */

    public function test_un_contact_devient_un_tiers_avec_son_ice(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend(['customer' => [$this->contact([
            'email' => 'contact@acme.ma', 'phone' => '0522000000',
        ])]])->executer();

        $this->assertSame(1, $rapport['crees']);

        $tiers = Tiers::firstWhere('name', 'ACME SARL');
        $this->assertNotNull($tiers);
        $this->assertSame('001234567000089', $tiers->ice);
        $this->assertSame('contact@acme.ma', $tiers->email);
        $this->assertTrue($tiers->is_client);
        $this->assertFalse($tiers->is_supplier);
        $this->assertStringContainsString('Zoho Books', $tiers->notes);
    }

    /**
     * LA règle du lot : Books tient deux fiches pour un même ICE selon le rôle,
     * Dolibarr n'en veut qu'une, portant les deux drapeaux. Sans cela, l'import
     * créerait un doublon pour chaque partenaire qui est à la fois client et
     * fournisseur.
     */
    public function test_le_meme_ice_en_client_et_en_fournisseur_ne_fait_qu_un_seul_tiers(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend([
            'customer' => [$this->contact()],
            'vendor' => [$this->contact(['contact_id' => '238926000000000002'])],
        ])->executer();

        $this->assertSame(1, $rapport['crees'], 'Un seul tiers doit être créé.');
        $this->assertSame(1, $rapport['mis_a_jour'], 'Le second passage lève le drapeau fournisseur.');
        $this->assertSame(1, Tiers::count());

        $tiers = Tiers::first();
        $this->assertTrue($tiers->is_client);
        $this->assertTrue($tiers->is_supplier);
    }

    public function test_un_tiers_deja_present_n_est_pas_duplique(): void
    {
        $this->dansUneEntreprise();

        app(TiersService::class)->create([
            'name' => 'ACME', 'is_client' => true, 'is_supplier' => false,
            'ice' => '001234567000089',
        ]);

        $rapport = $this->zohoRend(['customer' => [$this->contact()]])->executer();

        $this->assertSame(0, $rapport['crees']);
        $this->assertSame(1, Tiers::count());
        // Le nom diffère (« ACME » contre « ACME SARL ») : c'est bien l'ICE qui
        // a servi, et le nom saisi à la main n'a pas été écrasé.
        $this->assertSame('ACME', Tiers::first()->name);
    }

    /**
     * Un tiers saisi dans Dolibarr a pu être corrigé à la main. Books n'a pas
     * autorité dessus : on ne remplit que le vide.
     */
    public function test_l_import_ne_remplace_jamais_une_donnee_deja_saisie(): void
    {
        $this->dansUneEntreprise();

        app(TiersService::class)->create([
            'name' => 'ACME SARL', 'is_client' => true, 'is_supplier' => false,
            'ice' => '001234567000089',
            'email' => 'corrige@acme.ma',
        ]);

        $this->zohoRend(['customer' => [$this->contact([
            'email' => 'ancien@books.ma', 'phone' => '0522111111',
        ])]])->executer();

        $tiers = Tiers::first();
        $this->assertSame('corrige@acme.ma', $tiers->email, "L'email corrigé à la main doit survivre.");
        $this->assertSame('0522111111', $tiers->phone, 'Un champ vide, lui, se complète.');
    }

    public function test_l_ice_se_compare_sur_ses_chiffres(): void
    {
        $this->dansUneEntreprise();

        app(TiersService::class)->create([
            'name' => 'ACME SARL', 'is_client' => true, 'is_supplier' => false,
            'ice' => '001234567000089',
        ]);

        // Même ICE, écrit avec des espaces et des tirets côté Books.
        $this->zohoRend(['customer' => [$this->contact([
            'cf_ice' => '0012 3456-7000 089',
        ])]])->executer();

        $this->assertSame(1, Tiers::count(), 'La mise en forme de l\'ICE ne doit pas créer de doublon.');
    }

    public function test_un_contact_sans_ice_se_rapproche_sur_le_nom(): void
    {
        $this->dansUneEntreprise();

        app(TiersService::class)->create([
            'name' => 'Épicerie Zahra', 'is_client' => true, 'is_supplier' => false,
        ]);

        $rapport = $this->zohoRend(['customer' => [$this->contact([
            'company_name' => 'EPICERIE  ZAHRA', 'cf_ice' => '',
        ])]])->executer();

        $this->assertSame(0, $rapport['crees'], 'Accents, casse et espaces ne doivent pas tromper le rapprochement.');
        $this->assertSame(1, Tiers::count());
    }

    public function test_les_creations_sans_ice_sont_signalees(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend(['customer' => [
            $this->contact(['company_name' => '+212616971090', 'contact_name' => '+212616971090', 'cf_ice' => '']),
        ]])->executer();

        $this->assertSame(1, $rapport['crees']);
        $this->assertSame('nouveau, SANS ICE', $rapport['details'][0]['raison']);
    }

    public function test_un_contact_sans_nom_est_ecarte_avec_sa_raison(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend(['customer' => [
            $this->contact(['company_name' => '', 'contact_name' => '']),
        ]])->executer();

        $this->assertSame(0, $rapport['crees']);
        $this->assertSame(0, Tiers::count());
        $this->assertSame('ecarte', $rapport['details'][0]['action']);
        $this->assertSame('contact sans nom exploitable', $rapport['details'][0]['raison']);
    }

    /**
     * Une simulation qui n'annonce pas le vrai résultat ne sert à rien.
     *
     * Le cas qui l'a révélé : chez Books, 108 partenaires existent en client ET
     * en fournisseur. Comme la simulation n'écrit pas, elle n'alimentait pas son
     * index et ne voyait donc pas ces doublons INTERNES à l'import — elle
     * promettait 566 créations quand le vrai import en fait 458.
     */
    public function test_la_simulation_annonce_les_fusions_comme_le_vrai_import(): void
    {
        $this->dansUneEntreprise();

        $contacts = [
            'customer' => [$this->contact()],
            'vendor' => [$this->contact(['contact_id' => '2'])],
        ];

        $simule = $this->zohoRend($contacts)->executer(simulation: true);
        $this->assertSame(0, Tiers::count(), 'La simulation n\'écrit rien.');

        $reel = $this->zohoRend($contacts)->executer();

        $this->assertSame($reel['crees'], $simule['crees'], 'Le nombre de créations annoncé doit être le vrai.');
        $this->assertSame($reel['mis_a_jour'], $simule['mis_a_jour'], 'Le nombre de fusions aussi.');
        $this->assertSame(1, $simule['crees']);
        $this->assertSame(1, $simule['mis_a_jour']);
    }

    public function test_la_simulation_n_ecrit_rien_mais_rend_le_meme_rapport(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend(['customer' => [
            $this->contact(),
            $this->contact(['contact_id' => '2', 'company_name' => 'BETA SARL', 'cf_ice' => '009999999000011']),
        ]])->executer(simulation: true);

        $this->assertSame(2, $rapport['crees'], 'Le rapport annonce ce qui SERAIT créé.');
        $this->assertSame(0, Tiers::count(), 'Mais rien n\'est écrit.');
    }

    public function test_un_import_rejoue_ne_cree_rien_de_plus(): void
    {
        $this->dansUneEntreprise();

        $contacts = ['customer' => [
            $this->contact(),
            $this->contact(['contact_id' => '2', 'company_name' => 'BETA SARL', 'cf_ice' => '009999999000011']),
        ]];

        $this->zohoRend($contacts)->executer();
        $this->assertSame(2, Tiers::count());

        $second = $this->zohoRend($contacts)->executer();

        $this->assertSame(0, $second['crees'], 'Rejouer l\'import ne doit rien créer.');
        $this->assertSame(2, Tiers::count());
    }
}
