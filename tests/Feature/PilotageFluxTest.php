<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Compteurs de l'écran de suivi, sur un décor connu.
 */
class PilotageFluxTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Admin',
            'email' => 'admin@acme.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    private function tiers(string $token, string $nom = 'Client A'): array
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => $nom, 'is_client' => true,
        ])->assertCreated()->json('data');
    }

    /** Crée un document de vente, et le valide si demandé. */
    private function document(string $token, string $type, int $tiersId, array $lignes, bool $valider = true, array $extra = []): array
    {
        $doc = $this->withToken($token)->postJson('/api/v1/ventes/documents', array_merge([
            'type' => $type, 'tiers_id' => $tiersId, 'lignes' => $lignes,
        ], $extra))->assertCreated()->json('data');

        if (! $valider) {
            return $doc;
        }

        return $this->withToken($token)
            ->postJson("/api/v1/ventes/documents/{$doc['id']}/valider")
            ->assertOk()->json('data');
    }

    private function flux(string $token): array
    {
        return $this->withToken($token)->getJson('/api/v1/pilotage/flux')->assertOk()->json('data');
    }

    private function ligne(float $quantite = 1, float $prix = 100): array
    {
        return ['designation' => 'Prestation', 'quantite' => $quantite, 'prix_unitaire' => $prix, 'tva_rate' => 20];
    }

    /* ---------------------------------------------------------------- */

    public function test_les_compteurs_de_vente_reflètent_le_decor(): void
    {
        $token = $this->registerTenant();
        $client = $this->tiers($token);

        // Deux devis validés en attente de réponse.
        $this->document($token, 'devis', $client['id'], [$this->ligne()]);
        $this->document($token, 'devis', $client['id'], [$this->ligne()]);

        // Une commande validée : le carnet ferme.
        $this->document($token, 'commande', $client['id'], [$this->ligne(10)]);

        // Un brouillon de commande, qui n'engage rien.
        $this->document($token, 'commande', $client['id'], [$this->ligne()], valider: false);

        $ventes = $this->flux($token)['ventes'];

        $this->assertSame(2, $ventes['devis_en_attente']['count']);
        $this->assertSame(1, $ventes['commandes_ouvertes']['count']);
        $this->assertSame(1, $ventes['brouillons']['count']);
    }

    /**
     * Une facture soldée bascule en `paye` : elle ne doit donc plus compter
     * parmi les impayées. C'est ce qui permet de mesurer l'impayé sans joindre
     * les règlements.
     */
    public function test_une_facture_reglee_sort_des_impayees(): void
    {
        $token = $this->registerTenant();
        $client = $this->tiers($token);

        $facture = $this->document($token, 'facture', $client['id'], [$this->ligne(1, 1000)]);
        $this->document($token, 'facture', $client['id'], [$this->ligne(1, 500)]);

        $avant = $this->flux($token)['ventes']['factures_impayees'];
        $this->assertSame(2, $avant['count']);
        $this->assertSame('1800.00', $avant['reste'], '1000 + 500 HT, TVA 20 %.');

        // On solde la première.
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'especes',
        ])->assertOk();

        $apres = $this->flux($token)['ventes']['factures_impayees'];
        $this->assertSame(1, $apres['count'], 'La facture soldée ne compte plus.');
        $this->assertSame('600.00', $apres['reste']);
    }

    public function test_une_facture_echue_est_comptee_a_part(): void
    {
        $token = $this->registerTenant();
        $client = $this->tiers($token);

        // Échue hier.
        $this->document($token, 'facture', $client['id'], [$this->ligne()], extra: [
            'date_document' => now()->subDays(40)->toDateString(),
            'date_echeance' => now()->subDay()->toDateString(),
        ]);

        // Échéance à venir.
        $this->document($token, 'facture', $client['id'], [$this->ligne()], extra: [
            'date_echeance' => now()->addDays(30)->toDateString(),
        ]);

        $ventes = $this->flux($token)['ventes'];

        $this->assertSame(2, $ventes['factures_impayees']['count']);
        $this->assertSame(1, $ventes['factures_echues']['count'], 'Seule celle dont la date est passée.');
    }

    public function test_le_resume_du_stock_compte_ruptures_et_seuils(): void
    {
        $token = $this->registerTenant();

        // Sous son seuil, et à zéro : compte dans les deux.
        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
            'buy_price' => 60, 'stock_min' => 10,
        ])->assertCreated();

        // Sans seuil et sans stock : en rupture, mais pas « sous seuil ».
        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Sable', 'type' => 'product', 'sell_price' => 20, 'tva_rate' => 20,
        ])->assertCreated();

        // Un service : hors périmètre du stock.
        $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Pose', 'type' => 'service', 'sell_price' => 300, 'tva_rate' => 20,
        ])->assertCreated();

        $stock = $this->flux($token)['stock'];

        $this->assertSame(2, $stock['references_actives'], 'Le service ne compte pas.');
        $this->assertSame(2, $stock['en_rupture']);
        $this->assertSame(1, $stock['sous_seuil'], 'Seul celui qui porte un seuil.');
    }

    /**
     * LA garde contre le retour d'un N+1.
     *
     * L'état de livraison d'une commande n'est pas stocké : la méthode qui le
     * calcule charge les lignes du document. L'appeler sur une liste coûterait
     * une requête par commande. On mesure donc le même appel sur deux décors de
     * tailles très différentes et on exige le MÊME nombre de requêtes.
     */
    public function test_le_cout_ne_croit_pas_avec_le_nombre_de_documents(): void
    {
        $token = $this->registerTenant();
        $client = $this->tiers($token);

        $this->document($token, 'commande', $client['id'], [$this->ligne(5)]);

        $requetes = function () use ($token): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->withToken($token)->getJson('/api/v1/pilotage/flux')->assertOk();
            $compte = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $compte;
        };

        $avant = $requetes();

        // Quatorze commandes de plus, trois lignes chacune.
        for ($i = 0; $i < 14; $i++) {
            $this->document($token, 'commande', $client['id'], [
                $this->ligne(5), $this->ligne(3), $this->ligne(2),
            ]);
        }

        $apres = $requetes();

        $this->assertSame(
            $avant,
            $apres,
            "Le coût des compteurs a augmenté avec le décor : {$avant} requêtes puis {$apres}. C'est un N+1.",
        );
    }
}
