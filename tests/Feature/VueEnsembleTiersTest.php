<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Portail\Models\Acheteur;
use App\Modules\Portail\Models\AcheteurTiers;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\ContactService;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MontePortail;
use Tests\TestCase;

/**
 * La vue d'ensemble d'un tiers, façon Zoho Books : un seul appel pour toute la
 * colonne de droite et la carte du contact.
 *
 * Trois choses y sont éprouvées plus que le reste. Les chiffres disent la même
 * chose que la liste (solde SIGNÉ du grand livre) et que la caisse (plafond).
 * Les revenus suivent la définition du tableau de bord, mois vides compris et
 * bornes exactes. Et rien ne fuit : ni l'acheteur d'une autre entreprise ou
 * d'un autre client, ni le chiffre d'affaires à qui n'a pas le droit de le
 * voir — par aucune des routes qu'il peut appeler.
 */
class VueEnsembleTiersTest extends TestCase
{
    use MontePortail;
    use RefreshDatabase;

    /** @var array{token: string, slug: string} */
    private array $grossiste;

    private Tenant $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        // Un mercredi de mi-octobre : six mois = mai à octobre, douze mois =
        // novembre 2025 à octobre 2026, l'année = 2026 entière.
        Carbon::setTestNow('2026-10-15 10:00:00');

        $this->grossiste = $this->grossiste('Media Desk', 'admin@mediadesk.ma');
        $this->entreprise = User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant;
        $this->dansEntreprise($this->entreprise);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Chaque appel HTTP laisse derrière lui le contexte de l'entreprise du
     * jeton : on le repose avant de travailler par les services.
     */
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

    /** Pièce d'une ligne : prix HT + 20 % de TVA, validée sauf demande contraire. */
    private function piece(Tiers $tiers, string $type, float $prixHt, ?string $date = null, bool $valider = true): DocumentVente
    {
        $document = app(VenteService::class)->create([
            'type' => $type,
            'tiers_id' => $tiers->id,
            'date_document' => $date ?? now()->toDateString(),
            'lignes' => [[
                'designation' => 'Maintenance copieurs', 'quantite' => 1,
                'prix_unitaire' => $prixHt, 'tva_rate' => 20,
            ]],
        ]);

        return $valider ? app(VenteService::class)->valider($document) : $document;
    }

    /** @return array{data: array<string, mixed>, capacites: array<string, bool>} */
    private function vue(Tiers $tiers, string $periode = '', ?string $jeton = null): array
    {
        $reponse = $this->withToken($jeton ?? $this->grossiste['token'])
            ->getJson("/api/v1/tiers/{$tiers->id}/vue-ensemble".($periode !== '' ? "?periode={$periode}" : ''))
            ->assertOk()
            ->json();

        $this->dansEntreprise($this->entreprise);

        return $reponse;
    }

    private function jetonRole(string $role): string
    {
        return User::factory()->create([
            'tenant_id' => $this->entreprise->id, 'role' => $role, 'is_active' => true,
        ])->createToken('spa')->plainTextToken;
    }

    /** @return array<string, string> ca par mois */
    private function serie(array $revenus): array
    {
        return array_column($revenus['serie'], 'ca', 'mois');
    }

    /* ------------------------------ compte ------------------------------ */

    public function test_le_compte_suit_facture_reglement_partiel_puis_avoir_qui_rend_le_solde_negatif(): void
    {
        $client = $this->tiers('CLIM & COOL');

        $facture = $this->piece($client, DocumentVente::TYPE_FACTURE, 200); // 240 TTC
        $this->assertSame(
            ['devise' => 'MAD', 'creances' => '240.00', 'credits' => '0.00'],
            $this->vue($client)['data']['compte'],
        );

        app(VenteService::class)->ajouterPaiement($facture, ['montant' => 90, 'mode' => 'virement']);
        $this->assertSame('150.00', $this->vue($client)['data']['compte']['creances']);

        // Un avoir de 240 TTC : c'est nous qui devons 90 — un crédit, pas une
        // créance négative.
        $this->piece($client, DocumentVente::TYPE_AVOIR, 200);
        $compte = $this->vue($client)['data']['compte'];
        $this->assertSame('0.00', $compte['creances']);
        $this->assertSame('90.00', $compte['credits']);

        // La liste dit la même chose, au centime : c'est le même solde signé.
        $ligne = collect($this->withToken($this->grossiste['token'])
            ->getJson('/api/v1/tiers?avec_solde=1')->assertOk()->json('data'))->firstWhere('id', $client->id);
        $this->assertSame('-90.00', $ligne['solde']);
    }

    /* ------------------------------ revenus ----------------------------- */

    public function test_les_revenus_deduisent_les_avoirs_et_gardent_les_mois_vides(): void
    {
        $client = $this->tiers('CLIM & COOL');
        $autre = $this->tiers('AUTRE SARL');

        $this->piece($client, DocumentVente::TYPE_FACTURE, 200, '2026-10-03');
        $this->piece($client, DocumentVente::TYPE_AVOIR, 50, '2026-10-10');
        $this->piece($client, DocumentVente::TYPE_FACTURE, 100, '2026-08-20');
        // Ni brouillon, ni devis, ni la facture d'un autre client.
        $this->piece($client, DocumentVente::TYPE_FACTURE, 999, '2026-09-01', valider: false);
        $this->piece($client, DocumentVente::TYPE_DEVIS, 777, '2026-07-01');
        $this->piece($autre, DocumentVente::TYPE_FACTURE, 5000, '2026-10-05');

        // Deux factures LE MÊME JOUR : l'agrégat par jour les additionne.
        $this->piece($client, DocumentVente::TYPE_FACTURE, 10.10, '2026-08-20');

        $revenus = $this->vue($client)['data']['revenus'];

        $this->assertSame('6m', $revenus['periode'], 'Six mois par défaut.');
        $this->assertSame([
            '2026-05' => '0.00', '2026-06' => '0.00', '2026-07' => '0.00',
            '2026-08' => '110.10', '2026-09' => '0.00', '2026-10' => '150.00',
        ], $this->serie($revenus));
        $this->assertSame('260.10', $revenus['total'], 'Hors taxes, avoir déduit.');
        $this->assertSame(4, $revenus['nb_pieces'], 'Trois factures et un avoir émis.');
    }

    /**
     * Facture puis avoir total dans le mois : le revenu net est nul, mais on
     * a bien vendu. Le nombre de pièces le dit — l'écran n'écrit pas « aucune
     * vente » sous un mois facturé.
     */
    public function test_un_avoir_qui_annule_tout_laisse_un_total_nul_mais_des_pieces(): void
    {
        $client = $this->tiers('CLIM & COOL');
        $vierge = $this->tiers('Sans pièce');

        $this->piece($client, DocumentVente::TYPE_FACTURE, 1000, '2026-09-10');
        $this->piece($client, DocumentVente::TYPE_AVOIR, 1000, '2026-09-20');

        $revenus = $this->vue($client)['data']['revenus'];
        $this->assertSame('0.00', $revenus['total']);
        $this->assertSame('0.00', $this->serie($revenus)['2026-09']);
        $this->assertSame(2, $revenus['nb_pieces']);

        $this->assertSame(0, $this->vue($vierge)['data']['revenus']['nb_pieces']);
    }

    /**
     * Le « Facturé TTC sur 12 mois » de la synthèse et le graphique « 12
     * derniers mois » sont sur la même fiche : ils prennent les MÊMES
     * factures. Un an glissant au jour près, sans borne haute, comptait aussi
     * celles d'entre J-365 et le 1er du mois M-11, et les pièces datées
     * d'avance.
     */
    public function test_le_facture_sur_12_mois_couvre_les_memes_mois_que_le_graphique(): void
    {
        $client = $this->tiers('INTERNATIONAL CLINIC');

        // Aujourd'hui : 2026-10-15. J-365 = 2025-10-15.
        $this->piece($client, DocumentVente::TYPE_FACTURE, 1000, '2025-10-20'); // après J-365, avant novembre
        $this->piece($client, DocumentVente::TYPE_FACTURE, 100, '2025-11-01');  // premier jour des douze mois
        $this->piece($client, DocumentVente::TYPE_FACTURE, 10, '2026-10-31');   // dernier jour du mois courant
        $this->piece($client, DocumentVente::TYPE_FACTURE, 1, '2026-11-02');    // datée d'avance

        $graphique = $this->vue($client, '12m')['data']['revenus'];
        $this->assertSame('110.00', $graphique['total']);

        $synthese = $this->withToken($this->grossiste['token'])
            ->getJson("/api/v1/tiers/{$client->id}/synthese")->assertOk()->json('data');
        $this->dansEntreprise($this->entreprise);

        // Les mêmes factures, TTC : 110 HT × 1,20.
        $this->assertSame('132.00', $synthese['ca_12_mois']);
        $this->assertSame('1333.20', $synthese['ca_ttc'], 'Le total, lui, prend tout.');
    }

    public function test_les_bornes_de_chaque_periode_sont_exactes(): void
    {
        $client = $this->tiers('CLIM & COOL');

        // Un montant par date, en puissances de deux : chaque total dit à lui
        // seul quelles pièces il a prises.
        foreach ([
            '2025-10-31' => 128, // veille des douze mois
            '2025-11-01' => 64,  // premier jour des douze mois
            '2025-12-31' => 32,  // veille de l'année civile
            '2026-01-01' => 16,  // premier jour de l'année
            '2026-04-30' => 8,   // veille des six mois
            '2026-05-01' => 4,   // premier jour des six mois
            '2026-10-31' => 2,   // dernier jour du mois courant
            '2026-11-01' => 1,   // datée d'avance : dans l'année, hors des mois glissants
        ] as $date => $montant) {
            $this->piece($client, DocumentVente::TYPE_FACTURE, $montant, $date);
        }

        $six = $this->vue($client, '6m')['data']['revenus'];
        $this->assertSame(['2026-05-01', '2026-10-31'], [$six['du'], $six['au']]);
        $this->assertCount(6, $six['serie']);
        $this->assertSame('6.00', $six['total']);

        $douze = $this->vue($client, '12m')['data']['revenus'];
        $this->assertSame(['2025-11-01', '2026-10-31'], [$douze['du'], $douze['au']]);
        $this->assertCount(12, $douze['serie']);
        $this->assertSame('2025-11', $douze['serie'][0]['mois']);
        $this->assertSame('126.00', $douze['total']);

        // L'année CIVILE en cours : janvier à décembre, mois à venir compris.
        $annee = $this->vue($client, 'annee')['data']['revenus'];
        $this->assertSame(['2026-01-01', '2026-12-31'], [$annee['du'], $annee['au']]);
        $this->assertSame(
            ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06',
                '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'],
            array_keys($this->serie($annee)),
        );
        $this->assertSame('1.00', $this->serie($annee)['2026-11']);
        $this->assertSame('0.00', $this->serie($annee)['2026-12']);
        $this->assertSame('31.00', $annee['total']);
    }

    public function test_une_periode_inconnue_est_refusee(): void
    {
        $client = $this->tiers('CLIM & COOL');

        $this->withToken($this->grossiste['token'])
            ->getJson("/api/v1/tiers/{$client->id}/vue-ensemble?periode=24m")
            ->assertStatus(422)
            ->assertJsonValidationErrors('periode');
    }

    /* ----------------------- conditions et crédit ----------------------- */

    /**
     * Null n'est pas zéro : « non renseigné » n'est pas « payable à
     * réception ». Les tiers repris de Zoho n'ont aucun délai, et leurs
     * factures sont pourtant à 30 ou 60 jours — l'écran ne doit pas inventer
     * une condition qu'on n'a jamais saisie.
     */
    public function test_le_delai_est_rendu_tel_quel_et_null_reste_distinct_de_zero(): void
    {
        $sansDelai = $this->tiers('Comptant');
        $zero = $this->tiers('Zéro jour', ['delai_paiement_jours' => 0]);
        $trente = $this->tiers('Trente jours', ['delai_paiement_jours' => 30]);

        $this->assertNull($this->vue($sansDelai)['data']['delai_paiement_jours']);
        $this->assertSame(0, $this->vue($zero)['data']['delai_paiement_jours']);
        $this->assertSame(30, $this->vue($trente)['data']['delai_paiement_jours']);
    }

    public function test_le_credit_est_nul_sans_plafond_et_suit_la_caisse_avec(): void
    {
        $libre = $this->tiers('Sans plafond');
        $this->piece($libre, DocumentVente::TYPE_FACTURE, 200);
        $this->assertNull($this->vue($libre)['data']['credit']);

        $limite = $this->tiers('Avec plafond', ['plafond_credit' => 1000]);
        $this->piece($limite, DocumentVente::TYPE_FACTURE, 200); // 240 TTC

        $this->assertSame(
            ['plafond' => '1000.00', 'encours' => '240.00', 'disponible' => '760.00'],
            $this->vue($limite)['data']['credit'],
        );

        // Le contrôle de la caisse et du portail rend les mêmes chiffres.
        $this->withToken($this->grossiste['token'])->getJson("/api/v1/tiers/{$limite->id}/encours")
            ->assertOk()
            ->assertJsonPath('data.encours', '240.00')
            ->assertJsonPath('data.disponible', '760.00');

        // Un client créditeur n'a rien consommé : encours à zéro, pas négatif.
        $this->piece($limite, DocumentVente::TYPE_AVOIR, 300); // 360 TTC
        $this->assertSame(
            ['plafond' => '1000.00', 'encours' => '0.00', 'disponible' => '1000.00'],
            $this->vue($limite)['data']['credit'],
        );

        // Le plafond sort en chaîne à deux décimales sur toutes les bases.
        $this->withToken($this->grossiste['token'])->getJson("/api/v1/tiers/{$limite->id}")
            ->assertOk()->assertJsonPath('data.plafond_credit', '1000.00');
    }

    /* ----------------------- contact et portail ------------------------- */

    public function test_le_contact_principal_est_rendu_et_null_sans_contact(): void
    {
        $client = $this->tiers('CLIM & COOL');
        $this->assertNull($this->vue($client)['data']['contact_principal']);

        app(ContactService::class)->create($client, ['nom' => 'Karim Alami', 'fonction' => 'Achats', 'email' => 'k@clim.ma']);
        app(ContactService::class)->create($client, ['nom' => 'Second', 'email' => 's@clim.ma']);

        $principal = $this->vue($client)['data']['contact_principal'];
        $this->assertSame('Karim Alami', $principal['nom']);
        $this->assertSame('Achats', $principal['fonction']);
        $this->assertSame('k@clim.ma', $principal['email']);
    }

    public function test_le_portail_ne_montre_jamais_l_acheteur_d_une_autre_entreprise(): void
    {
        $client = $this->tiers('Épicerie Atlas');

        // Un acheteur rattaché à ce client par le vrai parcours du portail.
        $jetonAcheteur = $this->acheteur('atlas@epicerie.ma', 'Atlas');
        $this->rattacher($jetonAcheteur, $this->grossiste, 'atlas@epicerie.ma', $client->id);

        // Le MÊME acheteur, rattaché aussi chez un concurrent, à SON client.
        $concurrent = $this->grossiste('Concurrent', 'admin@concurrent.ma');
        $this->rattacher($jetonAcheteur, $concurrent, 'atlas@epicerie.ma');

        // Et une ligne d'une autre entreprise qui viserait NOTRE tiers — seule
        // une corruption ou un import fautif la produirait. Le filtre
        // d'entreprise est posé à la main : c'est lui qu'on éprouve ici.
        $entrepriseB = User::withoutGlobalScopes()->firstWhere('email', 'admin@concurrent.ma')->tenant;
        $intrus = Acheteur::create(['name' => 'Intrus', 'email' => 'intrus@ailleurs.ma', 'password' => 'password123']);
        AcheteurTiers::create([
            'acheteur_id' => $intrus->id, 'tenant_id' => $entrepriseB->id, 'tiers_id' => $client->id,
            'statut' => AcheteurTiers::STATUT_APPROUVE, 'demande_at' => now(), 'approuve_at' => now(),
        ]);

        // Enfin, chez NOUS, l'acheteur d'un AUTRE client. Le filtre par tiers
        // est l'autre moitié de la requête faite à la main : sans lui, chaque
        // fiche listerait les comptes acheteurs (nom, e-mail) de tous les
        // clients de l'entreprise.
        $this->dansEntreprise($this->entreprise);
        $voisin = $this->tiers('Droguerie du Port');
        $jetonVoisin = $this->acheteur('port@droguerie.ma', 'Port');
        $this->rattacher($jetonVoisin, $this->grossiste, 'port@droguerie.ma', $voisin->id);
        $this->dansEntreprise($this->entreprise);

        $portail = $this->vue($client)['data']['portail'];

        $this->assertCount(1, $portail);
        $this->assertSame('approuve', $portail[0]['statut']);
        $this->assertSame(['name' => 'Atlas', 'email' => 'atlas@epicerie.ma'], $portail[0]['acheteur']);
        $this->assertSame('2026-10-15', $portail[0]['approuve_at']);

        $chezLeVoisin = $this->vue($voisin)['data']['portail'];
        $this->assertSame(['port@droguerie.ma'], array_column(array_column($chezLeVoisin, 'acheteur'), 'email'));
    }

    /* ------------------------------ droits ------------------------------ */

    /**
     * UNE politique pour le caissier, la même sur toutes les routes qu'il
     * peut appeler : ce que le client DOIT et ce qu'il peut encore prendre à
     * crédit, oui — la caisse le lui montre pour vendre à crédit, la liste
     * des tiers aussi ; ce que le client RAPPORTE, non, ni dans la vue
     * d'ensemble ni dans la synthèse.
     */
    public function test_le_caissier_voit_ce_que_doit_le_client_pas_ce_qu_il_rapporte(): void
    {
        $client = $this->tiers('CLIM & COOL', ['plafond_credit' => 1000, 'delai_paiement_jours' => 30]);
        $this->piece($client, DocumentVente::TYPE_FACTURE, 200); // 240 TTC
        $jeton = $this->jetonRole('caissier');

        // Caissier : tiers en lecture, ventes et achats fermés.
        $vue = $this->vue($client, jeton: $jeton);

        $this->assertFalse($vue['capacites']['ventes']);
        $this->assertTrue($vue['capacites']['portail']);
        $this->assertArrayNotHasKey('revenus', $vue['data'], 'Les revenus doivent être OMIS, pas mis à zéro.');
        $this->assertSame('240.00', $vue['data']['compte']['creances']);
        $this->assertSame(['plafond' => '1000.00', 'encours' => '240.00', 'disponible' => '760.00'], $vue['data']['credit']);
        $this->assertTrue($vue['data']['client']);
        $this->assertSame(30, $vue['data']['delai_paiement_jours']);
        $this->assertArrayHasKey('contact_principal', $vue['data']);
        $this->assertSame([], $vue['data']['portail']);

        // Les mêmes chiffres que la caisse et que la liste — pas un de plus.
        $this->withToken($jeton)->getJson("/api/v1/tiers/{$client->id}/encours")
            ->assertOk()->assertJsonPath('data.encours', '240.00')->assertJsonPath('data.disponible', '760.00');
        $ligne = collect($this->withToken($jeton)->getJson('/api/v1/tiers?avec_solde=1')->assertOk()->json('data'))
            ->firstWhere('id', $client->id);
        $this->assertSame('240.00', $ligne['solde']);

        // La synthèse, sous la même garde, omet le chiffre d'affaires.
        $synthese = $this->withToken($jeton)->getJson("/api/v1/tiers/{$client->id}/synthese")->assertOk()->json('data');
        foreach (['ca_ttc', 'ca_12_mois', 'achats_ttc'] as $cle) {
            $this->assertArrayNotHasKey($cle, $synthese, "{$cle} doit être OMIS pour le caissier.");
        }
        $this->assertSame(1, $synthese['ventes']['factures'], 'Les comptes de pièces restent.');
        $this->dansEntreprise($this->entreprise);

        // Comptable : ventes en lecture, il voit tout.
        $comptable = $this->vue($client, jeton: $this->jetonRole('comptable'));
        $this->assertTrue($comptable['capacites']['ventes']);
        $this->assertSame('240.00', $comptable['data']['compte']['creances']);
        $this->assertSame('760.00', $comptable['data']['credit']['disponible']);
        $this->assertSame('200.00', $comptable['data']['revenus']['total']);
    }

    /**
     * Le formulaire laisse cocher « prospect » sans « client » : c'est un
     * client à venir, la fiche le traite en client dès le premier affichage
     * — l'écran le suppose avant la réponse, le serveur ne le dément pas.
     */
    public function test_un_prospect_sans_la_case_client_est_traite_en_client(): void
    {
        $prospect = $this->tiers('Prospect pur', ['is_client' => false, 'is_prospect' => true]);
        $aucuneCase = $this->tiers('Sans case', ['is_client' => false]);

        $vue = $this->vue($prospect)['data'];
        $this->assertTrue($vue['client']);
        $this->assertSame(['devise' => 'MAD', 'creances' => '0.00', 'credits' => '0.00'], $vue['compte']);
        $this->assertSame('0.00', $vue['revenus']['total']);

        $this->assertFalse($this->vue($aucuneCase)['data']['client']);
    }

    public function test_un_fournisseur_pur_ne_recoit_aucun_bloc_chiffre(): void
    {
        $fournisseur = $this->tiers('Toners du Nord', ['is_client' => false, 'is_supplier' => true]);

        $vue = $this->vue($fournisseur);

        $this->assertFalse($vue['data']['client']);
        foreach (['credit', 'compte', 'revenus'] as $bloc) {
            $this->assertArrayNotHasKey($bloc, $vue['data']);
        }

        // Rien n'interdit de le facturer : il a alors un compte client, et la
        // fiche le montre comme la liste montre son solde.
        $this->piece($fournisseur, DocumentVente::TYPE_FACTURE, 100);
        $facture = $this->vue($fournisseur)['data'];
        $this->assertTrue($facture['client']);
        $this->assertSame('120.00', $facture['compte']['creances']);
        $this->assertSame('100.00', $facture['revenus']['total']);
    }

    public function test_le_tiers_d_une_autre_entreprise_est_introuvable(): void
    {
        $client = $this->tiers('CLIM & COOL');
        $concurrent = $this->grossiste('Concurrent', 'admin@concurrent.ma');

        $this->withToken($concurrent['token'])
            ->getJson("/api/v1/tiers/{$client->id}/vue-ensemble")
            ->assertNotFound();
    }

    /* ---------------------------- performance --------------------------- */

    public function test_le_nombre_de_requetes_ne_depend_ni_des_pieces_ni_des_acheteurs(): void
    {
        $petit = $this->tiers('Petit client', ['plafond_credit' => 5000]);
        $gros = $this->tiers('Gros client', ['plafond_credit' => 5000]);
        app(ContactService::class)->create($petit, ['nom' => 'Contact petit']);
        app(ContactService::class)->create($gros, ['nom' => 'Contact gros']);

        $this->piece($petit, DocumentVente::TYPE_FACTURE, 100, '2026-10-01');
        for ($mois = 1; $mois <= 10; $mois++) {
            $this->piece($gros, DocumentVente::TYPE_FACTURE, 100 * $mois, sprintf('2026-%02d-05', $mois));
            $this->piece($gros, DocumentVente::TYPE_FACTURE, 50, sprintf('2026-%02d-20', $mois));
        }
        $this->piece($gros, DocumentVente::TYPE_AVOIR, 30, '2026-09-25');

        $rattacher = function (Tiers $tiers, int $combien): void {
            for ($i = 1; $i <= $combien; $i++) {
                $acheteur = Acheteur::create([
                    'name' => "Acheteur {$tiers->id}-{$i}", 'email' => "a{$tiers->id}-{$i}@portail.ma", 'password' => 'password123',
                ]);
                AcheteurTiers::create([
                    'acheteur_id' => $acheteur->id, 'tenant_id' => $this->entreprise->id, 'tiers_id' => $tiers->id,
                    'statut' => AcheteurTiers::STATUT_APPROUVE, 'demande_at' => now(), 'approuve_at' => now(),
                ]);
            }
        };
        $rattacher($petit, 1);
        $rattacher($gros, 4);

        $compter = function (Tiers $tiers): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->vue($tiers, 'annee');
            $nombre = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $nombre;
        };

        // Premier passage à blanc : caches de permissions, plan comptable…
        $compter($petit);

        $pourLePetit = $compter($petit);
        $pourLeGros = $compter($gros);

        $this->assertSame($pourLePetit, $pourLeGros, 'Une requête par pièce, par mois ou par acheteur : le N+1 est revenu.');
        // 14 le 2026-10-07 : neuf de socle (jeton, utilisateur, tiers,
        // entreprise, plan comptable et comptes de créance), puis solde,
        // contact, acheteurs (deux), pièces de la période.
        $this->assertLessThanOrEqual(16, $pourLeGros, 'Un seul appel pour toute la vue : le compte doit rester court.');
    }
}
