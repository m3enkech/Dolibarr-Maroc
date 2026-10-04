<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** Bon de livraison PARTIEL de la première ligne d'une commande validée. */
    private function livrer(int $commandeId, float $quantite): array
    {
        $ligneId = $this->withToken($this->token)
            ->getJson("/api/v1/ventes/documents/{$commandeId}")->json('data.lignes.0.id');

        return $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commandeId}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => $quantite]],
        ])->assertSuccessful()->json('data');
    }

    /** Pièce tirée d'une autre, lignes reprises telles quelles. */
    private function transformer(int $sourceId, string $type): array
    {
        return $this->withToken($this->token)
            ->postJson("/api/v1/ventes/documents/{$sourceId}/transformer", ['type' => $type])
            ->assertOk()->json('data');
    }

    /** Brouillon retouché à une autre quantité : facturation partielle. */
    private function retoucher(int $id, float $quantite): void
    {
        $ligne = $this->withToken($this->token)->getJson("/api/v1/ventes/documents/{$id}")->json('data.lignes.0');

        $this->withToken($this->token)->putJson("/api/v1/ventes/documents/{$id}", [
            'lignes' => [[
                'id' => $ligne['id'], 'produit_id' => $ligne['produit_id'],
                'quantite' => $quantite, 'prix_unitaire' => $ligne['prix_unitaire'],
            ]],
        ])->assertOk();
    }

    private function stockDe(int $produitId): float
    {
        return (float) collect($this->withToken($this->token)->getJson('/api/v1/stock/niveaux')->json('data'))
            ->firstWhere('produit_id', $produitId)['quantite'];
    }

    /** Pack de 10 sacs du produit suivi, plus une prestation qui ne stocke rien. */
    private function kit(): array
    {
        $pose = $this->withToken($this->token)->postJson('/api/v1/produits', [
            'name' => 'Pose', 'type' => 'service', 'sell_price' => 100, 'tva_rate' => 20,
        ])->json('data');

        return $this->withToken($this->token)->postJson('/api/v1/produits', [
            'name' => 'Pack chantier', 'type' => 'kit', 'sell_price' => 900, 'tva_rate' => 20,
            'composants' => [
                ['produit_id' => $this->produit['id'], 'quantite' => 10],
                ['produit_id' => $pose['id'], 'quantite' => 1],
            ],
        ])->assertCreated()->json('data');
    }

    /* ---------------------------------------------------------------- */
    /* Livraisons partielles : des BL frères s'ADDITIONNENT */
    /* ---------------------------------------------------------------- */

    /**
     * (a) LE BUG : deux livraisons partielles d'une même commande. Le second BL
     * ne sortait rien — les 6 du premier passaient pour un « déjà sorti » qui
     * couvrait ses 4. Deux BL frères décrivent deux départs de marchandise,
     * pas deux fois le même.
     */
    public function test_a_deux_bl_partiels_freres_sortent_chacun_leur_part(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $this->valider($this->livrer($commande['id'], 6)['id']);
        $this->assertSame(94.0, $this->stock());

        $this->valider($this->livrer($commande['id'], 4)['id']);
        $this->assertSame(90.0, $this->stock(), 'Le second BL sort ses 4 : 6 + 4 = 10 sacs partis.');
        $this->assertSame(2, $this->nbSorties());
    }

    /*
     * (b) BL de 10 puis facture de 10 — depuis la commande ou depuis le BL :
     * test_commande_livree_puis_facturee_ne_sort_le_stock_qu_une_fois et
     * test_chemin_commande_livraison_facture_reste_inchange, plus bas.
     * (d) BL de 6 puis facture de 10 depuis la commande :
     * test_livraison_partielle_puis_facture_totale.
     */

    /** (c) Facture directe depuis la commande, sans aucun BL : elle sort tout. */
    public function test_c_facture_depuis_la_commande_sans_bl(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $this->valider($this->document('facture', 10, $commande['id'])['id']);

        $this->assertSame(90.0, $this->stock());
        $this->assertSame(1, $this->nbSorties());
    }

    /**
     * (e) Deux livraisons partielles puis la facture de la commande entière :
     * les BL ont tout sorti, la facture ne sort rien. L'ancien calcul arrivait
     * aussi à 90 au bout du compte — mais en laissant le second BL sans sortie
     * et en rattrapant à la facture : le stock était faux entre les deux.
     */
    public function test_e_deux_bl_partiels_puis_facture_totale(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $this->valider($this->livrer($commande['id'], 6)['id']);
        $this->valider($this->livrer($commande['id'], 4)['id']);
        $this->assertSame(90.0, $this->stock(), 'Le stock est juste dès la seconde livraison.');

        $this->valider($this->document('facture', 10, $commande['id'])['id']);

        $this->assertSame(90.0, $this->stock(), 'La facture ne sort rien de plus.');
        $this->assertSame(2, $this->nbSorties(), 'Deux sorties : une par BL, aucune pour la facture.');
    }

    /**
     * (f) Chaque livraison partielle est facturée depuis son BL : deux
     * sous-chaînes BL → facture dans la même famille. Avant la correction, le
     * stock restait bloqué à 94.
     */
    public function test_f_chaque_bl_partiel_facture_depuis_son_bl(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $bl1 = $this->livrer($commande['id'], 6);
        $this->valider($bl1['id']);
        $this->valider($this->document('facture', 6, $bl1['id'])['id']);
        $this->assertSame(94.0, $this->stock());

        $bl2 = $this->livrer($commande['id'], 4);
        $this->valider($bl2['id']);
        $this->assertSame(90.0, $this->stock(), 'Le second BL sort ses 4 malgré la facture du premier.');

        $this->valider($this->document('facture', 4, $bl2['id'])['id']);

        $this->assertSame(90.0, $this->stock());
        $this->assertSame(2, $this->nbSorties());
    }

    /**
     * (g) Facturation partielle sans aucun BL : deux factures frères de 6 et de
     * 4 s'additionnent comme deux BL frères.
     */
    public function test_g_deux_factures_partielles_sans_bl(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $f1 = $this->document('facture', 10, $commande['id']);
        $this->retoucher($f1['id'], 6);
        $this->valider($f1['id']);
        $this->assertSame(94.0, $this->stock());

        $f2 = $this->document('facture', 10, $commande['id']);
        $this->retoucher($f2['id'], 4);
        $this->valider($f2['id']);

        $this->assertSame(90.0, $this->stock(), 'La seconde facture sort ses 4.');
        $this->assertSame(2, $this->nbSorties());
    }

    /**
     * (h) Une facture PLUS PETITE que ce qui est déjà livré ne sort rien : elle
     * facture une marchandise déjà partie.
     */
    public function test_h_bl_de_6_puis_facture_de_4(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $this->valider($this->livrer($commande['id'], 6)['id']);

        $facture = $this->document('facture', 10, $commande['id']);
        $this->retoucher($facture['id'], 4);
        $this->valider($facture['id']);

        $this->assertSame(94.0, $this->stock(), 'Seuls les 6 livrés sont partis.');
        $this->assertSame(1, $this->nbSorties());
    }

    /**
     * (i) La racine est le DEVIS : sa commande livrée en deux fois, et une
     * facture tirée directement du devis — trois branches, une famille.
     */
    public function test_i_racine_devis_commande_livree_en_deux_et_facture_du_devis(): void
    {
        $devis = $this->document('devis', 10);
        $this->valider($devis['id']);

        $commande = $this->document('commande', 10, $devis['id']);
        $this->valider($commande['id']);

        $this->valider($this->livrer($commande['id'], 6)['id']);
        $this->valider($this->livrer($commande['id'], 4)['id']);
        $this->assertSame(90.0, $this->stock(), 'Les deux livraisons petites-filles du devis s\'additionnent.');

        $this->valider($this->document('facture', 10, $devis['id'])['id']);

        $this->assertSame(90.0, $this->stock(), 'La facture du devis couvre la marchandise livrée via la commande.');
        $this->assertSame(2, $this->nbSorties());
    }

    /**
     * (j) Piège n° 1 : un BL DIRECT, sans commande, est lui-même la racine. Sa
     * facture ne doit rien ressortir.
     */
    public function test_j_bl_direct_sans_commande_puis_facture(): void
    {
        $bl = $this->document('bon_livraison', 10);
        $this->valider($bl['id']);
        $this->assertSame(90.0, $this->stock());

        $this->valider($this->document('facture', 10, $bl['id'])['id']);

        $this->assertSame(90.0, $this->stock());
        $this->assertSame(1, $this->nbSorties());
    }

    /**
     * (k) Un kit livré en deux fois : ce sont ses composants qui sortent, et les
     * deux BL s'additionnent aussi au niveau des composants.
     */
    public function test_k_kit_livre_en_deux_bl_partiels(): void
    {
        $kit = $this->kit();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $kit['id'], 'quantite' => 2]],
        ])->assertCreated()->json('data');
        $this->valider($commande['id']);

        $this->valider($this->livrer($commande['id'], 1)['id']);
        $this->assertSame(90.0, $this->stock(), 'Un kit = 10 sacs.');

        $this->valider($this->livrer($commande['id'], 1)['id']);
        $this->assertSame(80.0, $this->stock(), 'Le second kit livré sort ses 10 sacs.');

        $this->valider($this->document('facture', 2, $commande['id'])['id']);

        $this->assertSame(80.0, $this->stock(), 'La facture des deux kits ne sort rien de plus.');
    }

    /**
     * (k) Kit RECOMPOSÉ entre la livraison et la facture (10 sacs → 12). Le BL a
     * sorti 10 ; la facture du même kit ne doit rien sortir. L'ancien calcul
     * comparait les 12 d'aujourd'hui aux 10 déjà sortis et sortait la
     * différence. Le bilan, lui, relit les deux pièces avec la même composition.
     */
    public function test_k_kit_recompose_entre_bl_et_facture(): void
    {
        $kit = $this->kit();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $kit['id'], 'quantite' => 1]],
        ])->assertCreated()->json('data');
        $this->valider($commande['id']);

        $this->valider($this->livrer($commande['id'], 1)['id']);
        $this->assertSame(90.0, $this->stock());

        $this->withToken($this->token)->putJson("/api/v1/produits/{$kit['id']}", [
            'composants' => [['produit_id' => $this->produit['id'], 'quantite' => 12]],
        ])->assertOk();

        $this->valider($this->transformer($commande['id'], 'facture')['id']);

        $this->assertSame(90.0, $this->stock(), 'La facture d\'un kit déjà livré ne sort rien.');
    }

    /**
     * (k) Un kit et l'un de ses composants dans la même famille : la commande
     * porte 1 kit (10 sacs) + 3 sacs, on ne livre que le kit, puis on facture
     * la commande entière. La facture ne sort que les 3 sacs à l'unité — la part
     * couverte (10) se consomme sur la ligne du kit, pas deux fois.
     */
    public function test_k_kit_et_composant_dans_la_meme_famille(): void
    {
        $kit = $this->kit();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [
                ['produit_id' => $kit['id'], 'quantite' => 1],
                ['produit_id' => $this->produit['id'], 'quantite' => 3],
            ],
        ])->assertCreated()->json('data');
        $this->valider($commande['id']);

        $lignes = $this->withToken($this->token)
            ->getJson("/api/v1/ventes/documents/{$commande['id']}")->json('data.lignes');

        $blKit = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $lignes[0]['id'], 'quantite' => 1]],
        ])->assertSuccessful()->json('data');
        $this->valider($blKit['id']);
        $this->assertSame(90.0, $this->stock());

        $this->valider($this->transformer($commande['id'], 'facture')['id']);
        $this->assertSame(87.0, $this->stock(), 'La facture sort les 3 sacs que personne n\'a livrés.');

        $blSacs = $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $lignes[1]['id'], 'quantite' => 3]],
        ])->assertSuccessful()->json('data');
        $this->valider($blSacs['id']);

        $this->assertSame(87.0, $this->stock(), 'La livraison des 3 sacs déjà facturés ne ressort rien.');
    }

    /**
     * (l) Avoir AVEC retour après une livraison, puis nouvelle livraison. Les
     * 6 livrés reviennent ; le BL suivant sort ses 4 — pas 10 (l'avoir
     * rouvrirait une double sortie), pas 0 (il bloquerait une vraie livraison).
     * L'ancien calcul s'arrêtait à 100 : les 6 sortis par le premier BL
     * passaient pour un crédit, alors que l'avoir les avait fait rentrer.
     */
    public function test_l_avoir_apres_livraison_puis_nouvelle_livraison(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $bl1 = $this->livrer($commande['id'], 6);
        $this->valider($bl1['id']);
        $facture = $this->document('facture', 6, $bl1['id']);
        $this->valider($facture['id']);
        $this->assertSame(94.0, $this->stock());

        $this->valider($this->document('avoir', 6, $facture['id'])['id']);
        $this->assertSame(100.0, $this->stock(), 'Les 6 sont revenus.');

        $bl2 = $this->livrer($commande['id'], 4);
        $this->valider($bl2['id']);
        $this->assertSame(96.0, $this->stock(), 'Seuls les 4 de la nouvelle livraison sont dehors.');

        $this->valider($this->document('facture', 4, $bl2['id'])['id']);
        $this->assertSame(96.0, $this->stock(), 'Sa facture ne ressort rien.');
    }

    /**
     * (l) Tout est rendu, puis une livraison de REMPLACEMENT part depuis le
     * devis : elle doit sortir, et sa facture ne rien ressortir.
     */
    public function test_l_avoir_total_puis_livraison_de_remplacement(): void
    {
        $devis = $this->document('devis', 10);
        $this->valider($devis['id']);
        $commande = $this->document('commande', 10, $devis['id']);
        $this->valider($commande['id']);

        $bl = $this->livrer($commande['id'], 10);
        $this->valider($bl['id']);
        $facture = $this->document('facture', 10, $bl['id']);
        $this->valider($facture['id']);
        $this->valider($this->document('avoir', 10, $facture['id'])['id']);
        $this->assertSame(100.0, $this->stock(), 'Marchandise défectueuse rendue.');

        $remplacement = $this->document('bon_livraison', 10, $devis['id']);
        $this->valider($remplacement['id']);
        $this->assertSame(90.0, $this->stock(), 'La livraison de remplacement sort.');

        $this->valider($this->document('facture', 10, $remplacement['id'])['id']);
        $this->assertSame(90.0, $this->stock(), 'Sa facture ne ressort rien.');
    }

    /**
     * (l) L'ORDRE compte : facture faite avant la livraison, annulée par un
     * avoir (qui fait rentrer ce qu'elle avait sorti), PUIS livraison. La
     * marchandise part réellement : le BL doit sortir. Une règle qui
     * additionnerait sans tenir compte de l'ordre (« le plus grand des cumuls
     * BL et factures ») verrait 10 facturés pour 10 livrés et ne sortirait rien.
     */
    public function test_l_facture_annulee_par_avoir_avant_la_livraison(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);
        $this->valider($this->document('avoir', 10, $facture['id'])['id']);
        $this->assertSame(100.0, $this->stock());

        $this->valider($this->livrer($commande['id'], 10)['id']);
        $this->assertSame(90.0, $this->stock(), 'La livraison faite après l\'annulation sort.');

        $this->valider($this->document('facture', 10, $commande['id'])['id']);
        $this->assertSame(90.0, $this->stock(), 'La facture refaite ne ressort rien.');
    }

    /**
     * (l) Retour d'une marchandise LIVRÉE alors que la facture couvre plus que
     * le livré, puis livraison du reliquat. L'avoir rend 2 des 6 sacs partis :
     * il s'impute d'abord sur le livré. L'imputer sur le facturé non livré
     * laissait le livré à 6, et le reliquat de 4 sortait 2 de trop (90) —
     * sans rattrapage possible, tout étant déjà facturé.
     */
    public function test_l_avoir_d_une_partie_livree_puis_reliquat(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $this->valider($this->livrer($commande['id'], 6)['id']);
        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);
        $this->assertSame(90.0, $this->stock(), '6 livrés + 4 facturés en attente de livraison.');

        $avoir = $this->transformer($facture['id'], 'avoir');
        $this->retoucher($avoir['id'], 2);
        $this->valider($avoir['id']);
        $this->assertSame(92.0, $this->stock(), 'Les 2 sacs rendus reviennent.');

        $this->valider($this->livrer($commande['id'], 4)['id']);
        $this->assertSame(92.0, $this->stock(), '100 − 6 + 2 − 4 : le reliquat était déjà sorti par la facture.');
    }

    /** Même règle, facture d'abord : les 6 livrés reviennent tous, puis le reliquat part. */
    public function test_l_facture_puis_livraison_partielle_rendue_puis_reliquat(): void
    {
        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);

        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);
        $this->valider($this->livrer($commande['id'], 6)['id']);
        $this->assertSame(90.0, $this->stock());

        $avoir = $this->transformer($facture['id'], 'avoir');
        $this->retoucher($avoir['id'], 6);
        $this->valider($avoir['id']);
        $this->assertSame(96.0, $this->stock());

        $this->valider($this->livrer($commande['id'], 4)['id']);
        $this->assertSame(96.0, $this->stock(), 'Les 4 du reliquat restent facturés : ils étaient déjà sortis.');
    }

    /**
     * L'ordre du rejeu est celui des VALIDATIONS, pas celui de la création des
     * brouillons ni l'horodatage à la seconde. Ici le BL est préparé AVANT
     * l'avoir mais validé APRÈS lui, dans la même seconde : trié par
     * (validated_at, id), le rejeu plaçait le BL avant l'avoir, et la facture
     * refaite ressortait 10 sacs déjà partis (80).
     */
    public function test_rejeu_dans_l_ordre_des_validations_et_non_des_brouillons(): void
    {
        $this->freezeSecond();

        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);
        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);

        $bl = $this->livrer($commande['id'], 10);
        $avoir = $this->transformer($facture['id'], 'avoir');

        $this->valider($avoir['id']);
        $this->assertSame(100.0, $this->stock(), 'Facture annulée : rien n\'est dehors.');
        $this->valider($bl['id']);
        $this->assertSame(90.0, $this->stock(), 'La livraison faite après l\'annulation sort.');

        $this->valider($this->document('facture', 10, $commande['id'])['id']);
        $this->assertSame(90.0, $this->stock(), 'La facture refaite ne ressort rien.');
    }

    /**
     * Symétrique : l'avoir préparé AVANT le BL mais validé APRÈS lui. Trié par
     * création, l'avoir passait avant le BL et la facture refaite ne sortait
     * rien (100) alors que la marchandise rendue est à nouveau vendue.
     */
    public function test_rejeu_avoir_prepare_avant_le_bl_mais_valide_apres(): void
    {
        $this->freezeSecond();

        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);
        $facture = $this->document('facture', 10, $commande['id']);
        $this->valider($facture['id']);

        $avoir = $this->transformer($facture['id'], 'avoir');
        $bl = $this->livrer($commande['id'], 10);

        $this->valider($bl['id']);
        $this->assertSame(90.0, $this->stock(), 'Livraison couverte par la facture.');
        $this->valider($avoir['id']);
        $this->assertSame(100.0, $this->stock(), 'Marchandise rendue.');

        $this->valider($this->document('facture', 10, $commande['id'])['id']);
        $this->assertSame(90.0, $this->stock(), 'Revendue : elle sort à nouveau.');
    }

    /**
     * LIMITE CONNUE, figée pour qu'un changement soit délibéré. Les pièces sont
     * relues avec la composition ACTUELLE du kit. Facturé (10 sacs sortis), puis
     * recomposé à 12, puis livré : le BL emporte réellement 12 sacs (88), mais
     * le rejeu relit la facture à 12 et ne sort rien (90). L'ordre inverse
     * (test_k_kit_recompose_entre_bl_et_facture), le plus courant, est juste ;
     * figer la composition à la validation inverserait simplement le défaut.
     */
    public function test_limite_kit_recompose_entre_facture_et_bl(): void
    {
        $kit = $this->kit();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $kit['id'], 'quantite' => 1]],
        ])->assertCreated()->json('data');
        $this->valider($commande['id']);

        $this->valider($this->transformer($commande['id'], 'facture')['id']);
        $this->assertSame(90.0, $this->stock());

        $this->withToken($this->token)->putJson("/api/v1/produits/{$kit['id']}", [
            'composants' => [['produit_id' => $this->produit['id'], 'quantite' => 12]],
        ])->assertOk();

        $this->valider($this->livrer($commande['id'], 1)['id']);
        $this->assertSame(90.0, $this->stock(), 'Limite : 88 en réalité, 2 sacs de trop au stock affiché.');
    }

    /**
     * LIMITE CONNUE, figée : un devis transformé en DEUX commandes forme une
     * seule famille. Le BL de la seconde est couvert par la facture de la
     * première jusqu'à ce que la seconde soit facturée à son tour — le stock
     * affiché reste trop haut entre les deux, puis se rattrape. Une famille
     * découpée par commande casserait la facture tirée du devis (test i).
     */
    public function test_limite_devis_transforme_en_deux_commandes(): void
    {
        $devis = $this->document('devis', 10);
        $this->valider($devis['id']);
        $c1 = $this->document('commande', 10, $devis['id']);
        $this->valider($c1['id']);
        $c2 = $this->document('commande', 10, $devis['id']);
        $this->valider($c2['id']);

        $this->valider($this->transformer($c1['id'], 'facture')['id']);
        $this->assertSame(90.0, $this->stock(), 'C1 retirée au comptoir, sans BL.');

        $this->valider($this->livrer($c2['id'], 10)['id']);
        $this->assertSame(90.0, $this->stock(), 'Limite : 80 en réalité, la sortie attend la facture de C2.');

        $this->valider($this->transformer($c2['id'], 'facture')['id']);
        $this->assertSame(80.0, $this->stock(), 'Rattrapé à la facture de C2.');
    }

    /**
     * (m) Vente au colis : la commande porte 2 cartons de 12, livrés un carton
     * à la fois. Les quantités de la famille sont en UNITÉS de stock.
     */
    public function test_m_vente_au_colis_livree_en_deux_fois(): void
    {
        $carton = $this->withToken($this->token)->postJson('/api/v1/produits', [
            'name' => 'Bouteille 1L', 'type' => 'product', 'sell_price' => 10, 'tva_rate' => 20,
        ])->json('data');

        $conditionnementId = $this->withToken($this->token)->postJson("/api/v1/produits/{$carton['id']}/conditionnements", [
            'nom' => 'Carton de 12', 'quantite_base' => 12,
        ])->assertCreated()->json('data.0.id');

        $entrepotId = $this->withToken($this->token)->getJson('/api/v1/stock/entrepots')->json('data.0.id');
        $this->withToken($this->token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $carton['id'], 'entrepot_id' => $entrepotId, 'type' => 'entree', 'quantite' => 100,
        ])->assertCreated();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $carton['id'], 'conditionnement_id' => $conditionnementId, 'quantite_colis' => 2]],
        ])->assertCreated()->json('data');
        $this->valider($commande['id']);

        $bl1 = $this->livrer($commande['id'], 12);
        $this->assertSame('1.000', $bl1['lignes'][0]['quantite_colis'], 'Un carton part.');
        $this->valider($bl1['id']);
        $this->assertSame(88.0, $this->stockDe($carton['id']));

        $this->valider($this->livrer($commande['id'], 12)['id']);
        $this->assertSame(76.0, $this->stockDe($carton['id']), 'Le second carton sort ses 12 unités.');

        $this->valider($this->transformer($commande['id'], 'facture')['id']);
        $this->assertSame(76.0, $this->stockDe($carton['id']), 'La facture des 2 cartons ne sort rien de plus.');
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

    /**
     * Piège n° 4 : une pièce SANS source (ticket de caisse, facture directe)
     * n'a pas de famille et ne paie aucune requête de famille. Témoin : la même
     * validation depuis une commande, elle, parcourt la descendance.
     */
    public function test_piece_sans_source_ne_cherche_pas_de_famille(): void
    {
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes) {
            $requetes[] = $requete->sql;
        });
        // Par référence : une fonction fléchée figerait la liste vide du départ.
        $parcourtLaFamille = function () use (&$requetes): bool {
            return collect($requetes)
                ->contains(fn (string $sql) => preg_match('/source_document_id"?\s+in\s*\(/i', $sql) === 1);
        };

        $this->valider($this->document('facture', 10)['id']);
        $this->assertFalse($parcourtLaFamille(), 'Facture directe : aucune requête de famille.');

        $commande = $this->document('commande', 10);
        $this->valider($commande['id']);
        $requetes = [];
        $this->valider($this->document('facture', 10, $commande['id'])['id']);
        $this->assertTrue($parcourtLaFamille(), 'Témoin : issue d\'une commande, elle lit sa famille.');
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
