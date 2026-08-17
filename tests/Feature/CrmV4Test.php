<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM v4 — prospects (flag + origine du lead + conversion), fiche détaillée
 * d'une opportunité et statistiques commerciales.
 */
class CrmV4Test extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $company, string $email): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin '.$company,
            'email' => $email,
            'password' => 'password123',
        ])->json('token');
    }

    private function activerCrm(string $token): void
    {
        $this->withToken($token)->putJson('/api/v1/parametres', ['features' => ['crm' => true]])->assertOk();
    }

    private function tiers(string $token, array $data = []): array
    {
        return $this->withToken($token)
            ->postJson('/api/v1/tiers', array_merge(['name' => 'Client X'], $data))
            ->json('data');
    }

    private function opportunite(string $token, int $tiersId, array $overrides = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/crm/opportunites', array_merge([
            'tiers_id' => $tiersId, 'titre' => 'Projet site web', 'montant_estime' => 10000, 'probabilite' => 50,
        ], $overrides))->json('data');
    }

    /* ---------------------------------------------------------------- */
    /* Lot 1 — Prospects                                                 */
    /* ---------------------------------------------------------------- */

    public function test_prospect_avec_source_puis_filtres_et_conversion_manuelle(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');

        $prospect = $this->tiers($token, [
            'name' => 'Prospect Alpha', 'is_prospect' => true, 'lead_source' => 'salon',
        ]);
        $this->assertTrue($prospect['is_prospect']);
        $this->assertSame('salon', $prospect['lead_source']);
        // Le prospect garde un code client : conversion sans rupture d'historique.
        $this->assertStringStartsWith('CL-', $prospect['code']);

        $client = $this->tiers($token, ['name' => 'Client Beta']);

        // Filtre prospects : seulement Alpha. Filtre clients : seulement Beta.
        $this->withToken($token)->getJson('/api/v1/tiers?type=prospect')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Prospect Alpha');
        $this->withToken($token)->getJson('/api/v1/tiers?type=client')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Client Beta');

        // Filtre par origine de lead.
        $this->withToken($token)->getJson('/api/v1/tiers?lead_source=salon')
            ->assertOk()->assertJsonPath('meta.total', 1);

        // Conversion manuelle → devient client, horodatée.
        $converti = $this->withToken($token)->postJson("/api/v1/tiers/{$prospect['id']}/convertir")->assertOk();
        $converti->assertJsonPath('data.is_prospect', false)->assertJsonPath('data.is_client', true);
        $this->assertNotNull($converti->json('data.converti_at'));
        $this->assertSame($prospect['code'], $converti->json('data.code')); // code inchangé

        // Il bascule du filtre prospect vers le filtre client.
        $this->withToken($token)->getJson('/api/v1/tiers?type=prospect')->assertJsonPath('meta.total', 0);
        $this->withToken($token)->getJson('/api/v1/tiers?type=client')->assertJsonPath('meta.total', 2);
    }

    public function test_source_de_lead_invalide_refusee(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');

        $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'X', 'is_prospect' => true, 'lead_source' => 'pigeon_voyageur',
        ])->assertUnprocessable()->assertJsonValidationErrors('lead_source');
    }

    public function test_opportunite_gagnee_convertit_le_prospect_en_client(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);

        $prospect = $this->tiers($token, ['name' => 'Prospect Gamma', 'is_prospect' => true, 'lead_source' => 'site_web']);
        $opp = $this->opportunite($token, $prospect['id']);

        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$opp['id']}/cloturer", ['statut' => 'gagnee'])
            ->assertOk();

        $apres = $this->withToken($token)->getJson("/api/v1/tiers/{$prospect['id']}")->assertOk();
        $apres->assertJsonPath('data.is_prospect', false)->assertJsonPath('data.is_client', true);
        $this->assertNotNull($apres->json('data.converti_at'));
    }

    /* ---------------------------------------------------------------- */
    /* Lot 2 — Fiche détaillée d'opportunité                             */
    /* ---------------------------------------------------------------- */

    public function test_fiche_opportunite_expose_activites_documents_et_dates(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->tiers($token);
        $opp = $this->opportunite($token, $client['id'], ['montant_estime' => 8000]);

        // Une activité rattachée à l'opportunité.
        $this->withToken($token)->postJson('/api/v1/crm/activites', [
            'tiers_id' => $client['id'], 'opportunite_id' => $opp['id'],
            'type' => 'appel', 'sujet' => 'Relance devis',
        ])->assertCreated();

        // Un devis généré depuis l'opportunité.
        $devis = $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$opp['id']}/devis")->assertCreated();

        $fiche = $this->withToken($token)->getJson("/api/v1/crm/opportunites/{$opp['id']}")->assertOk();

        $fiche->assertJsonPath('data.opportunite.id', $opp['id'])
            ->assertJsonPath('data.vendeur', 'Admin T')
            ->assertJsonPath('data.activites.0.sujet', 'Relance devis')
            ->assertJsonPath('data.documents.0.code', $devis->json('devis_code'))
            ->assertJsonPath('data.documents.0.type', 'devis');

        $this->assertNotNull($fiche->json('data.dates.creee_le'));
        $this->assertSame(0, $fiche->json('data.dates.jours_ouverts'));
    }

    public function test_fiche_opportunite_gatee_par_le_feature_flag(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->tiers($token);
        $opp = $this->opportunite($token, $client['id']);

        // On coupe le module : la fiche n'est plus accessible.
        $this->withToken($token)->putJson('/api/v1/parametres', ['features' => ['crm' => false]])->assertOk();
        $this->withToken($token)->getJson("/api/v1/crm/opportunites/{$opp['id']}")->assertForbidden();
    }

    /* ---------------------------------------------------------------- */
    /* Lot 3 — Statistiques commerciales                                 */
    /* ---------------------------------------------------------------- */

    public function test_stats_taux_conversion_vendeur_source_et_entonnoir(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);

        $p1 = $this->tiers($token, ['name' => 'P1', 'is_prospect' => true, 'lead_source' => 'salon']);
        $p2 = $this->tiers($token, ['name' => 'P2', 'is_prospect' => true, 'lead_source' => 'site_web']);

        // 2 gagnées (12000 + 8000), 1 perdue (5000), 1 ouverte (10000 @ 50%).
        $g1 = $this->opportunite($token, $p1['id'], ['montant_estime' => 12000]);
        $g2 = $this->opportunite($token, $p2['id'], ['montant_estime' => 8000]);
        $perdue = $this->opportunite($token, $p1['id'], ['montant_estime' => 5000]);
        $ouverte = $this->opportunite($token, $p1['id'], ['montant_estime' => 10000, 'probabilite' => 50]);

        foreach ([$g1, $g2] as $o) {
            $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$o['id']}/cloturer", ['statut' => 'gagnee'])->assertOk();
        }
        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$perdue['id']}/cloturer", ['statut' => 'perdue'])->assertOk();

        // Un devis depuis l'opportunité ouverte → alimente l'entonnoir.
        $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$ouverte['id']}/devis")->assertCreated();

        $stats = $this->withToken($token)->getJson('/api/v1/crm/stats')->assertOk();

        // 2 gagnées / 3 tranchées = 66.7 %
        $stats->assertJsonPath('data.synthese.total', 4)
            ->assertJsonPath('data.synthese.gagnees', 2)
            ->assertJsonPath('data.synthese.perdues', 1)
            ->assertJsonPath('data.synthese.ouvertes', 1)
            ->assertJsonPath('data.synthese.taux_conversion', 66.7)
            ->assertJsonPath('data.synthese.montant_gagne', '20000.00')
            ->assertJsonPath('data.synthese.montant_perdu', '5000.00')
            ->assertJsonPath('data.synthese.pipeline_ouvert', '10000.00')
            ->assertJsonPath('data.synthese.forecast_pondere', '5000.00')
            ->assertJsonPath('data.synthese.panier_moyen_gagne', '10000.00');

        // Performance du seul commercial (l'admin qui a créé les opportunités).
        $stats->assertJsonPath('data.par_vendeur.0.vendeur', 'Admin T')
            ->assertJsonPath('data.par_vendeur.0.gagnees', 2)
            ->assertJsonPath('data.par_vendeur.0.taux_conversion', 66.7)
            ->assertJsonPath('data.par_vendeur.0.montant_gagne', '20000.00');

        // Origines de lead : salon (P1, converti) et site_web (P2, converti).
        $sources = collect($stats->json('data.par_source'))->keyBy('source');
        $this->assertSame(1, $sources['salon']['total']);
        $this->assertSame(1, $sources['salon']['convertis']); // gagnée → converti
        $this->assertSame(1, $sources['site_web']['convertis']);

        // Entonnoir : 4 opportunités → 1 devis (JSON sérialise 25.0 en 25).
        $stats->assertJsonPath('data.transformation.opportunites', 4)
            ->assertJsonPath('data.transformation.devis', 1)
            ->assertJsonPath('data.transformation.taux_devis', 25);
    }

    public function test_entonnoir_suit_la_chaine_devis_vers_facture(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->tiers($token);
        $opp = $this->opportunite($token, $client['id'], ['montant_estime' => 10000]);

        // Devis depuis l'opportunité, puis validation et transformation en facture.
        $devisId = $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$opp['id']}/devis")
            ->assertCreated()->json('devis_id');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devisId}/valider")->assertOk();
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$devisId}/transformer", ['type' => 'facture'])
            ->assertSuccessful();

        // La facture hérite du lien : elle compte dans l'entonnoir et son CA.
        $stats = $this->withToken($token)->getJson('/api/v1/crm/stats')->assertOk();
        $stats->assertJsonPath('data.transformation.devis', 1)
            ->assertJsonPath('data.transformation.factures', 1)
            ->assertJsonPath('data.transformation.ca_facture', '12000.00'); // 10000 HT + TVA 20 %

        // Et elle apparaît dans les documents liés de la fiche.
        $codes = collect($this->withToken($token)->getJson("/api/v1/crm/opportunites/{$opp['id']}")
            ->json('data.documents'))->pluck('type');
        $this->assertTrue($codes->contains('facture'));
    }

    public function test_taux_devis_ne_depasse_pas_cent_pourcent(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->tiers($token);
        $opp = $this->opportunite($token, $client['id']);

        // Trois devis sur la MÊME opportunité : le taux reste à 100 %, pas 300 %.
        foreach (range(1, 3) as $i) {
            $this->withToken($token)->postJson("/api/v1/crm/opportunites/{$opp['id']}/devis")->assertCreated();
        }

        $this->withToken($token)->getJson('/api/v1/crm/stats')->assertOk()
            ->assertJsonPath('data.transformation.devis', 3)
            ->assertJsonPath('data.transformation.taux_devis', 100);
    }

    public function test_parametre_depuis_invalide_rejete(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);

        $this->withToken($token)->getJson('/api/v1/crm/stats?depuis=pas-une-date')
            ->assertUnprocessable()->assertJsonValidationErrors('depuis');

        $this->withToken($token)->getJson('/api/v1/crm/stats?depuis=2026-01-01')->assertOk();
    }

    public function test_stats_isolees_par_tenant_et_gatees(): void
    {
        $token = $this->registerTenant('T', 'a@test.ma');
        $this->activerCrm($token);
        $client = $this->tiers($token);
        $this->opportunite($token, $client['id']);

        // Un autre tenant ne voit pas les opportunités du premier.
        $autre = $this->registerTenant('Autre', 'b@test.ma');
        $this->activerCrm($autre);
        $this->withToken($autre)->getJson('/api/v1/crm/stats')
            ->assertOk()->assertJsonPath('data.synthese.total', 0);

        // Sans le module CRM activé → 403.
        $sansCrm = $this->registerTenant('SansCrm', 'c@test.ma');
        $this->withToken($sansCrm)->getJson('/api/v1/crm/stats')->assertForbidden();
    }
}
