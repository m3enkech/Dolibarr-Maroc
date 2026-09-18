<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Services\ProduitService;
use App\Modules\Integrations\Zoho\ImportArticlesZoho;
use App\Modules\Integrations\Zoho\ZohoBooksClient;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reprise du catalogue depuis Zoho Books.
 *
 * Deux décisions se jouent ici et ne se rattrapent pas : le TYPE d'un produit
 * (immuable une fois posé, et c'est lui qui décide s'il y a un stock) et sa
 * RÉFÉRENCE (ce que les équipes cherchent et dictent au téléphone).
 */
class ImportArticlesZohoTest extends TestCase
{
    use RefreshDatabase;

    /** Un article tel que la liste de Zoho le rend. */
    private function article(array $valeurs = []): array
    {
        return array_merge([
            'item_id' => '238926000000280426',
            'name' => 'Toner Konica Minolta TN211',
            'sku' => 'T-TN211',
            'unit' => 'pcs',
            'status' => 'active',
            'description' => '',
            'rate' => 150,
            'purchase_rate' => 100,
            'tax_percentage' => 20,
            'product_type' => 'goods',
            // ⚠️ Faux chez Books pour TOUS les articles de cette organisation :
            // Books n'y suit aucun stock. C'est précisément pourquoi le type se
            // déduit de `product_type` et non de ce drapeau.
            'track_inventory' => false,
        ], $valeurs);
    }

    /** @param  list<array<string, mixed>>  $articles */
    private function zohoRend(array $articles): ImportArticlesZoho
    {
        $doublure = new class($articles) extends ZohoBooksClient
        {
            public function __construct(private array $articles) {}

            public function estConfigure(): bool
            {
                return true;
            }

            public function articles(array $filtres = []): Generator
            {
                yield from $this->articles;
            }
        };

        return new ImportArticlesZoho($doublure, app(ProduitService::class));
    }

    private function dansUneEntreprise(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        app(TenantContext::class)->set(User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant);
    }

    /* ---------------------------------------------------------------- */

    public function test_un_article_devient_un_produit_avec_son_sku_en_reference(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend([$this->article()])->executer();

        $this->assertSame(1, $rapport['crees']);

        $produit = Produit::first();
        $this->assertSame('T-TN211', $produit->code, 'La référence Books doit survivre à la reprise.');
        $this->assertSame('Toner Konica Minolta TN211', $produit->name);
        $this->assertSame('150.00', $produit->sell_price);
        $this->assertSame('100.00', $produit->buy_price);
        $this->assertSame('pcs', $produit->unit);
        $this->assertTrue($produit->is_active);
        $this->assertSame('238926000000280426', $produit->source_id);
    }

    /**
     * LA décision irréversible du lot : `track_inventory` est faux chez Books
     * pour tout le catalogue. S'y fier ferait de chaque article un SERVICE,
     * c'est-à-dire un article sans stock — et le type ne se change plus ensuite.
     */
    public function test_une_marchandise_devient_un_produit_meme_si_books_n_en_suit_pas_le_stock(): void
    {
        $this->dansUneEntreprise();

        $this->zohoRend([
            $this->article(['product_type' => 'goods', 'track_inventory' => false]),
            $this->article(['item_id' => '2', 'sku' => 'INSTALL', 'name' => 'Installation sur site', 'product_type' => 'service']),
        ])->executer();

        $this->assertSame(Produit::TYPE_PRODUCT, Produit::firstWhere('code', 'T-TN211')->type);
        $this->assertSame(Produit::TYPE_SERVICE, Produit::firstWhere('code', 'INSTALL')->type);
    }

    public function test_un_sku_deja_pris_laisse_la_place_a_la_sequence(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend([
            $this->article(),
            $this->article(['item_id' => '2', 'name' => 'Toner compatible TN211']),
        ])->executer();

        $this->assertSame(2, $rapport['crees']);

        $second = Produit::firstWhere('name', 'Toner compatible TN211');
        $this->assertNotSame('T-TN211', $second->code);
        $this->assertStringContainsString('SKU déjà utilisé', $rapport['details'][1]['raison']);
    }

    public function test_un_article_sans_sku_recoit_une_reference_de_la_sequence(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend([$this->article(['sku' => ''])])->executer();

        $this->assertMatchesRegularExpression('/^PR-/', Produit::first()->code);
        $this->assertStringContainsString('pas de SKU', $rapport['details'][0]['raison']);
    }

    /**
     * Un taux hors barème marocain passerait l'import mais rendrait la fiche
     * impossible à enregistrer ensuite : la validation n'accepte que
     * 0, 7, 10, 14 et 20.
     */
    public function test_un_taux_de_tva_hors_bareme_est_ramene_a_vingt_et_signale(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend([$this->article(['tax_percentage' => 5.5])])->executer();

        $this->assertSame('20.00', Produit::first()->tva_rate);
        $this->assertStringContainsString('hors barème', $rapport['details'][0]['raison']);
    }

    public function test_un_taux_de_tva_legal_est_repris_tel_quel(): void
    {
        $this->dansUneEntreprise();

        $this->zohoRend([$this->article(['tax_percentage' => 7])])->executer();

        $this->assertSame('7.00', Produit::first()->tva_rate);
    }

    public function test_l_import_ne_remplace_jamais_une_donnee_deja_saisie(): void
    {
        $this->dansUneEntreprise();

        app(ProduitService::class)->create([
            'name' => 'Toner Konica Minolta TN211',
            'type' => Produit::TYPE_PRODUCT,
            'sell_price' => 175,
            'unit' => 'unité',
        ]);

        $this->zohoRend([$this->article()])->executer();

        $produit = Produit::first();
        $this->assertSame('175.00', $produit->sell_price, 'Le prix corrigé à la main doit survivre.');
        $this->assertSame('unité', $produit->unit);
        $this->assertSame('100.00', $produit->buy_price, 'Un champ vide, lui, se complète.');
        $this->assertSame('238926000000280426', $produit->source_id);
    }

    public function test_un_import_rejoue_ne_cree_rien_de_plus(): void
    {
        $this->dansUneEntreprise();

        $articles = [
            $this->article(),
            $this->article(['item_id' => '2', 'sku' => 'RLX-91450', 'name' => 'Papier traceur']),
        ];

        $this->zohoRend($articles)->executer();
        $this->assertSame(2, Produit::count());

        $second = $this->zohoRend($articles)->executer();

        $this->assertSame(0, $second['crees']);
        $this->assertSame(2, Produit::count());
    }

    public function test_un_article_deja_importe_est_retrouve_par_son_identifiant_zoho(): void
    {
        $this->dansUneEntreprise();

        $this->zohoRend([$this->article()])->executer();

        // L'article vit sa vie dans Dolibarr : renommé.
        Produit::first()->update(['name' => 'Toner TN211 (compatible)']);

        $rapport = $this->zohoRend([$this->article()])->executer();

        $this->assertSame(0, $rapport['crees']);
        $this->assertSame(1, Produit::count());
    }

    public function test_la_simulation_n_ecrit_rien_et_annonce_le_meme_resultat(): void
    {
        $this->dansUneEntreprise();

        $articles = [
            $this->article(),
            // Même SKU : le vrai import n'en créera qu'un, la simulation doit le dire.
            $this->article(['item_id' => '2', 'name' => 'Toner bis']),
        ];

        $simule = $this->zohoRend($articles)->executer(simulation: true);
        $this->assertSame(0, Produit::count(), 'La simulation n\'écrit rien.');

        $reel = $this->zohoRend($articles)->executer();

        $this->assertSame($reel['crees'], $simule['crees']);
        $this->assertSame($reel['inchanges'], $simule['inchanges']);
        $this->assertSame(2, $simule['crees'], 'Les deux entrent : le second sous une référence de séquence.');
    }

    public function test_un_article_sans_nom_est_ecarte_avec_sa_raison(): void
    {
        $this->dansUneEntreprise();

        $rapport = $this->zohoRend([$this->article(['name' => ''])])->executer();

        $this->assertSame(0, $rapport['crees']);
        $this->assertSame(0, Produit::count());
        $this->assertSame('article sans nom', $rapport['details'][0]['raison']);
    }

    public function test_un_article_inactif_chez_books_arrive_inactif(): void
    {
        $this->dansUneEntreprise();

        $this->zohoRend([$this->article(['status' => 'inactive'])])->executer();

        $this->assertFalse(Produit::first()->is_active);
    }
}
