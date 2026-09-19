<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Services\ProduitService;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\Exercice;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Integrations\Zoho\ImportFacturesZoho;
use App\Modules\Integrations\Zoho\ZohoBooksClient;
use App\Modules\Stock\Models\MouvementStock;
use App\Modules\Stock\Services\StockService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reprise des factures de vente depuis Zoho Books.
 *
 * C'est le lot qui porte le chiffre d'affaires : quatre ans d'historique et une
 * comptabilité qui doit tomber juste. Deux familles de risques sont couvertes
 * ici — les MONTANTS (une remise mal lue, un port oublié, et le CA est faux) et
 * les EFFETS DE BORD (une reprise qui vide le stock ou qui rejoue une facture
 * déjà là).
 */
class ImportFacturesZohoTest extends TestCase
{
    use RefreshDatabase;

    /* ----------------------------- décor ---------------------------- */

    private function dansUneEntreprise(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        app(TenantContext::class)->set(User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant);
    }

    /** Le client et l'article déjà repris par les deux lots précédents. */
    private function clientEtArticle(): void
    {
        app(TiersService::class)->create([
            'name' => 'AIDA MAROC', 'is_client' => true, 'is_supplier' => false,
            'ice' => '000061689000081',
            'source_systeme' => 'zoho_books', 'source_id' => 'client-1',
        ]);

        app(ProduitService::class)->create([
            'name' => 'Papier traceur 0.914x50',
            'type' => Produit::TYPE_PRODUCT,
            'sell_price' => 192,
            'source_systeme' => 'zoho_books', 'source_id' => 'article-1',
        ]);
    }

    /** Le résumé que rend la LISTE des factures (sans les lignes). */
    private function resume(array $valeurs = []): array
    {
        return array_merge([
            'invoice_id' => 'facture-1',
            'invoice_number' => 'MDK24-00110',
            'date' => '2024-05-29',
            'status' => 'paid',
            'customer_name' => 'AIDA MAROC',
            'total' => 921.6,
        ], $valeurs);
    }

    /** Le DÉTAIL que rend `get_invoice`, avec ses lignes. */
    private function detail(array $valeurs = [], ?array $lignes = null): array
    {
        return array_merge([
            'invoice_id' => 'facture-1',
            'invoice_number' => 'MDK24-00110',
            'date' => '2024-05-29',
            'due_date' => '2024-06-28',
            'status' => 'paid',
            'customer_id' => 'client-1',
            'customer_name' => 'AIDA MAROC',
            'cf_ice' => '000061689000081',
            'salesorder_number' => 'CO24-00124',
            'sub_total' => 768,
            'tax_total' => 153.6,
            'total' => 921.6,
            'payment_made' => 921.6,
            'last_payment_date' => '2024-05-31',
            'shipping_charge' => 0,
            'adjustment' => 0,
            'roundoff_value' => 0,
            'line_items' => $lignes ?? [$this->ligne()],
        ], $valeurs);
    }

    private function ligne(array $valeurs = []): array
    {
        return array_merge([
            'item_id' => 'article-1',
            'sku' => 'RLX-TRC91450',
            'name' => 'Papier traceur 0.914x50',
            'quantity' => 4,
            'rate' => 192,
            'item_total' => 768,
            'tax_percentage' => 20,
        ], $valeurs);
    }

    /**
     * Remplace le client Zoho par une doublure.
     *
     * @param  list<array<string, mixed>>  $resumes
     * @param  array<string, array<string, mixed>>  $details  indexés par invoice_id
     */
    private function zohoRend(array $resumes, array $details): ImportFacturesZoho
    {
        $doublure = new class($resumes, $details) extends ZohoBooksClient
        {
            public function __construct(private array $resumes, private array $details) {}

            public function estConfigure(): bool
            {
                return true;
            }

            public function factures(array $filtres = []): Generator
            {
                yield from $this->resumes;
            }

            public function facture(string $id): array
            {
                return $this->details[$id] ?? [];
            }
        };

        return new ImportFacturesZoho(
            $doublure,
            app(VenteService::class),
            app(TiersService::class),
            app(ComptaService::class),
            app(StockService::class),
        );
    }

    /** Le cas nominal, tel qu'il se présente chez Media Desk. */
    private function importSimple(array $options = []): array
    {
        return $this->zohoRend(
            [$this->resume()],
            ['facture-1' => $this->detail()],
        )->executer($options);
    }

    /* ---------------------------- montants --------------------------- */

    public function test_une_facture_arrive_avec_son_numero_sa_date_et_ses_montants(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $rapport = $this->importSimple();

        $this->assertSame(1, $rapport['importees']);

        $facture = DocumentVente::first();
        $this->assertSame(DocumentVente::TYPE_FACTURE, $facture->type);
        $this->assertSame('MDK24-00110', $facture->code, 'Le numéro de Books est celui du papier détenu par le client.');
        $this->assertSame('2024-05-29', $facture->date_document->toDateString());
        $this->assertSame('2024-06-28', $facture->date_echeance->toDateString());
        $this->assertSame('768.00', $facture->total_ht);
        $this->assertSame('153.60', $facture->total_tva);
        $this->assertSame('921.60', $facture->total_ttc);
        $this->assertSame('facture-1', $facture->source_id);
        $this->assertStringContainsString('CO24-00124', $facture->notes);

        $ligne = $facture->lignes->first();
        $this->assertSame(Produit::first()->id, $ligne->produit_id, 'La ligne doit retrouver son article.');
        $this->assertSame('4.000', $ligne->quantite);
        $this->assertSame('192.00', $ligne->prix_unitaire);
    }

    public function test_la_facture_reprise_porte_son_ecriture_comptable(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->importSimple(['avec_paiements' => false]);

        $vente = Ecriture::where('journal', Ecriture::JOURNAL_VENTES)->first();
        $this->assertNotNull($vente, 'Une reprise sans comptabilité ne vaudrait rien.');
        $this->assertSame('MDK24-00110', $vente->reference);
        $this->assertSame('2024-05-29', $vente->date_ecriture->toDateString());

        // Partie double : le TTC au débit du client, le HT et la TVA au crédit.
        $this->assertEqualsWithDelta(921.60, (float) $vente->lignes->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(921.60, (float) $vente->lignes->sum('credit'), 0.001);
    }

    /**
     * Le champ `discount` de Books vaut tantôt un pourcentage, tantôt une somme,
     * selon un réglage d'organisation. Le rapport entre le brut et `item_total`,
     * lui, est toujours vrai.
     */
    public function test_la_remise_est_deduite_des_montants_et_non_lue_dans_books(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $ligne = $this->ligne(['quantity' => 2, 'rate' => 100, 'item_total' => 180]);

        $rapport = $this->zohoRend(
            [$this->resume(['total' => 216])],
            ['facture-1' => $this->detail(
                ['sub_total' => 180, 'tax_total' => 36, 'total' => 216, 'payment_made' => 0],
                [$ligne],
            )],
        )->executer();

        $this->assertSame(1, $rapport['importees']);

        $ligneEcrite = DocumentVente::first()->lignes->first();
        $this->assertSame('10.00', $ligneEcrite->remise_percent);
        $this->assertSame('180.00', $ligneEcrite->montant_ht);
        $this->assertSame('216.00', DocumentVente::first()->total_ttc);
    }

    public function test_les_frais_de_port_deviennent_une_ligne_pour_que_le_total_tombe_juste(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $rapport = $this->zohoRend(
            [$this->resume(['total' => 838])],
            ['facture-1' => $this->detail([
                'sub_total' => 768, 'tax_total' => 0, 'total' => 838,
                'shipping_charge' => 70, 'shipping_charge_exclusive_of_tax' => 70,
                'shipping_charge_tax_percentage' => '',
                'payment_made' => 838,
            ], [$this->ligne(['tax_percentage' => 0])])],
        )->executer();

        $this->assertSame(1, $rapport['importees'], 'Sans ligne de port, le total ne tomberait pas juste.');

        $facture = DocumentVente::first();
        $this->assertCount(2, $facture->lignes);
        $this->assertSame('Frais de port', $facture->lignes->last()->designation);
        $this->assertSame('838.00', $facture->total_ttc);
    }

    /**
     * Les trois cas réels rencontrés sur les 1 337 factures de Media Desk.
     *
     * Books porte ses prix à cinq décimales — 0,93333 DH l'impression A4 —
     * quand la colonne en stocke deux. L'erreur d'un demi-centime se multiplie
     * par la quantité : sur 1 500 impressions, elle atteint 5 dirhams. Ces trois
     * factures étaient refusées ; elles doivent entrer, et tomber au centime
     * près sur le montant de Books.
     */
    #[DataProvider('arrondisReels')]
    public function test_l_ecart_d_arrondi_du_prix_unitaire_est_absorbe(
        float $quantite,
        float $prix,
        float $itemTotal,
        float $totalBooks,
        float $htAttendu,
        float $tvaAttendue,
    ): void {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $rapport = $this->zohoRend(
            [$this->resume(['total' => $totalBooks])],
            ['facture-1' => $this->detail(
                ['total' => $totalBooks, 'payment_made' => 0],
                [$this->ligne(['quantity' => $quantite, 'rate' => $prix, 'item_total' => $itemTotal])],
            )],
        )->executer();

        $this->assertSame(1, $rapport['importees'], 'La facture doit entrer.');
        $this->assertStringContainsString('écart d\'arrondi', $rapport['details'][0]['raison']);

        $facture = DocumentVente::first();
        $this->assertSame(number_format($htAttendu, 2, '.', ''), $facture->total_ht, 'Le HT doit être celui de Books.');
        $this->assertSame(number_format($tvaAttendue, 2, '.', ''), $facture->total_tva, 'La TVA aussi.');
        $this->assertSame(number_format($totalBooks, 2, '.', ''), $facture->total_ttc, 'Et le TTC au centime près.');

        // L'écriture doit rester équilibrée malgré la correction.
        $vente = Ecriture::where('journal', Ecriture::JOURNAL_VENTES)->first();
        $this->assertEqualsWithDelta(
            (float) $vente->lignes->sum('debit'),
            (float) $vente->lignes->sum('credit'),
            0.001,
        );
    }

    public static function arrondisReels(): array
    {
        return [
            // MDK25-00250 : 1 500 impressions à 0,93333 → 0,93 perd 5,00 HT.
            'impression A4 en volume' => [1500, 0.93333, 1400, 1680, 1400, 280],
            // #MDK22-175 : 20 ramettes à 54,1667.
            'ramettes de papier' => [20, 54.1667, 1083.33, 1300, 1083.33, 216.67],
            // #MDK22-174 : 8 cartouches à 316,6667 — écart NÉGATIF.
            'cartouches HP' => [8, 316.6667, 2533.33, 3040, 2533.33, 506.67],
        ];
    }

    /**
     * L'autre moitié de la règle : ce que l'arrondi ne peut PAS expliquer reste
     * refusé. Un trou qu'on voit vaut mieux qu'un CA faux qu'on ne voit pas.
     */
    public function test_une_facture_dont_le_total_ne_tombe_pas_juste_est_refusee_sans_rien_laisser(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $rapport = $this->zohoRend(
            [$this->resume()],
            // Books annonce 921,60 mais ne donne qu'une ligne à 100 : une ligne
            // manque (article supprimé, forfait non détaillé…).
            ['facture-1' => $this->detail([], [$this->ligne(['quantity' => 1, 'rate' => 100, 'item_total' => 100])])],
        )->executer();

        $this->assertSame(0, $rapport['importees']);
        $this->assertSame(1, $rapport['refusees']);
        $this->assertStringContainsString('total incohérent', $rapport['details'][0]['raison']);

        // La transaction doit avoir TOUT annulé : ni document, ni ligne, ni écriture.
        $this->assertSame(0, DocumentVente::count());
        $this->assertSame(0, Ecriture::where('journal', Ecriture::JOURNAL_VENTES)->count());
    }

    /* ------------------------- effets de bord ------------------------ */

    /**
     * Books ne suivait AUCUN stock. Rejouer quatre ans de ventes sans les achats
     * en regard enfoncerait chaque article à des milliers d'unités négatives :
     * le stock de départ s'établit par un inventaire, pas en rejouant l'histoire.
     */
    public function test_la_reprise_ne_touche_pas_au_stock_par_defaut(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->importSimple();

        $this->assertSame(0, MouvementStock::count());
    }

    public function test_la_reprise_sort_le_stock_quand_on_le_demande(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->importSimple(['avec_stock' => true]);

        $mouvement = MouvementStock::first();
        $this->assertNotNull($mouvement);
        $this->assertSame(MouvementStock::TYPE_VENTE, $mouvement->type);
        $this->assertSame('-4.000', $mouvement->quantite);
    }

    public function test_le_reglement_de_books_solde_la_facture(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->importSimple();

        $facture = DocumentVente::first();
        $this->assertSame(DocumentVente::STATUT_PAYE, $facture->statut);
        $this->assertSame('2024-05-31', $facture->paiements->first()->date_paiement->toDateString());
        $this->assertEqualsWithDelta(921.60, $facture->montantPaye(), 0.001);
    }

    public function test_sans_reglements_la_facture_reste_due(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->importSimple(['avec_paiements' => false]);

        $facture = DocumentVente::first();
        $this->assertSame(DocumentVente::STATUT_VALIDE, $facture->statut);
        $this->assertSame(0, $facture->paiements()->count());
    }

    public function test_une_facture_partiellement_reglee_le_reste(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->zohoRend(
            [$this->resume(['status' => 'partially_paid'])],
            ['facture-1' => $this->detail(['payment_made' => 400])],
        )->executer();

        $facture = DocumentVente::first();
        $this->assertSame(DocumentVente::STATUT_VALIDE, $facture->statut);
        $this->assertEqualsWithDelta(521.60, $facture->resteAPayer(), 0.001);
    }

    /* ---------------------------- tri amont -------------------------- */

    public function test_les_brouillons_et_les_annulees_sont_ignores(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $rapport = $this->zohoRend(
            [
                $this->resume(['invoice_id' => 'f-brouillon', 'status' => 'draft']),
                $this->resume(['invoice_id' => 'f-annulee', 'status' => 'void']),
                $this->resume(),
            ],
            ['facture-1' => $this->detail()],
        )->executer();

        $this->assertSame(2, $rapport['ignorees']);
        $this->assertSame(1, $rapport['importees']);
        $this->assertSame(1, DocumentVente::count());
        $this->assertSame('facture annulée chez Books', $rapport['details'][1]['raison']);
    }

    public function test_un_import_rejoue_ne_reprend_rien_et_n_appelle_meme_pas_zoho(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->importSimple();

        // Le détail est vide : si l'import allait le chercher, il échouerait.
        // C'est la preuve qu'une facture déjà reprise ne coûte pas son appel.
        $second = $this->zohoRend([$this->resume()], [])->executer();

        $this->assertSame(1, $second['deja_presentes']);
        $this->assertSame(0, $second['importees']);
        $this->assertSame(1, DocumentVente::count());
    }

    /* ----------------------------- clients --------------------------- */

    public function test_le_client_est_retrouve_par_son_identifiant_zoho(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->importSimple();

        $this->assertSame(1, Tiers::count(), 'Aucun client ne doit être créé en double.');
        $this->assertSame(Tiers::first()->id, DocumentVente::first()->tiers_id);
    }

    /**
     * Un client absent de la liste des contacts (supprimé, fusionné chez Books)
     * ne doit pas creuser un trou dans le CA : on le crée, et on le signale.
     */
    public function test_un_client_absent_des_contacts_est_cree_depuis_la_facture(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $rapport = $this->zohoRend(
            [$this->resume()],
            ['facture-1' => $this->detail([
                'customer_id' => 'client-inconnu',
                'customer_name' => 'SOCIÉTÉ DISPARUE',
                'cf_ice' => '009999999000011',
                'billing_address' => ['address' => '12 rue X', 'city' => 'Casablanca', 'zip' => '20000', 'country_code' => 'MA'],
            ])],
        )->executer();

        $this->assertSame(1, $rapport['importees']);
        $this->assertStringContainsString('client créé au passage', $rapport['details'][0]['raison']);

        $nouveau = Tiers::firstWhere('name', 'SOCIÉTÉ DISPARUE');
        $this->assertNotNull($nouveau);
        $this->assertSame('009999999000011', $nouveau->ice);
        $this->assertSame('Casablanca', $nouveau->city);
        $this->assertSame('client-inconnu', $nouveau->source_id);
    }

    public function test_plusieurs_factures_du_meme_client_inconnu_ne_le_creent_qu_une_fois(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $inconnu = ['customer_id' => 'client-inconnu', 'customer_name' => 'SOCIÉTÉ DISPARUE', 'cf_ice' => ''];

        $this->zohoRend(
            [$this->resume(), $this->resume(['invoice_id' => 'facture-2', 'invoice_number' => 'MDK24-00111'])],
            [
                'facture-1' => $this->detail($inconnu),
                'facture-2' => $this->detail($inconnu + ['invoice_id' => 'facture-2', 'invoice_number' => 'MDK24-00111']),
            ],
        )->executer();

        $this->assertSame(2, DocumentVente::count());
        $this->assertSame(2, Tiers::count(), 'Un seul client créé, pas un par facture.');
    }

    /* --------------------------- simulation -------------------------- */

    public function test_la_simulation_n_ecrit_rien_mais_annonce_le_vrai_resultat(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $simule = $this->importSimple(['simulation' => true]);

        $this->assertSame(0, DocumentVente::count(), 'La simulation n\'écrit rien.');
        $this->assertSame(0, Tiers::count() - 1, 'Ni tiers…');
        $this->assertSame(0, Ecriture::where('journal', Ecriture::JOURNAL_VENTES)->count(), '…ni écriture.');

        $reel = $this->importSimple();

        $this->assertSame($reel['importees'], $simule['importees']);
        $this->assertSame($reel['refusees'], $simule['refusees']);
        $this->assertSame($reel['ca_ht'], $simule['ca_ht'], 'Le CA annoncé doit être le vrai.');
    }

    /**
     * Une simulation qui annonce des refus imaginaires ne sert à rien : le
     * client créé pour la première facture est annulé avec elle, donc la
     * seconde ne doit pas se retrouver à pointer vers une ligne disparue.
     */
    public function test_la_simulation_enchaine_plusieurs_factures_d_un_client_inconnu(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $inconnu = ['customer_id' => 'client-inconnu', 'customer_name' => 'SOCIÉTÉ DISPARUE', 'cf_ice' => ''];

        $rapport = $this->zohoRend(
            [$this->resume(), $this->resume(['invoice_id' => 'facture-2', 'invoice_number' => 'MDK24-00111'])],
            [
                'facture-1' => $this->detail($inconnu),
                'facture-2' => $this->detail($inconnu + ['invoice_id' => 'facture-2', 'invoice_number' => 'MDK24-00111']),
            ],
        )->executer(['simulation' => true]);

        $this->assertSame(2, $rapport['importees']);
        $this->assertSame(0, $rapport['refusees']);
        $this->assertSame(0, DocumentVente::count());
    }

    /* ------------------------ verrou comptable ----------------------- */

    /**
     * L'obstacle annoncé dès le départ : un exercice clôturé refuse toute
     * écriture datée de cette année-là ou d'avant. La reprise doit le DIRE —
     * d'emblée, et sans s'interrompre au milieu des mille trois cents pièces.
     */
    public function test_un_exercice_cloture_fait_refuser_les_factures_de_cette_annee_la(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        Exercice::create(['annee' => 2024, 'resultat' => 0, 'cloture_at' => now()]);

        $rapport = $this->importSimple();

        $this->assertSame(0, $rapport['importees']);
        $this->assertSame(1, $rapport['refusees']);
        $this->assertSame(2024, $rapport['exercice_clos'], 'Le rapport doit nommer l\'année qui bloque.');
        $this->assertStringContainsString('clôturé', $rapport['details'][0]['raison']);
        $this->assertSame(0, DocumentVente::count(), 'Rien ne doit rester d\'une facture refusée.');
    }

    public function test_une_facture_posterieure_a_la_cloture_passe_quand_meme(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        Exercice::create(['annee' => 2023, 'resultat' => 0, 'cloture_at' => now()]);

        $rapport = $this->importSimple();

        $this->assertSame(1, $rapport['importees']);
        $this->assertSame(2023, $rapport['exercice_clos']);
    }

    /* --------------------------- robustesse -------------------------- */

    public function test_une_facture_qui_echoue_n_empeche_pas_les_suivantes(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $rapport = $this->zohoRend(
            [
                $this->resume(['invoice_id' => 'f-cassee']),
                $this->resume(),
            ],
            [
                'f-cassee' => $this->detail(['invoice_id' => 'f-cassee', 'invoice_number' => '', 'line_items' => []]),
                'facture-1' => $this->detail(),
            ],
        )->executer();

        $this->assertSame(1, $rapport['refusees']);
        $this->assertSame(1, $rapport['importees']);
        $this->assertSame(1, DocumentVente::count());
    }

    /**
     * RÉGRESSION — l'annuaire en mémoire ne doit rien retenir d'une transaction
     * annulée.
     *
     * L'annuaire vit en mémoire ; le rollback ne le touche pas. Y inscrire un
     * tiers depuis l'INTÉRIEUR de la transaction, c'était garder son identifiant
     * alors que sa ligne venait de disparaître — et un refus est ici chose
     * courante (exercice clôturé, total incohérent). Les factures suivantes du
     * même client pointaient alors sur une ligne morte : sur PostgreSQL,
     * violation de clé étrangère en cascade ; sur SQLite, pire, l'identifiant
     * est réattribué et la créance part chez un AUTRE client, sans un mot au
     * rapport.
     */
    public function test_un_client_cree_pour_une_facture_refusee_ne_contamine_pas_les_suivantes(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $inconnu = ['customer_id' => 'client-fantome', 'customer_name' => 'SOCIÉTÉ FANTÔME', 'cf_ice' => ''];

        $rapport = $this->zohoRend(
            [
                $this->resume(['invoice_id' => 'f-refusee']),
                $this->resume(['invoice_id' => 'f-bonne', 'invoice_number' => 'MDK24-00111']),
            ],
            [
                // Refusée : le total ne tombe pas juste. Le client est créé puis annulé.
                'f-refusee' => $this->detail(
                    $inconnu + ['invoice_id' => 'f-refusee'],
                    [$this->ligne(['quantity' => 1, 'rate' => 100, 'item_total' => 100])],
                ),
                // Même client, facture saine : elle doit passer, et le client
                // doit être recréé puisque le premier n'a jamais existé.
                'f-bonne' => $this->detail($inconnu + ['invoice_id' => 'f-bonne', 'invoice_number' => 'MDK24-00111']),
            ],
        )->executer();

        $this->assertSame(1, $rapport['refusees']);
        $this->assertSame(1, $rapport['importees'], 'La seconde facture ne doit pas hériter du refus de la première.');

        $facture = DocumentVente::firstWhere('code', 'MDK24-00111');
        $this->assertNotNull($facture);

        // LA garantie : le tiers du document existe vraiment, et c'est le bon.
        $tiers = Tiers::find($facture->tiers_id);
        $this->assertNotNull($tiers, 'Le document ne doit pas pointer sur une ligne annulée.');
        $this->assertSame('SOCIÉTÉ FANTÔME', $tiers->name, 'Ni sur le client de quelqu\'un d\'autre.');
    }

    public function test_une_ligne_sans_article_connu_reste_un_libelle(): void
    {
        $this->dansUneEntreprise();
        $this->clientEtArticle();

        $this->zohoRend(
            [$this->resume()],
            ['facture-1' => $this->detail([], [$this->ligne(['item_id' => 'article-disparu'])])],
        )->executer();

        $ligne = DocumentVente::first()->lignes->first();
        $this->assertNull($ligne->produit_id);
        $this->assertSame('Papier traceur 0.914x50', $ligne->designation);
    }
}
