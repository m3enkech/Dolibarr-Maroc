<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Passage de commande fournisseur depuis l'écran de suivi.
 *
 * C'est la seule écriture déclenchée par un tableau de bord de l'application :
 * ces tests gardent les quatre précautions qui la rendent défendable.
 */
class CommandeReapproTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Acme', 'name' => 'Admin',
            'email' => 'admin@acme.ma', 'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), $res->json('tenant.id')];
    }

    private function fournisseur(string $token, string $nom): array
    {
        return $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => $nom, 'is_supplier' => true, 'is_client' => false,
        ])->assertCreated()->json('data');
    }

    private function produit(string $token, string $nom, array $extra = []): array
    {
        return $this->withToken($token)->postJson('/api/v1/produits', array_merge([
            'name' => $nom, 'type' => 'product', 'sell_price' => 100, 'tva_rate' => 20,
            'buy_price' => 60, 'stock_min' => 20, 'stock_reappro' => 50,
        ], $extra))->assertCreated()->json('data');
    }

    /** Le dépôt du décor : valider une réception exige de savoir où elle arrive. */
    private function entrepot(string $token): int
    {
        return $this->depot ??= $this->withToken($token)
            ->postJson('/api/v1/stock/entrepots', ['name' => 'Casablanca'])
            ->assertCreated()->json('data.id');
    }

    private ?int $depot = null;

    /** Un achat validé PUIS reçu, qui fait de ce tiers le fournisseur déduit de l'article. */
    private function achatPasse(string $token, int $tiersId, int $produitId, float $quantite = 10, float $prix = 60): void
    {
        $commande = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'commande', 'tiers_id' => $tiersId,
            'entrepot_id' => $this->entrepot($token),
            'lignes' => [['produit_id' => $produitId, 'designation' => 'X', 'quantite' => $quantite, 'prix_unitaire' => $prix, 'tva_rate' => 20]],
        ])->assertCreated()->json('data');

        $this->withToken($token)->postJson("/api/v1/achats/documents/{$commande['id']}/valider")->assertOk();

        // Reçue en totalité : la commande est soldée et ne pèse plus sur
        // « en commande », sinon elle couvrirait le besoin qu'on veut mesurer.
        $reception = $this->withToken($token)
            ->postJson("/api/v1/achats/documents/{$commande['id']}/transformer", ['type' => 'reception'])
            ->assertSuccessful()->json('data');

        $this->withToken($token)->postJson("/api/v1/achats/documents/{$reception['id']}/valider")->assertOk();
    }

    private function commander(string $token, array $produitIds, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/achats/commandes-reappro', array_merge([
            'produit_ids' => $produitIds,
            'cle_idempotence' => (string) Str::uuid(),
        ], $extra));
    }

    /* ---------------------------------------------------------------- */

    public function test_une_commande_par_fournisseur(): void
    {
        [$token] = $this->registerTenant();

        $sotrama = $this->fournisseur($token, 'Sotrama');
        $atlas = $this->fournisseur($token, 'Atlas');

        $ciment = $this->produit($token, 'Ciment');
        $sable = $this->produit($token, 'Sable');
        $brique = $this->produit($token, 'Brique');

        $this->achatPasse($token, $sotrama['id'], $ciment['id']);
        $this->achatPasse($token, $sotrama['id'], $sable['id']);
        $this->achatPasse($token, $atlas['id'], $brique['id']);

        $data = $this->commander($token, [$ciment['id'], $sable['id'], $brique['id']])
            ->assertCreated()->json('data');

        $this->assertCount(2, $data['commandes'], 'Une commande par fournisseur, pas une par article.');
        $this->assertSame([], $data['ignores']);

        $parFournisseur = collect($data['commandes'])->keyBy(fn ($c) => $c['fournisseur']['name']);
        $this->assertSame(2, $parFournisseur['Sotrama']['nb_lignes']);
        $this->assertSame(1, $parFournisseur['Atlas']['nb_lignes']);
    }

    /**
     * LE test du lot : le serveur recalcule, il ne fait pas confiance à l'écran.
     *
     * On lit la quantité conseillée, on vend cinq unités de plus, PUIS on
     * commande. Le client n'envoie que des identifiants ; la ligne créée doit
     * donc refléter l'état d'APRÈS la vente, pas le chiffre affiché avant.
     */
    public function test_la_quantite_est_recalculee_au_moment_du_clic(): void
    {
        [$token] = $this->registerTenant();
        $fournisseur = $this->fournisseur($token, 'Sotrama');
        $produit = $this->produit($token, 'Ciment');

        // Dix reçues, seuil à vingt : l'article reste sous son seuil, donc
        // visible dans les alertes.
        $this->achatPasse($token, $fournisseur['id'], $produit['id'], quantite: 10);

        $conseilAvant = collect($this->withToken($token)->getJson('/api/v1/stock/alertes')->json('data'))
            ->firstWhere('produit_id', $produit['id']);
        $this->assertNotNull($conseilAvant, "L'article doit figurer dans les alertes avant le clic.");
        $avant = (float) $conseilAvant['suggestion'];

        // Cinq unités partent entre l'affichage et le clic.
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client', 'is_client' => true])
            ->assertCreated()->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['produit_id' => $produit['id'], 'designation' => 'Ciment', 'quantite' => 5, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->assertCreated()->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();

        $commande = $this->commander($token, [$produit['id']])->assertCreated()->json('data.commandes.0');

        $ligne = DocumentAchat::query()->findOrFail($commande['id'])->lignes()->first();

        $this->assertSame(
            $avant + 5,
            (float) $ligne->quantite,
            "La quantité commandée doit tenir compte des cinq unités vendues APRÈS l'affichage.",
        );
    }

    public function test_un_article_sans_historique_d_achat_est_ecarte_et_annonce(): void
    {
        [$token] = $this->registerTenant();
        $orphelin = $this->produit($token, 'Palette bois');

        $data = $this->commander($token, [$orphelin['id']])->assertOk()->json('data');

        $this->assertSame([], $data['commandes'], 'Rien ne doit être créé sans fournisseur.');
        $this->assertSame('fournisseur_inconnu', $data['ignores'][0]['raison']);
        $this->assertSame('Palette bois', $data['ignores'][0]['name']);
        $this->assertSame(0, DocumentAchat::query()->count());
    }

    /**
     * Le fournisseur est DÉDUIT du dernier achat, parfois vieux de deux ans.
     * S'il n'est plus fournisseur entre-temps, on ne lui adresse pas une
     * commande : `AchatService::create()` accepterait le tiers sans broncher,
     * la vérification du drapeau vivant dans la requête HTTP, pas dans le
     * service.
     */
    public function test_un_fournisseur_qui_n_en_est_plus_un_ne_recoit_pas_de_commande(): void
    {
        [$token] = $this->registerTenant();
        $fournisseur = $this->fournisseur($token, 'Ancien partenaire');
        $produit = $this->produit($token, 'Ciment');
        $this->achatPasse($token, $fournisseur['id'], $produit['id']);

        Tiers::query()->whereKey($fournisseur['id'])->update(['is_supplier' => false]);

        $data = $this->commander($token, [$produit['id']])->assertOk()->json('data');

        $this->assertSame([], $data['commandes']);
        $this->assertSame('fournisseur_inconnu', $data['ignores'][0]['raison']);
    }

    public function test_un_fournisseur_de_repli_rattrape_les_orphelins(): void
    {
        [$token] = $this->registerTenant();
        $repli = $this->fournisseur($token, 'Centrale d\'achat');
        $orphelin = $this->produit($token, 'Palette bois');

        $data = $this->commander($token, [$orphelin['id']], ['fournisseur_id' => $repli['id']])
            ->assertCreated()->json('data');

        $this->assertCount(1, $data['commandes']);
        $this->assertSame('Centrale d\'achat', $data['commandes'][0]['fournisseur']['name']);
        $this->assertSame([], $data['ignores']);
    }

    public function test_un_article_deja_couvert_est_ecarte(): void
    {
        [$token] = $this->registerTenant();
        $fournisseur = $this->fournisseur($token, 'Sotrama');

        // Seuil à 20, réappro à 50, et 60 en stock : rien à commander.
        $produit = $this->produit($token, 'Ciment');
        $this->achatPasse($token, $fournisseur['id'], $produit['id'], quantite: 60);

        $data = $this->commander($token, [$produit['id']])->assertOk()->json('data');

        $this->assertSame([], $data['commandes']);
        $this->assertSame('rien_a_commander', $data['ignores'][0]['raison']);
    }

    /**
     * Une commande créée deux fois est un vrai dégât d'exploitation. Le second
     * envoi doit rendre la MÊME réponse, sans rien créer de plus.
     */
    public function test_le_meme_envoi_repete_ne_cree_pas_deux_commandes(): void
    {
        [$token] = $this->registerTenant();
        $fournisseur = $this->fournisseur($token, 'Sotrama');
        $produit = $this->produit($token, 'Ciment');
        $this->achatPasse($token, $fournisseur['id'], $produit['id']);

        $cle = (string) Str::uuid();
        $avant = DocumentAchat::query()->where('type', 'commande')->count();

        $premier = $this->commander($token, [$produit['id']], ['cle_idempotence' => $cle])
            ->assertCreated()->json('data');
        $second = $this->commander($token, [$produit['id']], ['cle_idempotence' => $cle])
            ->assertOk()->json('data');

        $this->assertSame($premier['commandes'][0]['code'], $second['commandes'][0]['code'], 'La même réponse est rendue.');
        $this->assertSame(
            $avant + 1,
            DocumentAchat::query()->where('type', 'commande')->count(),
            'Le renvoi de la même clé ne doit créer aucune commande supplémentaire.',
        );
    }

    /**
     * Deux clés différentes sur deux articles différents : chacune fait son
     * travail. Rejouer une clé n'est pas la même chose que commander à nouveau.
     */
    public function test_deux_cles_differentes_creent_bien_deux_commandes(): void
    {
        [$token] = $this->registerTenant();
        $fournisseur = $this->fournisseur($token, 'Sotrama');
        $ciment = $this->produit($token, 'Ciment');
        $sable = $this->produit($token, 'Sable');
        $this->achatPasse($token, $fournisseur['id'], $ciment['id']);
        $this->achatPasse($token, $fournisseur['id'], $sable['id']);

        $avant = DocumentAchat::query()->where('type', 'commande')->count();

        $this->commander($token, [$ciment['id']])->assertCreated();
        $this->commander($token, [$sable['id']])->assertCreated();

        $this->assertSame($avant + 2, DocumentAchat::query()->where('type', 'commande')->count());
    }

    /**
     * La commande est validée d'office : un brouillon ne compte pas dans
     * « en commande », donc la suggestion resterait inchangée à l'écran et le
     * clic suivant créerait un doublon pour la même marchandise.
     */
    public function test_la_commande_est_validee_et_pese_aussitot_sur_le_besoin(): void
    {
        [$token] = $this->registerTenant();
        $fournisseur = $this->fournisseur($token, 'Sotrama');
        $produit = $this->produit($token, 'Ciment');
        $this->achatPasse($token, $fournisseur['id'], $produit['id']);

        $data = $this->commander($token, [$produit['id']])->assertCreated()->json('data');
        $this->assertSame('valide', $data['commandes'][0]['statut']);

        // Le besoin est désormais couvert : un second passage n'a plus rien à
        // commander.
        $suivant = $this->commander($token, [$produit['id']])->assertOk()->json('data');
        $this->assertSame('rien_a_commander', $suivant['ignores'][0]['raison']);
    }

    public function test_seuls_les_roles_qui_ecrivent_sur_les_achats_peuvent_commander(): void
    {
        [$token, $tenantId] = $this->registerTenant();
        $fournisseur = $this->fournisseur($token, 'Sotrama');
        $produit = $this->produit($token, 'Ciment');
        $this->achatPasse($token, $fournisseur['id'], $produit['id']);

        foreach (['commercial', 'caissier', 'lecture', 'comptable'] as $role) {
            $jeton = User::factory()->create([
                'tenant_id' => $tenantId, 'role' => $role, 'is_active' => true,
            ])->createToken('spa')->plainTextToken;

            $this->commander($jeton, [$produit['id']])
                ->assertForbidden("Le rôle {$role} ne doit pas pouvoir commander.");
        }

        $this->assertSame(0, DocumentAchat::query()->where('type', 'commande')->where('notes', 'like', '%suivi%')->count());
    }

    public function test_un_article_d_une_autre_entreprise_est_refuse(): void
    {
        [$tokenA] = $this->registerTenant();

        $autre = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Beta', 'name' => 'Admin', 'email' => 'beta@test.ma', 'password' => 'password123',
        ])->assertCreated()->json('token');

        $produitB = $this->produit($autre, 'Article de B');

        $this->commander($tokenA, [$produitB['id']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('produit_ids.0');
    }
}
