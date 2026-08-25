<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une marchandise ne sort du stock QU'UNE FOIS.
 *
 * Un même besoin client peut être décrit par plusieurs documents — une commande,
 * un ou plusieurs bons de livraison, une facture — et deux d'entre eux peuvent
 * chacun déclencher une sortie. Le garde-fou historique ne regardait que la
 * source DIRECTE de la facture : il ratait le cas où le bon de livraison et la
 * facture sont deux FRÈRES issus de la même commande.
 */
class SortieStockUniqueTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private array $produit;

    private array $tiers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Gros Atlas', 'name' => 'Patron', 'email' => 'a@gros.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');

        $this->produit = $this->withToken($this->token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ])->json('data');

        $this->tiers = $this->withToken($this->token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');

        // 100 en stock au départ, dans l'entrepôt par défaut.
        $entrepot = $this->withToken($this->token)
            ->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');

        $this->withToken($this->token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $this->produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'entree', 'quantite' => 100,
        ])->assertCreated();
    }

    /** @return array<string, mixed> */
    private function document(string $type, float $quantite, ?int $sourceId = null): array
    {
        if ($sourceId !== null) {
            return $this->withToken($this->token)
                ->postJson("/api/v1/ventes/documents/{$sourceId}/transformer", ['type' => $type])
                ->assertOk()->json('data');
        }

        return $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => $type, 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $this->produit['id'], 'quantite' => $quantite]],
        ])->assertCreated()->json('data');
    }

    private function valider(int $id): void
    {
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$id}/valider")->assertOk();
    }

    private function stock(): float
    {
        return (float) $this->withToken($this->token)->getJson('/api/v1/stock/niveaux')->json('data.0.quantite');
    }

    private function nbSorties(): int
    {
        return collect($this->withToken($this->token)->getJson('/api/v1/stock/mouvements')->json('data'))
            ->where('type', 'vente')
            ->count();
    }

    /* ---------------------------------------------------------------- */

    /** Le cas qui a motivé la correction : deux frères issus d'une même commande. */
    public function test_commande_livree_puis_facturee_ne_sort_le_stock_qu_une_fois(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $bl = $this->document('bon_livraison', 10, $commande['id']);
        $this->valider($bl['id']);
        $this->assertSame(90.0, $this->stock(), 'La livraison sort la marchandise.');

        // La facture est faite depuis la COMMANDE, pas depuis le bon de livraison.
        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);

        $this->assertSame(90.0, $this->stock(), 'La facture ne doit rien sortir de plus.');
        $this->assertSame(1, $this->nbSorties());
    }

    /** L'ordre inverse doit donner le même résultat. */
    public function test_commande_facturee_puis_livree_ne_sort_le_stock_qu_une_fois(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);
        $this->assertSame(90.0, $this->stock(), 'Sans livraison, la facture sort la marchandise.');

        $bl = $this->document('bon_livraison', 10, $commande['id']);
        $this->valider($bl['id']);

        $this->assertSame(90.0, $this->stock(), 'La livraison ne doit rien sortir de plus.');
        $this->assertSame(1, $this->nbSorties());
    }

    /**
     * Livraison partielle : la facture solde ce qui n'est pas encore parti, sans
     * ressortir ce qui l'est déjà.
     */
    public function test_livraison_partielle_puis_facture_totale(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $ligneId = $this->withToken($this->token)
            ->getJson("/api/v1/ventes/documents/{$commande['id']}")->json('data.lignes.0.id');

        $bl = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 6]],
        ])->assertSuccessful()->json('data');
        $this->valider($bl['id']);
        $this->assertSame(94.0, $this->stock(), '6 sont partis.');

        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);

        $this->assertSame(90.0, $this->stock(), 'La facture ne sort que les 4 restants.');
    }

    /** Le chemin sain ne doit pas changer de comportement. */
    public function test_chemin_commande_livraison_facture_reste_inchange(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $bl = $this->document('bon_livraison', 10, $commande['id']);
        $this->valider($bl['id']);

        // Facture faite depuis le BL : c'est le chemin normal.
        $facture = $this->document('facture', 10, $bl['id']);
        $this->valider($facture['id']);

        $this->assertSame(90.0, $this->stock());
        $this->assertSame(1, $this->nbSorties());
    }

    /** Une facture sans aucune source sort bien la marchandise. */
    public function test_facture_directe_sort_le_stock(): void
    {
        $facture = $this->document('facture', 10);
        $this->valider($facture['id']);

        $this->assertSame(90.0, $this->stock());
        $this->assertSame(1, $this->nbSorties());
    }

    /** Un devis aussi peut engendrer deux frères : même règle. */
    public function test_devis_livre_puis_facture_ne_sort_le_stock_qu_une_fois(): void
    {
        $devis = $this->document('devis', 10);
        $this->valider($devis['id']);

        $bl = $this->document('bon_livraison', 10, $devis['id']);
        $this->valider($bl['id']);

        $facture = $this->document('facture', 10, $devis['id']);
        $this->valider($facture['id']);

        $this->assertSame(90.0, $this->stock());
        $this->assertSame(1, $this->nbSorties());
    }

    /**
     * Le solde livré puis le reliquat : trois documents, une seule marchandise.
     */
    public function test_partielle_puis_facture_totale_puis_reliquat(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $ligneId = $this->withToken($this->token)
            ->getJson("/api/v1/ventes/documents/{$commande['id']}")->json('data.lignes.0.id');

        $bl1 = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 4]],
        ])->assertSuccessful()->json('data');
        $this->valider($bl1['id']);

        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);

        $bl2 = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => 6]],
        ])->assertSuccessful()->json('data');
        $this->valider($bl2['id']);

        $this->assertSame(90.0, $this->stock(), 'Au total, 10 sacs sont sortis — pas 20.');
    }

    /**
     * PIÈGE DE LA CORRECTION elle-même : deux lignes du MÊME article sur une
     * seule pièce. Si le crédit « déjà sorti » était relu à chaque ligne, la
     * seconde croirait que la première l'a déjà couverte et ne sortirait rien.
     */
    public function test_deux_lignes_du_meme_produit_sortent_les_deux(): void
    {
        $facture = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $this->tiers['id'],
            'lignes' => [
                ['produit_id' => $this->produit['id'], 'quantite' => 5, 'prix_unitaire' => 85],
                ['produit_id' => $this->produit['id'], 'quantite' => 5, 'prix_unitaire' => 78],
            ],
        ])->assertCreated()->json('data');

        $this->valider($facture['id']);

        $this->assertSame(90.0, $this->stock(), 'Les deux lignes sortent : 5 + 5.');
    }

    /**
     * Même piège, par la porte des kits : un kit et l'un de ses composants sur
     * la même pièce visent le même produit de stock.
     */
    public function test_un_kit_et_son_composant_sur_la_meme_piece(): void
    {
        $kit = $this->withToken($this->token)->postJson('/api/v1/produits', [
            'name' => 'Pack chantier', 'type' => 'kit', 'sell_price' => 900, 'tva_rate' => 20,
            'composants' => [['produit_id' => $this->produit['id'], 'quantite' => 10]],
        ])->assertCreated()->json('data');

        $facture = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $this->tiers['id'],
            'lignes' => [
                ['produit_id' => $kit['id'], 'quantite' => 1],
                ['produit_id' => $this->produit['id'], 'quantite' => 3],
            ],
        ])->assertCreated()->json('data');

        $this->valider($facture['id']);

        $this->assertSame(87.0, $this->stock(), '10 sacs pour le kit, plus 3 à l\'unité.');
    }

    /**
     * L'avoir reste symétrique : il fait rentrer la marchandise UNE fois, quel
     * que soit le chemin par lequel elle était sortie.
     */
    public function test_avoir_fait_rentrer_la_marchandise_une_seule_fois(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $bl = $this->document('bon_livraison', 10, $commande['id']);
        $this->valider($bl['id']);

        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);
        $this->assertSame(90.0, $this->stock());

        $avoir = $this->document('avoir', 10, $facture['id']);
        $this->valider($avoir['id']);

        $this->assertSame(100.0, $this->stock(), 'La marchandise revient, une seule fois.');
    }
}
