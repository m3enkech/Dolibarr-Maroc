<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Stock\Models\MouvementStock;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * stock:verifier-familles — le diagnostic des familles touchées par le bug des
 * BL partiels frères, en lecture seule.
 *
 * Les données « d'avant la correction » sont reconstituées en retouchant les
 * mouvements : on crée la famille avec le code actuel, puis on efface la
 * sortie que l'ancien code n'aurait pas écrite (ou on ajoute celle qu'il
 * aurait écrite en trop).
 */
class VerifierFamillesStockTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{token: string, produit: array, tiers: array, entrepot: array}> */
    private array $entreprises = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise('a@gros.ma', 'Gros Atlas', 'Ciment 50kg');
        $this->entreprise('b@autre.ma', 'Autre Négoce', 'Plâtre 25kg');
    }

    private function entreprise(string $email, string $societe, string $article): void
    {
        $token = $this->postJson('/api/v1/auth/register', [
            'company_name' => $societe, 'name' => 'Patron', 'email' => $email, 'password' => 'password123',
        ])->assertCreated()->json('token');

        $tiers = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $entrepot = $this->withToken($token)->postJson('/api/v1/stock/entrepots', ['name' => 'Dépôt'])->json('data');

        $this->entreprises[$email] = compact('token', 'tiers', 'entrepot') + ['produit' => []];
        $this->entreprises[$email]['produit'] = $this->article($email, $article);
    }

    /** Un article suivi en stock, 100 au dépôt. */
    private function article(string $email, string $nom): array
    {
        $produit = $this->api($email)->postJson('/api/v1/produits', [
            'name' => $nom, 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
        ])->assertCreated()->json('data');

        $this->api($email)->postJson('/api/v1/stock/mouvements', [
            'produit_id' => $produit['id'], 'entrepot_id' => $this->entreprises[$email]['entrepot']['id'],
            'type' => 'entree', 'quantite' => 100,
        ])->assertCreated();

        return $produit;
    }

    /** Pack de 10 unités de l'article principal. */
    private function kit(string $email): array
    {
        return $this->api($email)->postJson('/api/v1/produits', [
            'name' => 'Pack chantier', 'type' => 'kit', 'sell_price' => 900, 'tva_rate' => 20,
            'composants' => [['produit_id' => $this->entreprises[$email]['produit']['id'], 'quantite' => 10]],
        ])->assertCreated()->json('data');
    }

    private function api(string $email): self
    {
        return $this->withToken($this->entreprises[$email]['token']);
    }

    /** Pièce validée, sans source. */
    private function piece(string $email, string $type, float $quantite, ?int $produitId = null): array
    {
        $e = $this->entreprises[$email];

        $piece = $this->api($email)->postJson('/api/v1/ventes/documents', [
            'type' => $type, 'tiers_id' => $e['tiers']['id'],
            'lignes' => [['produit_id' => $produitId ?? $e['produit']['id'], 'quantite' => $quantite]],
        ])->assertCreated()->json('data');
        $this->valider($email, $piece['id']);

        return $piece;
    }

    private function commande(string $email, float $quantite, ?int $produitId = null): array
    {
        return $this->piece($email, 'commande', $quantite, $produitId);
    }

    private function livrer(string $email, int $commandeId, float $quantite, bool $valider = true): array
    {
        $ligneId = $this->api($email)->getJson("/api/v1/ventes/documents/{$commandeId}")->json('data.lignes.0.id');

        $bl = $this->api($email)->postJson("/api/v1/ventes/documents/{$commandeId}/livrer", [
            'lignes' => [['source_ligne_id' => $ligneId, 'quantite' => $quantite]],
        ])->assertSuccessful()->json('data');

        if ($valider) {
            $this->valider($email, $bl['id']);
        }

        return $bl;
    }

    /** Brouillon tiré d'une autre pièce, lignes reprises telles quelles. */
    private function transformer(string $email, int $sourceId, string $type): array
    {
        return $this->api($email)->postJson("/api/v1/ventes/documents/{$sourceId}/transformer", ['type' => $type])
            ->assertOk()->json('data');
    }

    private function facturer(string $email, int $sourceId): array
    {
        $facture = $this->transformer($email, $sourceId, 'facture');
        $this->valider($email, $facture['id']);

        return $this->api($email)->getJson("/api/v1/ventes/documents/{$facture['id']}")->json('data');
    }

    private function valider(string $email, int $id): void
    {
        $this->api($email)->postJson("/api/v1/ventes/documents/{$id}/valider")->assertOk();
    }

    /** Reconstitue l'ancien comportement : cette pièce n'avait rien sorti. */
    private function effacerSorties(string $email, int $documentId): void
    {
        $this->commeEntreprise($email, fn () => MouvementStock::where('document_vente_id', $documentId)->delete());
    }

    /** Reconstitue une sortie en trop d'avant la règle de famille. */
    private function ajouterSortie(string $email, array $document, int $produitId, float $quantite): void
    {
        $this->commeEntreprise($email, fn () => MouvementStock::create([
            'produit_id' => $produitId,
            'entrepot_id' => $this->entreprises[$email]['entrepot']['id'],
            'document_vente_id' => $document['id'],
            'type' => MouvementStock::TYPE_VENTE,
            'quantite' => -$quantite,
            'quantite_apres' => 0,
            'reference' => $document['code'],
        ]));
    }

    /** Inventaire du dépôt, l'article principal compté à cette quantité, validé. */
    private function inventaire(string $email, float $compte): array
    {
        $id = $this->api($email)->postJson('/api/v1/stock/inventaires', [
            'entrepot_id' => $this->entreprises[$email]['entrepot']['id'],
        ])->json('data.id');

        $this->api($email)->putJson("/api/v1/stock/inventaires/{$id}", [
            'comptages' => [['produit_id' => $this->entreprises[$email]['produit']['id'], 'quantite_comptee' => $compte]],
        ])->assertOk();

        return $this->api($email)->postJson("/api/v1/stock/inventaires/{$id}/valider")->assertOk()->json('data');
    }

    private function commeEntreprise(string $email, \Closure $fn): mixed
    {
        $tenant = User::withoutGlobalScopes()->where('email', $email)->first()->tenant;

        return app(TenantContext::class)->runAs($tenant, $fn);
    }

    /** @return array{0: int, 1: string} code retour et sortie de la commande */
    private function verifier(string $email): array
    {
        $code = Artisan::call('stock:verifier-familles', ['email' => $email]);

        return [$code, Artisan::output()];
    }

    /* ---------------------------------------------------------------- */

    /**
     * Le scénario (a) tel qu'il est en production : deux BL partiels de 6 et 4,
     * le second sans sortie. La commande trouve 4 unités de sous-sortie, nomme
     * les pièces, leur date, le client et l'entrepôt.
     */
    public function test_trouve_la_sous_sortie_du_second_bl_partiel(): void
    {
        $commande = $this->commande('a@gros.ma', 10);
        $this->livrer('a@gros.ma', $commande['id'], 6);
        $bl2 = $this->livrer('a@gros.ma', $commande['id'], 4);
        $this->effacerSorties('a@gros.ma', $bl2['id']);

        [$code, $sortie] = $this->verifier('a@gros.ma');

        $this->assertSame(0, $code);
        $this->assertStringContainsString($commande['code'].' (commande) · Client · Ciment 50kg', $sortie, 'La famille est désignée par sa racine et son client.');
        $this->assertStringContainsString('· Dépôt : dû 10, sorti 6', $sortie);
        $this->assertStringContainsString('SOUS-SORTIE de 4', $sortie);
        $this->assertMatchesRegularExpression(
            '/'.preg_quote($bl2['code'], '/').'\s+bon de livraison\s+'.preg_quote(now()->format('d/m/Y'), '/').'\s+porte 4\s+dû 4\s+sorti 0/',
            $sortie,
        );
        $this->assertStringContainsString('Aucun inventaire validé n\'a compté cet article dans cet entrepôt.', $sortie);
        $this->assertStringContainsString('1 écart(s) : 1 sous-sortie(s), 0 sur-sortie(s).', $sortie);
        $this->assertMatchesRegularExpression('/Ciment 50kg \(\S+\) · Dépôt : stock affiché trop haut de 4/', $sortie);
    }

    /**
     * Une famille saine n'est pas citée ; une sur-sortie d'avant la règle de
     * famille (BL et facture frères sortant chacun) l'est, dans l'autre sens.
     */
    public function test_ignore_les_familles_saines_et_signale_les_sur_sorties(): void
    {
        $saine = $this->commande('a@gros.ma', 10);
        $blSain = $this->livrer('a@gros.ma', $saine['id'], 10);
        $this->facturer('a@gros.ma', $blSain['id']);

        $doublee = $this->commande('a@gros.ma', 5);
        $this->livrer('a@gros.ma', $doublee['id'], 5);
        $facture = $this->facturer('a@gros.ma', $doublee['id']);

        // L'ancien listener faisait sortir la facture issue de la commande en plus du BL.
        $this->ajouterSortie('a@gros.ma', $facture, $this->entreprises['a@gros.ma']['produit']['id'], 5);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringNotContainsString($saine['code'], $sortie, 'Une famille juste n\'est pas listée.');
        $this->assertStringContainsString($doublee['code'], $sortie);
        $this->assertStringContainsString('dû 5, sorti 10', $sortie);
        $this->assertStringContainsString('SUR-SORTIE de 5', $sortie);
        $this->assertStringContainsString('2 famille(s) de documents examinée(s)', $sortie);
    }

    public function test_aucun_ecart_le_dit(): void
    {
        $commande = $this->commande('a@gros.ma', 10);
        $this->livrer('a@gros.ma', $commande['id'], 6);
        $this->livrer('a@gros.ma', $commande['id'], 4);

        [$code, $sortie] = $this->verifier('a@gros.ma');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Aucun écart', $sortie);
    }

    /**
     * Les avoirs entrent dans le rejeu. Une facture annulée par un avoir avant
     * la livraison : le BL qui suit DOIT sortir ses 10 (l'ancien code ne
     * sortait rien). Sans l'avoir dans le rejeu, le BL paraîtrait couvert par
     * la facture et ces 10 unités de stock fantôme passeraient inaperçues.
     */
    public function test_un_avoir_entre_dans_le_rejeu(): void
    {
        $commande = $this->commande('a@gros.ma', 10);
        $facture = $this->facturer('a@gros.ma', $commande['id']);
        $avoir = $this->transformer('a@gros.ma', $facture['id'], 'avoir');
        $this->valider('a@gros.ma', $avoir['id']);
        $bl = $this->livrer('a@gros.ma', $commande['id'], 10);
        $this->effacerSorties('a@gros.ma', $bl['id']);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('dû 20, sorti 10', $sortie);
        $this->assertStringContainsString('SOUS-SORTIE de 10', $sortie);
        $this->assertMatchesRegularExpression('/\savoir\s+\S+\s+porte 10\s+rentré 10/', $sortie);
        $this->assertMatchesRegularExpression('/'.preg_quote($bl['code'], '/').'\s+bon de livraison\s+\S+\s+porte 10\s+dû 10\s+sorti 0/', $sortie);
    }

    /** Un kit livré en deux BL, le second sans sortie : l'écart est compté en composants. */
    public function test_kit_livre_en_deux_bl(): void
    {
        $kit = $this->kit('a@gros.ma');
        $commande = $this->commande('a@gros.ma', 2, $kit['id']);
        $this->livrer('a@gros.ma', $commande['id'], 1);
        $this->effacerSorties('a@gros.ma', $this->livrer('a@gros.ma', $commande['id'], 1)['id']);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('Ciment 50kg', $sortie);
        $this->assertStringContainsString('dû 20, sorti 10', $sortie);
        $this->assertStringContainsString('SOUS-SORTIE de 10', $sortie);
    }

    /**
     * Racine à trois niveaux (devis → commande → BL) et facture tirée du devis :
     * une seule famille, désignée par le devis. Les groupes du diagnostic sont
     * calculés en une passe, pas par racine() : ils doivent tomber d'accord.
     */
    public function test_famille_a_racine_devis(): void
    {
        $devis = $this->piece('a@gros.ma', 'devis', 10);
        $commande = $this->transformer('a@gros.ma', $devis['id'], 'commande');
        $this->valider('a@gros.ma', $commande['id']);
        $this->livrer('a@gros.ma', $commande['id'], 6);
        $this->effacerSorties('a@gros.ma', $this->livrer('a@gros.ma', $commande['id'], 4)['id']);
        $this->facturer('a@gros.ma', $devis['id']);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('1 famille(s) de documents examinée(s)', $sortie);
        $this->assertStringContainsString($devis['code'].' (devis)', $sortie);
        $this->assertStringNotContainsString($commande['code'].' (commande)', $sortie);
        $this->assertStringContainsString('dû 10, sorti 6', $sortie);
        $this->assertStringContainsString('SOUS-SORTIE de 4', $sortie);
    }

    /**
     * Un article archivé (suppression douce) après ses ventes, et un kit dont
     * c'est le composant : leurs familles saines le restent. Lu sans les
     * archivés, chaque sortie passée paraissait « due 0 » — fausse SUR-SORTIE
     * de toute la quantité vendue, qu'un humain aurait « corrigée ».
     */
    public function test_un_article_archive_ne_fabrique_pas_d_ecart(): void
    {
        $commande = $this->commande('a@gros.ma', 10);
        $this->facturer('a@gros.ma', $this->livrer('a@gros.ma', $commande['id'], 10)['id']);

        $kit = $this->kit('a@gros.ma');
        $this->livrer('a@gros.ma', $this->commande('a@gros.ma', 1, $kit['id'])['id'], 1);

        $this->api('a@gros.ma')->deleteJson('/api/v1/produits/'.$this->entreprises['a@gros.ma']['produit']['id'])->assertOk();

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('2 famille(s) de documents examinée(s)', $sortie);
        $this->assertStringContainsString('Aucun écart', $sortie);
    }

    /**
     * Le dernier inventaire qui a compté l'article : antérieur à l'écart, il est
     * cité et l'écart reste dans le net ; postérieur, l'écart est signalé comme
     * probablement déjà absorbé et sort du net — sans quoi on le corrigerait
     * une seconde fois.
     */
    public function test_cite_le_dernier_inventaire_et_l_ecarte_du_net_s_il_est_posterieur(): void
    {
        $avant = $this->inventaire('a@gros.ma', 100);
        $dateAvant = now()->format('d/m/Y');

        $this->travel(1)->days();
        $commande = $this->commande('a@gros.ma', 10);
        $this->livrer('a@gros.ma', $commande['id'], 6);
        $this->effacerSorties('a@gros.ma', $this->livrer('a@gros.ma', $commande['id'], 4)['id']);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString("Dernier inventaire de l'article : {$avant['code']} du {$dateAvant} (Dépôt), antérieur à l'écart.", $sortie);
        $this->assertMatchesRegularExpression('/Ciment 50kg \(\S+\) · Dépôt : stock affiché trop haut de 4/', $sortie);

        // Le comptage trouve les 90 réels et recale le stock affiché (94).
        $this->travel(1)->days();
        $apres = $this->inventaire('a@gros.ma', 90);
        $dateApres = now()->format('d/m/Y');

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('SOUS-SORTIE de 4', $sortie, 'L\'écart des pièces demeure : seul le jugement change.');
        $this->assertStringContainsString("Inventaire {$apres['code']} du {$dateApres} (Dépôt), postérieur à ces pièces", $sortie);
        $this->assertStringContainsString('probablement déjà corrigé cet écart', $sortie);
        $this->assertStringContainsString('Aucun : chaque écart précède un inventaire qui a compté l\'article.', $sortie);
        $this->assertStringContainsString('1 écart(s) suivi(s) d\'un inventaire, exclu(s) du net', $sortie);
        $this->assertStringNotContainsString('stock affiché trop haut de 4', $sortie);
    }

    /**
     * Le net est donné par article et par entrepôt, jamais en « unités » toutes
     * confondues : 4 sacs et 24 bouteilles ne font pas 28 de quoi que ce soit.
     * Deux écarts opposés du même article se compensent.
     */
    public function test_le_net_est_par_article_et_ne_melange_pas_les_unites(): void
    {
        $bouteille = $this->article('a@gros.ma', 'Bouteille 1L');

        $c1 = $this->commande('a@gros.ma', 10);
        $this->livrer('a@gros.ma', $c1['id'], 6);
        $this->effacerSorties('a@gros.ma', $this->livrer('a@gros.ma', $c1['id'], 4)['id']);

        $c2 = $this->commande('a@gros.ma', 4);
        $bl = $this->livrer('a@gros.ma', $c2['id'], 4);
        $this->ajouterSortie('a@gros.ma', $this->facturer('a@gros.ma', $bl['id']), $this->entreprises['a@gros.ma']['produit']['id'], 4);

        $c3 = $this->commande('a@gros.ma', 30, $bouteille['id']);
        $this->livrer('a@gros.ma', $c3['id'], 6);
        $this->effacerSorties('a@gros.ma', $this->livrer('a@gros.ma', $c3['id'], 24)['id']);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('3 écart(s) : 2 sous-sortie(s), 1 sur-sortie(s).', $sortie);
        $this->assertMatchesRegularExpression('/Bouteille 1L \(\S+\) · Dépôt : stock affiché trop haut de 24/', $sortie);
        $this->assertMatchesRegularExpression('/Ciment 50kg \(\S+\) · Dépôt : les écarts se compensent/', $sortie);
        $this->assertStringNotContainsString('unité', $sortie);
    }

    /**
     * Le diagnostic rejoue dans l'ordre des VALIDATIONS. BL préparé avant
     * l'avoir, validé après lui, dans la même seconde : rejoué dans l'ordre de
     * création (validated_at puis id), le BL paraissait couvert par la facture
     * encore active — « dû 0, sorti 10 », fausse SUR-SORTIE sur un stock juste.
     */
    public function test_rejoue_dans_l_ordre_des_validations(): void
    {
        $this->freezeSecond();

        $commande = $this->commande('a@gros.ma', 10);
        $facture = $this->facturer('a@gros.ma', $commande['id']);
        $bl = $this->livrer('a@gros.ma', $commande['id'], 10, valider: false);
        $avoir = $this->transformer('a@gros.ma', $facture['id'], 'avoir');

        $this->valider('a@gros.ma', $avoir['id']);
        $this->valider('a@gros.ma', $bl['id']);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('Aucun écart', $sortie);
    }

    /**
     * Une facture reprise d'un autre logiciel est hors règle : la reprise Zoho
     * les a importées SANS mouvement de stock (option de reprise). Sans cette
     * exclusion, chaque reprise suivie d'un avoir paraîtrait en sous-sortie.
     */
    public function test_ignore_les_pieces_reprises(): void
    {
        $facture = $this->piece('a@gros.ma', 'facture', 10);

        // Telle que la reprise la laisse : son origine, et aucune sortie.
        $this->effacerSorties('a@gros.ma', $facture['id']);
        $this->commeEntreprise('a@gros.ma', fn () => DocumentVente::whereKey($facture['id'])
            ->update(['source_systeme' => 'zoho_books', 'source_id' => 'INV-000123']));

        $this->valider('a@gros.ma', $this->transformer('a@gros.ma', $facture['id'], 'avoir')['id']);

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('1 famille(s) de documents examinée(s)', $sortie);
        $this->assertStringContainsString('Aucun écart', $sortie);
    }

    /** Chaque entreprise ne voit que ses familles, même quand les deux ont un écart. */
    public function test_ne_voit_que_son_entreprise(): void
    {
        foreach (['a@gros.ma', 'b@autre.ma'] as $email) {
            $commande = $this->commande($email, 10);
            $this->livrer($email, $commande['id'], 6);
            $this->effacerSorties($email, $this->livrer($email, $commande['id'], 4)['id']);
        }

        [, $sortieA] = $this->verifier('a@gros.ma');
        $this->assertStringContainsString('Gros Atlas', $sortieA);
        $this->assertStringContainsString('Ciment 50kg', $sortieA);
        $this->assertStringNotContainsString('Plâtre 25kg', $sortieA);
        $this->assertStringContainsString('1 écart(s)', $sortieA);

        [, $sortieB] = $this->verifier('b@autre.ma');
        $this->assertStringContainsString('Plâtre 25kg', $sortieB);
        $this->assertStringNotContainsString('Ciment 50kg', $sortieB);
    }

    /** Lecture seule, prouvée au niveau SQL : aucune écriture, aucun verrou. */
    public function test_n_ecrit_rien(): void
    {
        $commande = $this->commande('a@gros.ma', 10);
        $this->livrer('a@gros.ma', $commande['id'], 6);
        $this->effacerSorties('a@gros.ma', $this->livrer('a@gros.ma', $commande['id'], 4)['id']);
        // Un inventaire, pour que la lecture des comptages soit aussi tracée.
        $this->inventaire('a@gros.ma', 90);

        $requetes = [];
        DB::listen(function ($requete) use (&$requetes) {
            $requetes[] = $requete->sql;
        });

        [, $sortie] = $this->verifier('a@gros.ma');

        $this->assertStringContainsString('SOUS-SORTIE', $sortie, 'Le diagnostic a bien travaillé.');
        $this->assertNotEmpty($requetes);

        foreach ($requetes as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql);
            $this->assertStringNotContainsStringIgnoringCase('for update', $sql);
        }
    }

    public function test_utilisateur_inconnu(): void
    {
        [$code, $sortie] = $this->verifier('personne@nulle.part');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('introuvable', $sortie);
    }
}
