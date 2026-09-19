<?php

namespace Tests\Feature;

use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retrouver une pièce dans quatre ans d'historique.
 *
 * Le problème concret : 1 314 factures, une pagination « Précédent / Suivant »,
 * et aucun filtre de période. Atteindre une facture de 2022 demandait
 * quatre-vingts clics — autant dire que l'historique repris était invisible.
 */
class NavigationVentesTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tiers $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        $this->jeton = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->json('token');

        // Le décor se monte hors requête HTTP : sans contexte d'entreprise, le
        // scope multi-entreprises est fail-closed et rien ne s'écrirait.
        app(\App\Core\Tenancy\TenantContext::class)->set(
            \App\Models\User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant,
        );

        $this->client = app(TiersService::class)->create([
            'name' => 'CLIM & COOL', 'is_client' => true, 'is_supplier' => false,
            'ice' => '000080850000035',
        ]);
    }

    private function facture(string $date, float $prix = 100, array $extra = []): DocumentVente
    {
        return app(VenteService::class)->create(array_merge([
            'type' => DocumentVente::TYPE_FACTURE,
            'tiers_id' => $this->client->id,
            'date_document' => $date,
            'lignes' => [[
                'designation' => $extra['designation'] ?? 'Toner Konica',
                'quantite' => 1, 'prix_unitaire' => $prix, 'tva_rate' => 20,
            ]],
        ], array_diff_key($extra, ['designation' => null])));
    }

    /** @return array<string, mixed> */
    private function lister(array $params = []): array
    {
        return $this->withToken($this->jeton)
            ->getJson('/api/v1/ventes/documents?'.http_build_query($params + ['type' => 'facture']))
            ->assertOk()
            ->json();
    }

    /* ---------------------------------------------------------------- */

    public function test_les_annees_disponibles_sont_rendues_avec_leur_compte(): void
    {
        $this->facture('2022-04-28');
        $this->facture('2022-11-24');
        $this->facture('2026-09-18');

        $annees = $this->withToken($this->jeton)
            ->getJson('/api/v1/ventes/documents/annees?type=facture')
            ->assertOk()
            ->json('data');

        // Décroissant : l'année en cours d'abord, c'est là qu'on travaille.
        $this->assertSame([
            ['annee' => 2026, 'total' => 1],
            ['annee' => 2022, 'total' => 2],
        ], $annees);
    }

    public function test_filtrer_sur_une_annee_ne_rend_que_ses_pieces(): void
    {
        $this->facture('2022-04-28');
        $this->facture('2024-06-15');
        $this->facture('2026-09-18');

        $resultat = $this->lister(['annee' => 2022]);

        $this->assertCount(1, $resultat['data']);
        $this->assertSame('2022-04-28', $resultat['data'][0]['date_document']);
    }

    public function test_une_plage_de_dates_borne_des_deux_cotes(): void
    {
        $this->facture('2023-12-31');
        $this->facture('2024-06-15');
        $this->facture('2025-01-01');

        $resultat = $this->lister(['date_debut' => '2024-01-01', 'date_fin' => '2024-12-31']);

        $this->assertCount(1, $resultat['data']);
        $this->assertSame('2024-06-15', $resultat['data'][0]['date_document']);
    }

    /**
     * On cherche avec ce qu'on a sous les yeux, pas avec le code interne : le
     * bon de commande cité par le client, son ICE, ou le nom d'un article.
     */
    public function test_la_recherche_trouve_par_bon_de_commande(): void
    {
        $this->facture('2024-06-15', 100, ['reference_client' => 'CO24-00124']);
        $this->facture('2024-06-16');

        $resultat = $this->lister(['search' => 'CO24-00124']);

        $this->assertCount(1, $resultat['data']);
        $this->assertSame('CO24-00124', $resultat['data'][0]['reference_client']);
    }

    public function test_la_recherche_trouve_par_ice_du_client(): void
    {
        $this->facture('2024-06-15');

        $this->assertCount(1, $this->lister(['search' => '000080850000035'])['data']);
    }

    public function test_la_recherche_trouve_par_designation_de_ligne(): void
    {
        $this->facture('2024-06-15', 100, ['designation' => 'Toner Konica Minolta']);
        $this->facture('2024-06-16', 100, ['designation' => 'Papier traceur']);

        $resultat = $this->lister(['search' => 'traceur']);

        $this->assertCount(1, $resultat['data']);
    }

    /** Le piège de PostgreSQL : `LIKE` y est sensible à la casse. */
    public function test_la_recherche_ignore_la_casse(): void
    {
        $this->facture('2024-06-15', 100, ['designation' => 'Toner Konica Minolta']);

        $this->assertCount(1, $this->lister(['search' => 'konica'])['data']);
        $this->assertCount(1, $this->lister(['search' => 'clim'])['data']);
    }

    public function test_le_tri_par_montant_fonctionne_dans_les_deux_sens(): void
    {
        $this->facture('2024-01-01', 500);
        $this->facture('2024-01-02', 100);
        $this->facture('2024-01-03', 300);

        $croissant = $this->lister(['tri' => 'total_ttc', 'direction' => 'asc']);
        $this->assertSame('120.00', $croissant['data'][0]['total_ttc']);

        $decroissant = $this->lister(['tri' => 'total_ttc', 'direction' => 'desc']);
        $this->assertSame('600.00', $decroissant['data'][0]['total_ttc']);
    }

    /**
     * Une colonne de tri libre, c'est une injection ouverte — et un tri sur une
     * colonne non indexée, une liste qui rame sans qu'on sache pourquoi.
     */
    public function test_une_colonne_de_tri_inconnue_retombe_sur_l_ordre_par_defaut(): void
    {
        $this->facture('2022-01-01');
        $this->facture('2026-01-01');

        $resultat = $this->lister(['tri' => 'total_ttc; DROP TABLE documents_vente', 'direction' => 'asc']);

        // Ordre par défaut : la plus récente d'abord.
        $this->assertSame('2026-01-01', $resultat['data'][0]['date_document']);
        $this->assertSame(2, $resultat['meta']['total']);
    }

    public function test_une_fourchette_de_montants_borne_la_liste(): void
    {
        $this->facture('2024-01-01', 100);   // 120 TTC
        $this->facture('2024-01-02', 1000);  // 1 200 TTC

        $resultat = $this->lister(['montant_min' => 500]);

        $this->assertCount(1, $resultat['data']);
        $this->assertSame('1200.00', $resultat['data'][0]['total_ttc']);
    }

    public function test_filtrer_par_client_ne_rend_que_ses_pieces(): void
    {
        $autre = app(TiersService::class)->create([
            'name' => 'AUTRE SARL', 'is_client' => true, 'is_supplier' => false,
        ]);

        $this->facture('2024-01-01');
        app(VenteService::class)->create([
            'type' => DocumentVente::TYPE_FACTURE,
            'tiers_id' => $autre->id,
            'date_document' => '2024-01-02',
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 50, 'tva_rate' => 20]],
        ]);

        $resultat = $this->lister(['tiers_id' => $this->client->id]);

        $this->assertCount(1, $resultat['data']);
        $this->assertSame($this->client->id, $resultat['data'][0]['tiers']['id']);
    }

    /** Les filtres se combinent, sinon ils ne servent à rien sur 1 300 pièces. */
    public function test_les_filtres_se_combinent(): void
    {
        $this->facture('2022-04-28', 100, ['designation' => 'Toner']);
        $this->facture('2022-05-04', 900, ['designation' => 'Toner']);
        $this->facture('2024-04-28', 900, ['designation' => 'Toner']);

        $resultat = $this->lister(['annee' => 2022, 'montant_min' => 500, 'search' => 'toner']);

        $this->assertCount(1, $resultat['data']);
        $this->assertSame('2022-05-04', $resultat['data'][0]['date_document']);
    }
}
