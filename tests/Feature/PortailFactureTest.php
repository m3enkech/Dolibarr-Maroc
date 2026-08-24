<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MontePortail;
use Tests\TestCase;

/**
 * Factures de l'acheteur : ce qu'il doit, ce qu'il a déjà réglé, ce qui est
 * passé sous traite — et rien de ce qui appartient à un autre client.
 */
class PortailFactureTest extends TestCase
{
    use MontePortail, RefreshDatabase;

    /**
     * Facture VALIDÉE du grossiste pour un de ses clients. Le montant est donné
     * en HT ; la TVA à 20 % porte le TTC à 1,2 fois ce montant.
     *
     * La fiche renvoyée est celle d'APRÈS validation : une facture ne reçoit
     * son numéro définitif qu'à ce moment-là (PROV-… devient FA-…).
     *
     * @return array<string, mixed>
     */
    private function facture(array $g, int $clientId, float $ht, ?string $echeance = null): array
    {
        $brouillon = $this->withToken($g['token'])->postJson('/api/v1/ventes/documents', [
            'type' => 'facture',
            'tiers_id' => $clientId,
            'date_document' => now()->subDays(10)->toDateString(),
            'date_echeance' => $echeance ?? now()->addDays(20)->toDateString(),
            'lignes' => [['designation' => 'Marchandise', 'quantite' => 1, 'prix_unitaire' => $ht, 'tva_rate' => 20]],
        ])->assertCreated()->json('data');

        return $this->withToken($g['token'])
            ->postJson("/api/v1/ventes/documents/{$brouillon['id']}/valider")
            ->assertOk()->json('data');
    }

    private function url(array $g, string $suffixe = ''): string
    {
        return "/api/portail/v1/grossistes/{$g['slug']}/factures".$suffixe;
    }

    /* ---------------------------------------------------------------- */

    public function test_l_acheteur_voit_ses_factures_et_ce_qu_il_reste_a_payer(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $facture = $this->facture($g, $client, 1000); // 1 200 TTC
        $this->withToken($g['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 500, 'mode' => 'especes',
        ])->assertOk();

        $liste = $this->withToken($token)->getJson($this->url($g))->assertOk();

        $liste->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', $facture['code'])
            ->assertJsonPath('data.0.type', 'facture')
            ->assertJsonPath('data.0.total_ttc', '1200.00')
            ->assertJsonPath('data.0.montant_paye', '500.00')
            ->assertJsonPath('data.0.reste_a_payer', '700.00')
            ->assertJsonPath('data.0.etat', 'a_payer')
            ->assertJsonPath('situation.a_payer', '700.00')
            ->assertJsonPath('situation.en_retard', '0.00')
            ->assertJsonPath('situation.nb_factures_ouvertes', 1);
    }

    public function test_une_facture_soldee_est_marquee_payee_et_sort_du_du(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $facture = $this->facture($g, $client, 500); // 600 TTC
        $this->withToken($g['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 600, 'mode' => 'virement',
        ])->assertOk();

        $this->withToken($token)->getJson($this->url($g))->assertOk()
            ->assertJsonPath('data.0.etat', 'payee')
            ->assertJsonPath('data.0.reste_a_payer', '0.00')
            ->assertJsonPath('situation.a_payer', '0.00')
            ->assertJsonPath('situation.nb_factures_ouvertes', 0);
    }

    /**
     * LE point du lot : tant que le grossiste n'a pas validé sa facture, elle
     * n'existe pas. L'annoncer comme une somme due serait faux — il peut encore
     * la corriger, ou la supprimer.
     */
    public function test_une_facture_en_brouillon_n_est_jamais_exposee(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $brouillon = $this->withToken($g['token'])->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client,
            'lignes' => [['designation' => 'Marchandise', 'quantite' => 1, 'prix_unitaire' => 900, 'tva_rate' => 20]],
        ])->assertCreated()->json('data');

        $this->withToken($token)->getJson($this->url($g))->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('situation.a_payer', '0.00');

        $this->withToken($token)->getJson($this->url($g, "/{$brouillon['id']}"))->assertNotFound();
        $this->withToken($token)->get($this->url($g, "/{$brouillon['id']}/pdf"))->assertNotFound();
    }

    public function test_une_facture_echue_est_signalee_en_retard(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $this->facture($g, $client, 2000, now()->subDays(5)->toDateString()); // 2 400 TTC

        $this->withToken($token)->getJson($this->url($g))->assertOk()
            ->assertJsonPath('data.0.etat', 'en_retard')
            ->assertJsonPath('data.0.jours_retard', 5)
            ->assertJsonPath('situation.a_payer', '2400.00')
            ->assertJsonPath('situation.en_retard', '2400.00');
    }

    /**
     * Quand une traite est tirée, la créance quitte la facture pour l'effet,
     * mais la facture garde un reste à payer positif à vie. La compter dans
     * « à payer » réclamerait au client une somme qu'il a déjà réglée.
     */
    public function test_une_facture_couverte_par_une_traite_n_est_plus_a_payer(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $this->withToken($g['token'])->putJson('/api/v1/parametres', ['features' => ['effets' => true]])->assertOk();
        $facture = $this->facture($g, $client, 1000, now()->subDays(3)->toDateString()); // échue

        $this->withToken($token)->getJson($this->url($g))
            ->assertJsonPath('data.0.etat', 'en_retard')
            ->assertJsonPath('situation.a_payer', '1200.00');

        $this->withToken($g['token'])->postJson('/api/v1/effets', [
            'type' => 'recevoir', 'facture_id' => $facture['id'],
            'date_echeance' => now()->addDays(60)->toDateString(),
        ])->assertCreated();

        $this->withToken($token)->getJson($this->url($g))->assertOk()
            ->assertJsonPath('data.0.etat', 'traite_en_cours')
            ->assertJsonPath('data.0.jours_retard', 0)
            ->assertJsonPath('situation.a_payer', '0.00')
            ->assertJsonPath('situation.en_retard', '0.00')
            ->assertJsonPath('situation.sous_traite', '1200.00');
    }

    public function test_un_avoir_est_compte_a_valoir_et_non_comme_une_dette(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $facture = $this->facture($g, $client, 1000); // 1 200 TTC
        $avoir = $this->withToken($g['token'])
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/transformer", ['type' => 'avoir'])
            ->assertOk()->json('data');
        $this->withToken($g['token'])->postJson("/api/v1/ventes/documents/{$avoir['id']}/valider")->assertOk();

        $liste = $this->withToken($token)->getJson($this->url($g))->assertOk();

        $liste->assertJsonPath('meta.total', 2);
        $ligneAvoir = collect($liste->json('data'))->firstWhere('type', 'avoir');

        $this->assertSame('avoir_a_valoir', $ligneAvoir['etat']);
        $liste->assertJsonPath('situation.avoirs_a_valoir', '1200.00')
            ->assertJsonPath('situation.a_payer', '1200.00'); // la facture, pas l'avoir
    }

    public function test_le_detail_montre_les_lignes_et_les_reglements(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $facture = $this->facture($g, $client, 1000);
        $this->withToken($g['token'])->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 300, 'mode' => 'cheque', 'reference' => 'CH-882',
        ])->assertOk();

        $this->withToken($token)->getJson($this->url($g, "/{$facture['id']}"))->assertOk()
            ->assertJsonPath('data.total_ht', '1000.00')
            ->assertJsonPath('data.total_tva', '200.00')
            ->assertJsonPath('data.lignes.0.designation', 'Marchandise')
            ->assertJsonPath('data.lignes.0.montant_ttc', '1200.00')
            ->assertJsonPath('data.paiements.0.montant', '300.00')
            ->assertJsonPath('data.paiements.0.mode', 'cheque')
            ->assertJsonPath('data.paiements.0.reference', 'CH-882');
    }

    public function test_telecharger_le_pdf_de_sa_facture(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $token = $this->acheteur('e@test.ma');
        $client = $this->rattacher($token, $g, 'e@test.ma');

        $facture = $this->facture($g, $client, 1000);

        $reponse = $this->withToken($token)->get($this->url($g, "/{$facture['id']}/pdf"))->assertOk();

        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));
        $this->assertStringContainsString($facture['code'].'.pdf', $reponse->headers->get('content-disposition'));
    }

    /* ---------------------------------------------------------------- */
    /* Isolation — le point critique                                     */
    /* ---------------------------------------------------------------- */

    public function test_un_acheteur_ne_voit_que_ses_propres_factures(): void
    {
        $g = $this->grossiste('Gros Atlas', 'a@gros.ma');

        $t1 = $this->acheteur('un@test.ma', 'Épicerie Un');
        $c1 = $this->rattacher($t1, $g, 'un@test.ma');
        $t2 = $this->acheteur('deux@test.ma', 'Épicerie Deux');
        $this->rattacher($t2, $g, 'deux@test.ma');

        $factureDeUn = $this->facture($g, $c1, 1000);

        // Le second n'a aucune facture, et ne peut pas ouvrir celle du premier.
        $this->withToken($t2)->getJson($this->url($g))->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('situation.a_payer', '0.00');

        $this->withToken($t2)->getJson($this->url($g, "/{$factureDeUn['id']}"))->assertNotFound();
        $this->withToken($t2)->get($this->url($g, "/{$factureDeUn['id']}/pdf"))->assertNotFound();

        $this->withToken($t1)->getJson($this->url($g))->assertJsonPath('meta.total', 1);
    }

    public function test_les_factures_d_un_autre_grossiste_restent_invisibles(): void
    {
        $a = $this->grossiste('Gros Atlas', 'a@gros.ma');
        $b = $this->grossiste('Gros Rif', 'b@gros.ma');

        $token = $this->acheteur('e@test.ma');
        $clientA = $this->rattacher($token, $a, 'e@test.ma');
        $clientB = $this->rattacher($token, $b, 'e@test.ma');

        $this->facture($a, $clientA, 1000);
        $factureB = $this->facture($b, $clientB, 7000);

        // Chez A, l'acheteur ne voit que la facture de A — même si la pièce de
        // B lui appartient aussi : chaque grossiste a son propre périmètre.
        $chezA = $this->withToken($token)->getJson($this->url($a))->assertOk();
        $chezA->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_ttc', '1200.00');

        $this->withToken($token)->getJson($this->url($a, "/{$factureB['id']}"))->assertNotFound();
    }
}
