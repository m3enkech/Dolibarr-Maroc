<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalogue\Models\Produit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isolation multi-entreprises : sans tenant courant, une requête ne doit RIEN
 * renvoyer plutôt que TOUT.
 *
 * Le portail acheteur sert des requêtes qui n'ont aucun utilisateur d'entreprise
 * : si le scope global se contentait de ne pas filtrer, la moindre erreur de
 * câblage exposerait le catalogue et les commandes de tous les grossistes.
 */
class TenancyFailClosedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée le jeu de données SANS passer par HTTP : aucune requête ne laisse
     * d'utilisateur authentifié derrière elle, donc le repli
     * `auth()->user()->tenant_id` ne masque pas ce que l'on veut mesurer.
     */
    private function tenantAvecProduit(string $company, string $slug, string $produit): Tenant
    {
        $tenant = Tenant::create(['name' => $company, 'slug' => $slug]);

        app(TenantContext::class)->runAs($tenant, fn () => Produit::create([
            'code' => 'PR-'.$slug, 'name' => $produit, 'type' => 'product',
            'sell_price' => 10, 'tva_rate' => 20, 'is_active' => true,
        ]));

        return $tenant;
    }

    private function sansContexte(): void
    {
        app(TenantContext::class)->clear();
        auth()->forgetUser();
    }

    public function test_sans_tenant_courant_aucune_donnee_ne_remonte(): void
    {
        $this->tenantAvecProduit('Grossiste A', 'gros-a', 'Article A');
        $this->tenantAvecProduit('Grossiste B', 'gros-b', 'Article B');

        // Aucun contexte, aucun utilisateur : la requête ne doit rien renvoyer.
        $this->sansContexte();

        $this->assertSame(
            0,
            Produit::query()->count(),
            'Sans tenant courant, le scope global doit tout fermer, pas tout ouvrir.',
        );
    }

    public function test_le_contexte_pose_explicitement_filtre_bien(): void
    {
        $a = $this->tenantAvecProduit('Grossiste A', 'gros-a', 'Article A');
        $this->tenantAvecProduit('Grossiste B', 'gros-b', 'Article B');

        app(TenantContext::class)->set($a);

        $produits = Produit::query()->pluck('name');
        $this->assertCount(1, $produits);
        $this->assertSame('Article A', $produits->first());
    }

    public function test_run_as_restaure_le_contexte_meme_en_cas_d_erreur(): void
    {
        $a = $this->tenantAvecProduit('Grossiste A', 'gros-a', 'Article A');
        $b = $this->tenantAvecProduit('Grossiste B', 'gros-b', 'Article B');

        $context = app(TenantContext::class);
        $context->set($a);

        try {
            $context->runAs($b, function () {
                throw new \RuntimeException('boum');
            });
        } catch (\RuntimeException) {
            // attendu
        }

        // Le contexte d'origine doit être rétabli malgré l'exception.
        $this->assertSame($a->id, $context->id());
    }

    public function test_run_global_autorise_explicitement_une_lecture_cross_tenant(): void
    {
        $this->tenantAvecProduit('Grossiste A', 'gros-a', 'Article A');
        $this->tenantAvecProduit('Grossiste B', 'gros-b', 'Article B');

        $this->sansContexte();

        // La vitrine de la place de marché lira ainsi, de façon DÉLIBÉRÉE.
        $total = app(TenantContext::class)->runGlobal(fn () => Produit::query()->count());

        $this->assertSame(2, $total);

        // Et l'autorisation ne fuit pas hors du bloc.
        $this->assertSame(0, Produit::query()->count());
    }

    public function test_ecrire_sans_tenant_leve_une_erreur_lisible(): void
    {
        $this->tenantAvecProduit('Grossiste A', 'gros-a', 'Article A');

        $this->sansContexte();

        $this->expectException(\RuntimeException::class);

        Produit::create([
            'code' => 'PR-X', 'name' => 'Orphelin', 'type' => 'product',
            'sell_price' => 1, 'tva_rate' => 20,
        ]);
    }
}
