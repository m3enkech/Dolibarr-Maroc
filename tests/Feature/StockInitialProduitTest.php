<?php

namespace Tests\Feature;

use App\Core\Auth\Roles;
use App\Models\User;
use App\Modules\Stock\Models\MouvementStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stock de départ saisi à la création d'un article.
 *
 * La promesse tenue par ces tests : un article créé avec une quantité arrive
 * en rayon avec CETTE quantité, dans le bon dépôt — ou n'est pas créé du tout.
 * Jamais un article sans le stock qu'on croyait lui avoir donné, ni un numéro
 * de séquence consommé par une création avortée.
 */
class StockInitialProduitTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: int, 2: int} jeton, entreprise, administrateur */
    private function entreprise(string $nom, string $email): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => $nom, 'name' => 'Admin '.$nom,
            'email' => $email, 'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), $res->json('tenant.id'), $res->json('user.id')];
    }

    private function depot(string $token, string $nom, array $extra = []): int
    {
        return $this->withToken($token)
            ->postJson('/api/v1/stock/entrepots', ['name' => $nom, ...$extra])
            ->assertCreated()->json('data.id');
    }

    private function article(array $extra = []): array
    {
        return [
            'name' => 'Ciment 50kg', 'type' => 'product', 'sell_price' => 85, 'tva_rate' => 20,
            'unit' => 'sac', ...$extra,
        ];
    }

    private function creer(string $token, array $extra = [])
    {
        return $this->withToken($token)->postJson('/api/v1/produits', $this->article($extra));
    }

    /** Rien n'est resté d'une création refusée : ni article, ni stock, ni mouvement, ni numéro. */
    private function assertAucuneTrace(): void
    {
        $this->assertSame(0, DB::table('produits')->count());
        $this->assertSame(0, DB::table('stocks')->count());
        $this->assertSame(0, DB::table('mouvements_stock')->count());
        $this->assertNull(DB::table('sequences')->where('code', 'PR')->value('counter'));
    }

    public function test_le_stock_initial_entre_dans_le_depot_choisi(): void
    {
        [$token, , $adminId] = $this->entreprise('Acme', 'admin@acme.ma');
        $casablanca = $this->depot($token, 'Casablanca'); // premier, donc par défaut
        $agadir = $this->depot($token, 'Agadir');

        $produit = $this->creer($token, ['stock_initial' => 12.5, 'entrepot_id' => $agadir])
            ->assertCreated()
            ->json('data');

        $stocks = DB::table('stocks')->where('produit_id', $produit['id'])->get();
        $this->assertCount(1, $stocks);
        $this->assertSame($agadir, (int) $stocks[0]->entrepot_id);
        $this->assertEqualsWithDelta(12.5, (float) $stocks[0]->quantite, 0.0001);

        $mouvement = DB::table('mouvements_stock')->where('produit_id', $produit['id'])->sole();
        $this->assertSame(MouvementStock::TYPE_ENTREE, $mouvement->type);
        $this->assertSame($agadir, (int) $mouvement->entrepot_id);
        $this->assertEqualsWithDelta(12.5, (float) $mouvement->quantite, 0.0001);
        $this->assertEqualsWithDelta(12.5, (float) $mouvement->quantite_apres, 0.0001);
        $this->assertSame('Stock initial', $mouvement->note);
        $this->assertSame($produit['code'], $mouvement->reference);
        $this->assertSame($adminId, (int) $mouvement->user_id);
        $this->assertNull($mouvement->document_vente_id);

        // Visible tel quel dans les écrans du stock : Agadir porte la quantité,
        // Casablanca — le dépôt par défaut — n'a rien reçu.
        $depots = collect($this->withToken($token)
            ->getJson("/api/v1/stock/produits/{$produit['id']}/depots")
            ->assertOk()
            ->json('data.depots'))->keyBy('entrepot_id');
        $this->assertSame('12.500', $depots[$agadir]['quantite']);
        $this->assertSame('0.000', $depots[$casablanca]['quantite']);

        $this->withToken($token)->getJson('/api/v1/stock/niveaux')
            ->assertOk()
            ->assertJsonPath('data.0.quantite', '12.500');
    }

    public function test_sans_stock_initial_rien_ne_change(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');

        $this->creer($token)->assertCreated();
        $this->creer($token, ['stock_initial' => null, 'entrepot_id' => null])->assertCreated();

        $this->assertSame(2, DB::table('produits')->count());
        $this->assertSame(0, DB::table('stocks')->count());
        $this->assertSame(0, DB::table('mouvements_stock')->count());
        // Créer un article sans stock ne crée pas, au passage, de dépôt.
        $this->assertSame(0, DB::table('entrepots')->count());
    }

    public function test_sans_depot_designe_le_stock_va_au_depot_par_defaut(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');
        $this->depot($token, 'Casablanca');
        // Le second devient le dépôt par défaut : ni le premier, ni le plus ancien.
        $rabat = $this->depot($token, 'Rabat', ['is_default' => true]);
        $this->depot($token, 'Tanger');

        $produit = $this->creer($token, ['stock_initial' => 40])->assertCreated()->json('data');

        $this->assertSame($rabat, (int) DB::table('mouvements_stock')->where('produit_id', $produit['id'])->value('entrepot_id'));
        $this->assertSame($rabat, (int) DB::table('stocks')->where('produit_id', $produit['id'])->value('entrepot_id'));
    }

    public function test_sans_aucun_depot_le_depot_principal_est_cree(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');

        $produit = $this->creer($token, ['stock_initial' => 3])->assertCreated()->json('data');

        $depot = DB::table('entrepots')->sole();
        $this->assertSame('Entrepôt principal', $depot->name);
        $this->assertTrue((bool) $depot->is_default);
        $this->assertSame((int) $depot->id, (int) DB::table('stocks')->where('produit_id', $produit['id'])->value('entrepot_id'));
    }

    public function test_le_depot_d_une_autre_entreprise_est_refuse(): void
    {
        [$tokenB] = $this->entreprise('Beta', 'admin@beta.ma');
        $depotB = $this->depot($tokenB, 'Dépôt Beta');

        [$tokenA] = $this->entreprise('Acme', 'admin@acme.ma');
        $this->depot($tokenA, 'Dépôt Acme');

        $this->creer($tokenA, ['stock_initial' => 5, 'entrepot_id' => $depotB])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entrepot_id' => 'Ce dépôt est introuvable.']);

        // Ni article chez Acme, ni stock déposé chez Beta.
        $this->assertSame(0, DB::table('produits')->count());
        $this->assertSame(0, DB::table('stocks')->count());
        $this->assertSame(0, DB::table('mouvements_stock')->count());
    }

    public function test_un_depot_desactive_est_refuse_meme_par_defaut(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');
        $principal = $this->depot($token, 'Principal');
        $ferme = $this->depot($token, 'Fermé', ['is_active' => false]);

        $this->creer($token, ['stock_initial' => 5, 'entrepot_id' => $ferme])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entrepot_id' => 'Ce dépôt est désactivé : choisissez un dépôt actif.']);

        // Le dépôt par défaut lui-même désactivé : refusé à la création, pas
        // de stock posé dans un dépôt que les écrans ne listent plus.
        $this->withToken($token)->putJson("/api/v1/stock/entrepots/{$principal}", ['is_active' => false])->assertOk();

        $this->creer($token, ['stock_initial' => 5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entrepot_id' => 'Ce dépôt est désactivé : choisissez un dépôt actif.']);

        $this->assertAucuneTrace();
    }

    public function test_un_service_ou_un_kit_ne_recoit_pas_de_stock_initial(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');

        foreach (['service', 'kit'] as $type) {
            $this->creer($token, ['type' => $type, 'stock_initial' => 5])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([
                    'stock_initial' => 'Seul un produit a un stock : un service ou un kit ne reçoit pas de stock initial.',
                ]);
        }

        $this->assertSame(0, DB::table('produits')->count());
        $this->assertSame(0, DB::table('mouvements_stock')->count());
    }

    public function test_les_quantites_invalides_sont_refusees(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');
        $this->depot($token, 'Casablanca');

        // Des paires, pas un tableau associatif : PHP changerait '1.2345' et
        // '1000000000' en clés, et le second en entier.
        $cas = [
            [0, 'Le stock initial doit être supérieur à zéro.'],
            [-5, 'Le stock initial doit être supérieur à zéro.'],
            [1.2345, 'Le stock initial accepte au plus 3 décimales.'],
            ['1.2345', 'Le stock initial accepte au plus 3 décimales.'],
            ['beaucoup', 'Le stock initial doit être un nombre.'],
            [1000000000, 'Le stock initial ne peut pas dépasser 999 999 999.'],
            [999999999.5, 'Le stock initial ne peut pas dépasser 999 999 999.'],
        ];

        foreach ($cas as [$quantite, $message]) {
            $this->creer($token, ['stock_initial' => $quantite])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['stock_initial' => $message]);
        }

        $this->assertAucuneTrace();

        // Trois décimales passent, et sont conservées telles quelles.
        $produit = $this->creer($token, ['stock_initial' => '2.125'])->assertCreated()->json('data');
        $this->assertEqualsWithDelta(2.125, (float) DB::table('stocks')->where('produit_id', $produit['id'])->value('quantite'), 0.0001);
    }

    public function test_un_depot_sans_quantite_est_refuse(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');
        $depot = $this->depot($token, 'Casablanca');

        $this->creer($token, ['entrepot_id' => $depot])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'entrepot_id' => 'Le dépôt ne sert qu\'au stock initial : indiquez une quantité, ou retirez le dépôt.',
            ]);

        $this->assertSame(0, DB::table('produits')->count());
    }

    public function test_sans_droit_d_ecrire_le_stock_l_article_n_est_pas_cree(): void
    {
        [, $tenantId] = $this->entreprise('Acme', 'admin@acme.ma');

        // Aucun rôle du catalogue n'associe aujourd'hui catalogue en écriture
        // et stock en lecture : la doublure retire au manager le seul droit
        // d'écrire le stock, pour que la garde reste vraie si la matrice change.
        $user = User::factory()->create(['tenant_id' => $tenantId, 'role' => Roles::MANAGER, 'is_active' => true]);
        $token = UtilisateurCatalogueSansStock::findOrFail($user->id)->createToken('spa')->plainTextToken;

        $this->creer($token, ['stock_initial' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_initial' => 'Vous n\'avez pas le droit d\'alimenter le stock.']);

        $this->assertAucuneTrace();

        // Le même utilisateur crée bien l'article sans stock : seul le champ
        // est refusé, pas le catalogue.
        $this->creer($token)->assertCreated();
        // Le message suit la langue de l'utilisateur, comme le reste de l'API.
        $this->withToken($token)
            ->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/produits', $this->article(['stock_initial' => 10]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_initial' => 'لا تتوفر على صلاحية تزويد المخزون.']);
    }

    public function test_une_entree_en_echec_annule_l_article(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');

        // Panne au tout dernier pas, l'écriture du mouvement : la ligne de
        // stock a déjà été augmentée, et le dépôt principal créé à la volée.
        MouvementStock::creating(function (): never {
            throw new \RuntimeException('Panne simulée de l\'écriture du mouvement.');
        });

        $this->creer($token, ['stock_initial' => 10])->assertStatus(500);

        $this->assertAucuneTrace();
        $this->assertSame(0, DB::table('entrepots')->count());
        $this->assertNull(DB::table('sequences')->where('code', 'EN')->value('counter'));

        // Le numéro n'a pas été consommé : la création suivante reprend au premier.
        $this->creer($token)->assertCreated()->assertJsonPath('data.code', 'PR-'.now()->year.'-00001');
    }

    public function test_la_modification_refuse_le_stock_initial(): void
    {
        [$token] = $this->entreprise('Acme', 'admin@acme.ma');
        $depot = $this->depot($token, 'Casablanca');
        $produit = $this->creer($token, ['stock_initial' => 7])->assertCreated()->json('data');

        $message = 'Le stock d\'un article existant se corrige par un mouvement ou un inventaire.';

        $this->withToken($token)->putJson("/api/v1/produits/{$produit['id']}", ['stock_initial' => 50])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_initial' => $message]);

        $this->withToken($token)->putJson("/api/v1/produits/{$produit['id']}", ['entrepot_id' => $depot])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entrepot_id' => $message]);

        // Le stock n'a pas bougé : toujours la seule entrée de création.
        $this->assertSame(1, DB::table('mouvements_stock')->count());
        $this->assertEqualsWithDelta(7.0, (float) DB::table('stocks')->where('produit_id', $produit['id'])->value('quantite'), 0.0001);

        // Une modification ordinaire passe, et ne touche pas davantage au stock.
        $this->withToken($token)->putJson("/api/v1/produits/{$produit['id']}", ['name' => 'Ciment 25kg', 'stock_initial' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ciment 25kg');
        $this->assertSame(1, DB::table('mouvements_stock')->count());
    }
}

/**
 * Un manager privé du seul droit d'écrire le stock. Sanctum instancie la
 * classe enregistrée sur le jeton : la requête voit donc cette doublure, et la
 * matrice des rôles reste intacte pour tous les autres tests.
 */
class UtilisateurCatalogueSansStock extends User
{
    protected $table = 'users';

    public function hasPermission(string $domaine, string $action = Roles::READ): bool
    {
        if ($domaine === 'stock' && $action === Roles::WRITE) {
            return false;
        }

        return parent::hasPermission($domaine, $action);
    }
}
