<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Rouvrir un brouillon et l'enregistrer ne doit rien lui faire perdre.
 *
 * Le serveur SUPPRIME puis RECRÉE les lignes à chaque enregistrement : tout ce
 * que le formulaire ne renvoie pas disparaît. Le formulaire de vente ne
 * renvoyait ni le lien vers la ligne de commande (`source_ligne_id`) ni le
 * colis (`conditionnement_id`, `quantite_colis`), et le serveur les aurait de
 * toute façon écartés (aucune règle de validation, rien d'écrit par
 * syncLignes). Conséquences :
 * - un bon de livraison retouché, une fois validé, ne soldait plus la
 *   commande : elle réclamait une seconde livraison de ce qui était parti ;
 * - une vente au carton revenait en pièces (« 5 × Carton de 12 » disparaissait
 *   de la facture imprimée).
 *
 * `payloadDuFormulaire()` reproduit champ pour champ ce que VenteForm envoie
 * pour un brouillon rouvert : c'est CE corps-là que les tests font passer.
 */
class RetoucheBrouillonTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private array $produit;

    private array $tiers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->inscrire('Gros Atlas', 'a@gros.ma');
        [$this->produit, $this->tiers] = $this->catalogue($this->token);

        // 100 sacs en stock au départ.
        $entrepot = $this->withToken($this->token)
            ->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');

        $this->withToken($this->token)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $this->produit['id'], 'entrepot_id' => $entrepot['id'],
            'type' => 'entree', 'quantite' => 100,
        ])->assertCreated();
    }

    private function inscrire(string $societe, string $email): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'company_name' => $societe, 'name' => 'Patron', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function catalogue(string $token): array
    {
        $produit = $this->withToken($token)->postJson('/api/v1/produits', [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ])->assertCreated()->json('data');

        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client X'])->json('data');

        return [$produit, $tiers];
    }

    /** Commande de $quantite sacs, validée. */
    private function commandeValidee(float $quantite = 10, ?string $token = null): array
    {
        $token ??= $this->token;
        [$produit, $tiers] = $token === $this->token ? [$this->produit, $this->tiers] : $this->catalogue($token);

        $commande = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $tiers['id'],
            'lignes' => [['produit_id' => $produit['id'], 'quantite' => $quantite]],
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$commande['id']}/valider")->assertOk();

        return $this->lire($commande['id'], $token);
    }

    private function transformer(int $sourceId, string $type): array
    {
        return $this->withToken($this->token)
            ->postJson("/api/v1/ventes/documents/{$sourceId}/transformer", ['type' => $type])
            ->assertOk()->json('data');
    }

    private function livrer(array $commande, float $quantite, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->token)->postJson("/api/v1/ventes/documents/{$commande['id']}/livrer", [
            'lignes' => [['source_ligne_id' => $commande['lignes'][0]['id'], 'quantite' => $quantite]],
        ]);
    }

    private function lire(int $id, ?string $token = null): array
    {
        return $this->withToken($token ?? $this->token)
            ->getJson("/api/v1/ventes/documents/{$id}")->assertOk()->json('data');
    }

    private function valider(int $id): void
    {
        $this->withToken($this->token)->postJson("/api/v1/ventes/documents/{$id}/valider")->assertOk();
    }

    private function enregistrer(int $id, array $payload): TestResponse
    {
        return $this->withToken($this->token)->putJson("/api/v1/ventes/documents/{$id}", $payload);
    }

    /**
     * Le corps que VenteForm envoie pour un brouillon rouvert : handleSubmit,
     * alimenté par show(), quantités inchangées (le colis repart alors tel
     * qu'il est venu — voir colisDeLigne).
     *
     * @return array<string, mixed>
     */
    private function payloadDuFormulaire(array $document): array
    {
        return [
            'tiers_id' => $document['tiers_id'],
            'date_document' => $document['date_document'],
            'date_echeance' => $document['date_echeance'],
            'notes' => $document['notes'],
            'lignes' => array_map(function (array $l) {
                $auColis = $l['conditionnement_id'] && $l['quantite_colis'] && ($l['conditionnement_quantite_base'] ?? null);

                return [
                    'id' => $l['id'],
                    'source_ligne_id' => $l['source_ligne_id'],
                    'produit_id' => $l['produit_id'],
                    'designation' => $l['designation'],
                    'quantite' => (float) $l['quantite'],
                    'conditionnement_id' => $auColis ? $l['conditionnement_id'] : null,
                    'quantite_colis' => $auColis ? (float) $l['quantite_colis'] : null,
                    'prix_unitaire' => (float) $l['prix_unitaire'],
                    'remise_percent' => (float) $l['remise_percent'],
                    'tva_rate' => (float) $l['tva_rate'],
                ];
            }, $document['lignes']),
        ];
    }

    /** Stock de l'article du test (le ciment), même quand d'autres articles existent. */
    private function stock(): float
    {
        return (float) collect($this->withToken($this->token)->getJson('/api/v1/stock/niveaux')->json('data'))
            ->firstWhere('produit_id', $this->produit['id'])['quantite'];
    }

    private function nbSorties(): int
    {
        return collect($this->withToken($this->token)->getJson('/api/v1/stock/mouvements')->json('data'))
            ->where('type', 'vente')
            ->count();
    }

    /** Carton de 12 sacs pour l'article du test. */
    private function carton(): array
    {
        $conditionnements = $this->withToken($this->token)
            ->postJson("/api/v1/produits/{$this->produit['id']}/conditionnements", [
                'nom' => 'Carton de 12', 'quantite_base' => 12,
            ])->assertCreated()->json('data');

        return $conditionnements[0];
    }

    /* ---------------------------------------------------------------- */
    /* Le lien vers la ligne de commande */
    /* ---------------------------------------------------------------- */

    /** Le cas qui a motivé la correction : BL issu de la commande, retouché, validé. */
    public function test_bon_de_livraison_retouche_solde_toujours_la_commande(): void
    {
        $commande = $this->commandeValidee(10);
        $bl = $this->transformer($commande['id'], 'bon_livraison');

        $payload = $this->payloadDuFormulaire($this->lire($bl['id']));
        $payload['notes'] = 'Livré par le camion 2';
        $this->enregistrer($bl['id'], $payload)->assertOk()
            ->assertJsonPath('data.lignes.0.source_ligne_id', $commande['lignes'][0]['id']);

        $this->valider($bl['id']);

        $apres = $this->lire($commande['id']);
        $this->assertSame('10.000', $apres['lignes'][0]['quantite_livree'], 'La livraison est imputée sur la commande.');
        $this->assertSame('complete', $apres['livraison']);

        // La commande ne réclame plus rien : une seconde livraison est refusée…
        $this->livrer($commande, 1)->assertUnprocessable();

        // …et la marchandise n'est sortie qu'une fois.
        $this->assertSame(90.0, $this->stock());
        $this->assertSame(1, $this->nbSorties());
    }

    /** Même chose par la livraison partielle : le reliquat reste juste. */
    public function test_livraison_partielle_retouchee_garde_le_reliquat_juste(): void
    {
        $commande = $this->commandeValidee(10);
        $bl = $this->livrer($commande, 6)->assertSuccessful()->json('data');

        // Le vendeur retouche le prix sur le bon avant de le valider.
        $payload = $this->payloadDuFormulaire($this->lire($bl['id']));
        $payload['lignes'][0]['prix_unitaire'] = 80;
        $this->enregistrer($bl['id'], $payload)->assertOk();

        $this->valider($bl['id']);
        $this->assertSame(94.0, $this->stock(), 'Le bon retouché sort ses 6 sacs.');

        $apres = $this->lire($commande['id']);
        $this->assertSame('4.000', $apres['lignes'][0]['reste_a_livrer']);
        $this->assertSame('partielle', $apres['livraison']);

        // Sur-livraison toujours bloquée, solde toujours possible.
        $this->livrer($commande, 5)->assertUnprocessable();
        $this->valider($this->livrer($commande, 4)->assertSuccessful()->json('data.id'));

        $this->assertSame('complete', $this->lire($commande['id'])['livraison']);
    }

    /**
     * Le cas signalé : facture issue d'une commande déjà livrée, retouchée,
     * validée. Ce garde-fou-là ne dépend PAS du lien ligne à ligne : la
     * « famille de documents » de StockService se lit sur `source_document_id`,
     * que la mise à jour ne touche pas. Le test verrouille que le corps du
     * formulaire n'y change rien.
     */
    public function test_facture_issue_d_une_commande_retouchee_ne_sort_le_stock_qu_une_fois(): void
    {
        $commande = $this->commandeValidee(10);
        $this->valider($this->transformer($commande['id'], 'bon_livraison')['id']);
        $this->assertSame(90.0, $this->stock(), 'La livraison sort la marchandise.');

        $facture = $this->transformer($commande['id'], 'facture');
        $payload = $this->payloadDuFormulaire($this->lire($facture['id']));
        $payload['lignes'][0]['remise_percent'] = 5;
        $this->enregistrer($facture['id'], $payload)->assertOk();

        $this->valider($facture['id']);

        $this->assertSame(90.0, $this->stock(), 'La facture ne sort rien de plus.');
        $this->assertSame(1, $this->nbSorties());
    }

    /* ---------------------------------------------------------------- */
    /* Le colis */
    /* ---------------------------------------------------------------- */

    /** Une commande passée au carton (portail acheteur) reste au carton. */
    public function test_ligne_au_colis_garde_son_conditionnement(): void
    {
        $carton = $this->carton();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $this->produit['id'], 'conditionnement_id' => $carton['id'], 'quantite_colis' => 5]],
        ])->assertCreated()->json('data');

        $payload = $this->payloadDuFormulaire($this->lire($commande['id']));
        $payload['notes'] = 'Livrer avant midi';

        $this->enregistrer($commande['id'], $payload)->assertOk()
            ->assertJsonPath('data.lignes.0.conditionnement_id', $carton['id'])
            ->assertJsonPath('data.lignes.0.conditionnement', 'Carton de 12')
            ->assertJsonPath('data.lignes.0.quantite_colis', '5.000')
            ->assertJsonPath('data.lignes.0.quantite', '60.000')
            ->assertJsonPath('data.total_ht', '5100.00');

        // Et le colis suit la chaîne jusqu'à la sortie de stock, en unités.
        $this->valider($commande['id']);
        $facture = $this->transformer($commande['id'], 'facture');
        $this->assertSame('5.000', $facture['lignes'][0]['quantite_colis']);
        $this->valider($facture['id']);

        $this->assertSame(40.0, $this->stock(), '5 cartons de 12 = 60 sacs sortis.');
    }

    /* ---------------------------------------------------------------- */
    /* Le filet du serveur */
    /* ---------------------------------------------------------------- */

    /** Un client qui désigne la ligne mais oublie son lien ne le fait pas perdre. */
    public function test_le_serveur_reprend_le_lien_qu_un_envoi_omet(): void
    {
        $commande = $this->commandeValidee(10);
        $bl = $this->livrer($commande, 6)->assertSuccessful()->json('data');

        $payload = $this->payloadDuFormulaire($this->lire($bl['id']));
        unset($payload['lignes'][0]['source_ligne_id']);

        $this->enregistrer($bl['id'], $payload)->assertOk()
            ->assertJsonPath('data.lignes.0.source_ligne_id', $commande['lignes'][0]['id']);

        $this->valider($bl['id']);
        $this->assertSame('4.000', $this->lire($commande['id'])['lignes'][0]['reste_a_livrer']);
    }

    /**
     * Le colis n'est repris que sous une ligne INCHANGÉE : syncLignes recalcule
     * la quantité à partir du colis, en hériter sous une quantité retouchée
     * écraserait ce que le vendeur a tapé.
     */
    public function test_le_serveur_reprend_le_colis_seulement_si_la_ligne_n_a_pas_change(): void
    {
        $carton = $this->carton();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $this->produit['id'], 'conditionnement_id' => $carton['id'], 'quantite_colis' => 5]],
        ])->assertCreated()->json('data');

        $payload = $this->payloadDuFormulaire($this->lire($commande['id']));
        unset($payload['lignes'][0]['conditionnement_id'], $payload['lignes'][0]['quantite_colis']);

        $this->enregistrer($commande['id'], $payload)->assertOk()
            ->assertJsonPath('data.lignes.0.conditionnement_id', $carton['id'])
            ->assertJsonPath('data.lignes.0.quantite_colis', '5.000')
            ->assertJsonPath('data.lignes.0.quantite', '60.000');

        // Quantité retouchée : c'est elle qui fait foi, la ligne passe à l'unité.
        $payload = $this->payloadDuFormulaire($this->lire($commande['id']));
        unset($payload['lignes'][0]['conditionnement_id'], $payload['lignes'][0]['quantite_colis']);
        $payload['lignes'][0]['quantite'] = 70;

        $this->enregistrer($commande['id'], $payload)->assertOk()
            ->assertJsonPath('data.lignes.0.conditionnement_id', null)
            ->assertJsonPath('data.lignes.0.quantite_colis', null)
            ->assertJsonPath('data.lignes.0.quantite', '70.000');
    }

    /**
     * Livraison partielle d'une ligne au carton : le bon compte ses cartons
     * quand la quantité tombe juste, et ne perd JAMAIS sa quantité.
     *
     * Le bon recopiait le carton sans son nombre ; l'enregistrement d'un client
     * qui omet les clés colis en héritait 0 carton, d'où 0 × 12 = 0 sac — un bon
     * (puis sa facture) validé sans rien livrer ni facturer.
     */
    public function test_livraison_partielle_au_colis_garde_sa_quantite(): void
    {
        $carton = $this->carton();

        $commande = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'commande', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $this->produit['id'], 'conditionnement_id' => $carton['id'], 'quantite_colis' => 5]],
        ])->assertCreated()->json('data');
        $this->valider($commande['id']);
        $commande = $this->lire($commande['id']);

        // 24 sacs = 2 cartons pile : le bon les compte.
        $bl = $this->livrer($commande, 24)->assertSuccessful()->json('data');
        $this->assertSame($carton['id'], $bl['lignes'][0]['conditionnement_id']);
        $this->assertSame('2.000', $bl['lignes'][0]['quantite_colis']);
        $this->assertSame('24.000', $bl['lignes'][0]['quantite']);

        // 30 sacs = 2,5 cartons : la livraison part à l'unité.
        $blUnite = $this->livrer($commande, 30)->assertSuccessful()->json('data');
        $this->assertNull($blUnite['lignes'][0]['conditionnement_id']);
        $this->assertNull($blUnite['lignes'][0]['quantite_colis']);
        $this->assertSame('30.000', $blUnite['lignes'][0]['quantite']);

        // Bon d'AVANT la correction : carton sans nombre. Le client qui omet
        // les clés colis garde ses 24 sacs, pas 0.
        DB::table('document_vente_lignes')
            ->where('id', $bl['lignes'][0]['id'])
            ->update(['quantite_colis' => null]);

        $payload = $this->payloadDuFormulaire($this->lire($bl['id']));
        unset($payload['lignes'][0]['conditionnement_id'], $payload['lignes'][0]['quantite_colis']);

        $this->enregistrer($bl['id'], $payload)->assertOk()
            ->assertJsonPath('data.lignes.0.quantite', '24.000')
            ->assertJsonPath('data.total_ht', '2040.00');

        // Validé, le bon livre bien ses 24 sacs.
        $this->valider($bl['id']);
        $this->assertSame(76.0, $this->stock(), 'Les 24 sacs sont bien sortis.');
        $this->assertSame('24.000', $this->lire($commande['id'])['lignes'][0]['quantite_livree']);
    }

    /* ---------------------------------------------------------------- */
    /* Ce qu'une pièce issue d'une autre ne peut pas changer */
    /* ---------------------------------------------------------------- */

    /**
     * Un bon de livraison passé à un autre client soldait quand même la
     * commande du premier : celui-ci était ensuite facturé sans rien recevoir,
     * et ne pouvait plus être livré.
     */
    public function test_une_piece_issue_d_une_autre_garde_son_client(): void
    {
        $commande = $this->commandeValidee(10);
        $bl = $this->transformer($commande['id'], 'bon_livraison');
        $autre = $this->withToken($this->token)->postJson('/api/v1/tiers', ['name' => 'Client Y'])->json('data');

        // Corps exact du formulaire, client changé.
        $payload = $this->payloadDuFormulaire($this->lire($bl['id']));
        $payload['tiers_id'] = $autre['id'];
        $this->enregistrer($bl['id'], $payload)->assertUnprocessable()->assertJsonValidationErrors('tiers_id');

        // Sans les lignes non plus : elles gardent leur lien.
        $this->enregistrer($bl['id'], ['tiers_id' => $autre['id']])->assertUnprocessable()->assertJsonValidationErrors('tiers_id');

        $this->assertSame($this->tiers['id'], $this->lire($bl['id'])['tiers_id']);

        // Une pièce directe, elle, change de client librement.
        $devis = $this->withToken($this->token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $this->tiers['id'],
            'lignes' => [['produit_id' => $this->produit['id'], 'quantite' => 1]],
        ])->assertCreated()->json('data');
        $this->enregistrer($devis['id'], ['tiers_id' => $autre['id']])->assertOk()
            ->assertJsonPath('data.tiers_id', $autre['id']);
    }

    /**
     * Le lien vers la ligne de commande ne solde que l'article commandé. Un
     * article de remplacement — ou une ligne libre — marquait la commande
     * livrée alors que l'article commandé n'était jamais sorti du stock.
     */
    public function test_un_autre_article_ne_solde_pas_la_ligne_de_commande(): void
    {
        $commande = $this->commandeValidee(10);
        $bl = $this->transformer($commande['id'], 'bon_livraison');
        $chaux = $this->withToken($this->token)->postJson('/api/v1/produits', [
            'name' => 'Chaux 25kg', 'type' => 'product', 'sell_price' => 40, 'tva_rate' => 20,
        ])->assertCreated()->json('data');

        $base = $this->payloadDuFormulaire($this->lire($bl['id']));

        // Autre article, lien gardé : refusé.
        $force = $base;
        $force['lignes'][0]['produit_id'] = $chaux['id'];
        $this->enregistrer($bl['id'], $force)->assertUnprocessable()->assertJsonValidationErrors('lignes');

        // Ligne libre, lien gardé : refusé aussi.
        $force = $base;
        $force['lignes'][0]['produit_id'] = null;
        $this->enregistrer($bl['id'], $force)->assertUnprocessable()->assertJsonValidationErrors('lignes');

        // Le filet du serveur ne lègue pas le lien à un autre article.
        $omis = $base;
        $omis['lignes'][0]['produit_id'] = $chaux['id'];
        unset($omis['lignes'][0]['source_ligne_id']);
        $this->enregistrer($bl['id'], $omis)->assertOk()
            ->assertJsonPath('data.lignes.0.source_ligne_id', null);

        // Ce que fait le formulaire : l'article de remplacement part sans lien…
        $remplacement = $base;
        $remplacement['lignes'][0]['produit_id'] = $chaux['id'];
        $remplacement['lignes'][0]['source_ligne_id'] = null;
        $remplacement['lignes'][0]['id'] = null;
        $this->enregistrer($bl['id'], $remplacement)->assertOk();
        $this->valider($bl['id']);

        // …et la commande de ciment reste à livrer, visiblement.
        $apres = $this->lire($commande['id']);
        $this->assertSame('aucune', $apres['livraison']);
        $this->assertSame('10.000', $apres['lignes'][0]['reste_a_livrer']);
        $this->assertSame(100.0, $this->stock(), 'Aucun sac de ciment n\'est sorti.');
    }

    /** Le lien ne se forge ni vers une autre commande, ni vers une autre société. */
    public function test_un_lien_de_commande_force_est_refuse(): void
    {
        $commande = $this->commandeValidee(10);
        $bl = $this->livrer($commande, 6)->assertSuccessful()->json('data');
        $payload = $this->payloadDuFormulaire($this->lire($bl['id']));

        // Une autre commande du même client.
        $autre = $this->commandeValidee(3);
        $force = $payload;
        $force['lignes'][0]['source_ligne_id'] = $autre['lignes'][0]['id'];
        $this->enregistrer($bl['id'], $force)->assertUnprocessable()->assertJsonValidationErrors('lignes');

        // Une commande d'une AUTRE société : les lignes n'ont pas de tenant
        // propre, seule l'appartenance à la commande source les sépare.
        $tokenB = $this->inscrire('Concurrent', 'b@rival.ma');
        $commandeB = $this->commandeValidee(50, $tokenB);
        $blB = $this->livrer($commandeB, 20, $tokenB)->assertSuccessful()->json('data');

        $force = $payload;
        $force['lignes'][0]['source_ligne_id'] = $commandeB['lignes'][0]['id'];
        $this->enregistrer($bl['id'], $force)->assertUnprocessable()->assertJsonValidationErrors('lignes');

        // L'`id` d'une ligne étrangère ne lègue rien : sans lui, la ligne de
        // l'autre société passerait son lien à celle-ci.
        $emprunte = $payload;
        $emprunte['lignes'][0]['id'] = $blB['lignes'][0]['id'];
        unset($emprunte['lignes'][0]['source_ligne_id']);
        $this->enregistrer($bl['id'], $emprunte)->assertOk()
            ->assertJsonPath('data.lignes.0.source_ligne_id', null);

        // Hors d'un bon de livraison issu d'une commande, aucun lien n'a de sens.
        $facture = $this->transformer($commande['id'], 'facture');
        $force = $this->payloadDuFormulaire($this->lire($facture['id']));
        $force['lignes'][0]['source_ligne_id'] = $commande['lignes'][0]['id'];
        $this->enregistrer($facture['id'], $force)->assertUnprocessable()->assertJsonValidationErrors('lignes');
    }

    /* ---------------------------------------------------------------- */
    /* Achats : même aller-retour */
    /* ---------------------------------------------------------------- */

    /**
     * AchatForm renvoyait déjà `source_ligne_id` : on verrouille qu'une
     * réception retouchée par son corps exact solde toujours la commande.
     */
    public function test_reception_retouchee_par_le_formulaire_solde_la_commande_fournisseur(): void
    {
        $fournisseur = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Cimenterie', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');
        $entrepot = $this->withToken($this->token)->getJson('/api/v1/stock/entrepots')->json('data.0');

        $commande = $this->withToken($this->token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $fournisseur['id'], 'entrepot_id' => $entrepot['id'],
            'lignes' => [['produit_id' => $this->produit['id'], 'quantite' => 40, 'prix_unitaire' => 60]],
        ])->assertCreated()->json('data');
        $this->withToken($this->token)->postJson("/api/v1/achats/documents/{$commande['id']}/valider")->assertOk();

        $reception = $this->withToken($this->token)
            ->postJson("/api/v1/achats/documents/{$commande['id']}/transformer", ['type' => 'reception'])
            ->assertOk()->json('data');

        // Corps d'AchatForm.handleSubmit, quantité ramenée à 25.
        $this->withToken($this->token)->putJson("/api/v1/achats/documents/{$reception['id']}", [
            'tiers_id' => $reception['tiers_id'],
            'entrepot_id' => $reception['entrepot_id'],
            'ref_fournisseur' => null,
            'date_document' => $reception['date_document'],
            'date_echeance' => null,
            'notes' => null,
            'lignes' => array_map(fn (array $l) => [
                'produit_id' => $l['produit_id'],
                'source_ligne_id' => $l['source_ligne_id'],
                'designation' => $l['designation'],
                'quantite' => 25.0,
                'prix_unitaire' => (float) $l['prix_unitaire'],
                'remise_percent' => (float) $l['remise_percent'],
                'tva_rate' => (float) $l['tva_rate'],
            ], $reception['lignes']),
        ])->assertOk()->assertJsonPath('data.lignes.0.source_ligne_id', $commande['lignes'][0]['id']);

        $this->withToken($this->token)->postJson("/api/v1/achats/documents/{$reception['id']}/valider")->assertOk();

        $apres = $this->withToken($this->token)->getJson("/api/v1/achats/documents/{$commande['id']}")->json('data');
        $this->assertSame('recue_partielle', $apres['statut']);
        $this->assertSame('15.000', $apres['lignes'][0]['reste_a_recevoir']);
        $this->assertSame(125.0, $this->stock(), '100 au départ + 25 reçus.');
    }

    /**
     * Mêmes règles qu'à la vente : une réception issue d'une commande garde
     * son fournisseur, et ne solde la commande qu'avec l'article commandé.
     */
    public function test_reception_issue_d_une_commande_garde_son_fournisseur_et_son_article(): void
    {
        $fournisseur = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Cimenterie', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');
        $autreFournisseur = $this->withToken($this->token)->postJson('/api/v1/tiers', [
            'name' => 'Négoce Sud', 'is_client' => false, 'is_supplier' => true,
        ])->json('data');
        $chaux = $this->withToken($this->token)->postJson('/api/v1/produits', [
            'name' => 'Chaux 25kg', 'type' => 'product', 'sell_price' => 40, 'tva_rate' => 20,
        ])->assertCreated()->json('data');
        $entrepot = $this->withToken($this->token)->getJson('/api/v1/stock/entrepots')->json('data.0');

        $commande = $this->withToken($this->token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $fournisseur['id'], 'entrepot_id' => $entrepot['id'],
            'lignes' => [['produit_id' => $this->produit['id'], 'quantite' => 40, 'prix_unitaire' => 60]],
        ])->assertCreated()->json('data');
        $this->withToken($this->token)->postJson("/api/v1/achats/documents/{$commande['id']}/valider")->assertOk();

        $reception = $this->withToken($this->token)
            ->postJson("/api/v1/achats/documents/{$commande['id']}/transformer", ['type' => 'reception'])
            ->assertOk()->json('data');

        $corps = fn (array $surcharge = [], array $ligne = []) => array_merge([
            'tiers_id' => $reception['tiers_id'],
            'entrepot_id' => $reception['entrepot_id'],
            'lignes' => [array_merge([
                'produit_id' => $reception['lignes'][0]['produit_id'],
                'source_ligne_id' => $reception['lignes'][0]['source_ligne_id'],
                'designation' => $reception['lignes'][0]['designation'],
                'quantite' => 40.0,
                'prix_unitaire' => 60.0,
                'tva_rate' => 20.0,
            ], $ligne)],
        ], $surcharge);

        $this->withToken($this->token)->putJson("/api/v1/achats/documents/{$reception['id']}", $corps(['tiers_id' => $autreFournisseur['id']]))
            ->assertUnprocessable()->assertJsonValidationErrors('tiers_id');

        $this->withToken($this->token)->putJson("/api/v1/achats/documents/{$reception['id']}", $corps([], ['produit_id' => $chaux['id']]))
            ->assertUnprocessable()->assertJsonValidationErrors('lignes');

        // Inchangée, elle s'enregistre toujours.
        $this->withToken($this->token)->putJson("/api/v1/achats/documents/{$reception['id']}", $corps())
            ->assertOk()->assertJsonPath('data.lignes.0.source_ligne_id', $commande['lignes'][0]['id']);
    }
}
