<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\RenumerotationService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La numérotation des journaux comptables.
 *
 * Une série appartient à son EXERCICE, et l'administration fiscale marocaine
 * attend qu'elle soit chronologique et continue. La reprise Zoho a écrit
 * « VT-2026-01195 » sur une facture du 28 avril 2022 : la date était juste, le
 * numéro non — 2 059 écritures dans ce cas.
 */
class RenumerotationEcrituresTest extends TestCase
{
    use RefreshDatabase;

    private Tiers $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        app(TenantContext::class)->set(
            User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant,
        );

        $this->client = app(TiersService::class)->create([
            'name' => 'CLIM & COOL', 'is_client' => true, 'is_supplier' => false,
        ]);
    }

    private function facture(string $date): DocumentVente
    {
        return app(VenteService::class)->valider(app(VenteService::class)->create([
            'type' => DocumentVente::TYPE_FACTURE,
            'tiers_id' => $this->client->id,
            'date_document' => $date,
            'lignes' => [['designation' => 'Toner', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ]));
    }

    /** @return list<string> Les numéros du journal des ventes, dans l'ordre des dates. */
    private function numeros(): array
    {
        return Ecriture::where('journal', Ecriture::JOURNAL_VENTES)
            ->orderBy('date_ecriture')
            ->orderBy('id')
            ->pluck('numero')
            ->all();
    }

    /* ------------------------ la cause, corrigée ---------------------- */

    /**
     * LA correction de fond : la série suit l'année de la PIÈCE, jamais celle
     * du jour où on la saisit.
     */
    public function test_une_piece_anterieure_recoit_la_serie_de_son_exercice(): void
    {
        $this->facture('2022-04-28');

        $this->assertSame('VT-2022-00001', Ecriture::where('journal', 'VT')->first()->numero);
    }

    public function test_chaque_exercice_a_sa_propre_serie(): void
    {
        $this->facture('2022-04-28');
        $this->facture('2022-05-04');
        $this->facture('2024-06-15');

        $this->assertSame(['VT-2022-00001', 'VT-2022-00002', 'VT-2024-00001'], $this->numeros());
    }

    /** Le journal de trésorerie suit la même règle que celui des ventes. */
    public function test_l_encaissement_suit_l_annee_de_son_reglement(): void
    {
        $facture = $this->facture('2022-04-28');

        app(VenteService::class)->ajouterPaiement($facture, [
            'date_paiement' => '2022-05-10', 'montant' => 120, 'mode' => 'virement',
        ]);

        $this->assertSame('BQ-2022-00001', Ecriture::where('journal', 'BQ')->first()->numero);
    }

    /* --------------------- la réparation du passé --------------------- */

    /**
     * Fabrique l'état d'avant le correctif : tout dans la série de l'année
     * courante, et dans l'ordre de SAISIE — donc à l'envers des dates, comme
     * l'import parcourait la liste de Books.
     *
     * En deux passes, comme le service : renuméroter en place heurterait
     * l'index unique, et ce serait le banc d'essai qui casserait avant le code.
     */
    private function abimerLaNumerotation(): void
    {
        $ecritures = Ecriture::where('journal', 'VT')->orderByDesc('date_ecriture')->get();

        foreach ($ecritures as $ecriture) {
            DB::table('ecritures')->where('id', $ecriture->id)->update(['numero' => 'tmp-'.$ecriture->id]);
        }

        $rang = 0;
        foreach ($ecritures as $ecriture) {
            DB::table('ecritures')
                ->where('id', $ecriture->id)
                ->update(['numero' => sprintf('VT-%d-%05d', now()->year, ++$rang)]);
        }
    }

    public function test_la_renumerotation_rend_chaque_piece_a_son_exercice(): void
    {
        $this->facture('2022-04-28');
        $this->facture('2023-06-15');
        $this->facture('2024-09-01');
        $this->abimerLaNumerotation();

        app(RenumerotationService::class)->renumeroter(['VT']);

        $this->assertSame(['VT-2022-00001', 'VT-2023-00001', 'VT-2024-00001'], $this->numeros());
    }

    /**
     * Une fois les autres années sorties, la série de l'année courante reste
     * trouée et dans le désordre. Un journal troué n'est pas régulier : on
     * renumérote TOUT, ou le travail n'est pas fait.
     */
    public function test_la_serie_est_continue_et_dans_l_ordre_des_dates(): void
    {
        $this->facture('2026-03-01');
        $this->facture('2026-01-15');
        $this->facture('2026-02-20');
        $this->abimerLaNumerotation();

        app(RenumerotationService::class)->renumeroter(['VT']);

        $numeros = $this->numeros();

        $this->assertSame(['VT-2026-00001', 'VT-2026-00002', 'VT-2026-00003'], $numeros);

        // …et le premier numéro va bien à la pièce la plus ancienne.
        $this->assertSame(
            '2026-01-15',
            Ecriture::where('numero', 'VT-2026-00001')->first()->date_ecriture->toDateString(),
        );
    }

    /**
     * `ecritures` porte un index unique sur (tenant_id, numero) : renuméroter
     * en place ferait réclamer à l'une un numéro que l'autre n'a pas encore
     * quitté. D'où la double passe — et ce test, qui la force en inversant
     * complètement l'ordre.
     */
    public function test_inverser_completement_une_serie_ne_heurte_pas_l_index_unique(): void
    {
        foreach (['2026-05-01', '2026-04-01', '2026-03-01', '2026-02-01'] as $date) {
            $this->facture($date);
        }

        // Numéros à l'envers exact de ce que la renumérotation va poser.
        $rang = 0;
        foreach (Ecriture::where('journal', 'VT')->orderByDesc('date_ecriture')->get() as $ecriture) {
            DB::table('ecritures')->where('id', $ecriture->id)
                ->update(['numero' => sprintf('VT-2026-%05d', ++$rang)]);
        }

        app(RenumerotationService::class)->renumeroter(['VT']);

        $this->assertSame(
            ['VT-2026-00001', 'VT-2026-00002', 'VT-2026-00003', 'VT-2026-00004'],
            $this->numeros(),
        );
        $this->assertSame(0, Ecriture::where('numero', 'like', '~%')->count(), 'Aucun numéro temporaire ne doit rester.');
    }

    /**
     * Sans recaler le compteur, la facture suivante repartirait d'un numéro
     * déjà pris — et échouerait sur l'index unique au pire moment, celui d'une
     * validation.
     */
    public function test_la_piece_suivante_ne_heurte_pas_les_numeros_repris(): void
    {
        $this->facture('2026-01-15');
        $this->facture('2026-02-20');
        $this->abimerLaNumerotation();

        app(RenumerotationService::class)->renumeroter(['VT']);

        $this->facture('2026-03-10');

        $this->assertSame(
            ['VT-2026-00001', 'VT-2026-00002', 'VT-2026-00003'],
            $this->numeros(),
        );
    }

    public function test_une_serie_deja_juste_n_est_pas_touchee(): void
    {
        $this->facture('2026-01-15');
        $this->facture('2026-02-20');

        $rapport = app(RenumerotationService::class)->renumeroter(['VT']);

        $this->assertSame(0, $rapport['total']);
        $this->assertSame([], $rapport['series']);
    }

    public function test_se_limiter_a_un_journal_laisse_les_autres_en_l_etat(): void
    {
        $facture = $this->facture('2026-01-15');
        app(VenteService::class)->ajouterPaiement($facture, ['montant' => 120, 'mode' => 'virement']);

        $avant = Ecriture::where('journal', 'BQ')->first()->numero;

        app(RenumerotationService::class)->renumeroter(['VT']);

        $this->assertSame($avant, Ecriture::where('journal', 'BQ')->first()->numero);
    }

    /** La simulation joue tout, puis annule : elle éprouve la double passe pour de vrai. */
    public function test_la_simulation_annonce_le_vrai_resultat_sans_rien_garder(): void
    {
        $this->facture('2022-04-28');
        $this->facture('2023-06-15');
        $this->abimerLaNumerotation();

        $avant = Ecriture::orderBy('id')->pluck('numero')->all();

        $rapport = app(RenumerotationService::class)->renumeroter(['VT'], simulation: true);

        $this->assertSame(2, $rapport['total']);
        $this->assertSame($avant, Ecriture::orderBy('id')->pluck('numero')->all(), 'Rien ne doit avoir bougé.');

        $reel = app(RenumerotationService::class)->renumeroter(['VT']);
        $this->assertSame($rapport['total'], $reel['total'], 'La simulation doit annoncer le vrai compte.');
    }

    public function test_la_renumerotation_ne_touche_pas_les_autres_entreprises(): void
    {
        $this->facture('2022-04-28');
        $this->abimerLaNumerotation();

        // On retient l'identifiant RÉEL : PostgreSQL n'attribue pas ses clés
        // comme SQLite, et un « find(1) » écrit en dur passe sur un moteur et
        // tombe sur l'autre.
        $temoin = Ecriture::first();
        $intact = $temoin->numero;

        // Une autre entreprise renumérote la sienne : celle-ci ne bouge pas.
        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Concurrent', 'name' => 'Autre',
            'email' => 'autre@concurrent.ma', 'password' => 'password123',
        ])->assertCreated();

        app(TenantContext::class)->set(
            User::withoutGlobalScopes()->firstWhere('email', 'autre@concurrent.ma')->tenant,
        );

        app(RenumerotationService::class)->renumeroter();

        $this->assertSame($intact, Ecriture::withoutGlobalScopes()->find($temoin->id)->numero);
    }
}
