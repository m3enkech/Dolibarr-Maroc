<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le filtre d'échéance de la liste des factures.
 *
 * « Échue » veut dire DUE ET DÉPASSÉE. Une facture soldée bascule en `paye`,
 * donc `valide` signifie déjà « due » — même définition que les compteurs de
 * l'écran de suivi, sans quoi deux écrans donneraient deux nombres pour la même
 * question et personne ne saurait lequel croire.
 */
class EcheanceFacturesTest extends TestCase
{
    use RefreshDatabase;

    private string $jeton;

    private Tiers $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Media Desk', 'name' => 'Admin',
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->assertCreated();

        $this->jeton = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@mediadesk.ma', 'password' => 'password123',
        ])->json('token');

        app(TenantContext::class)->set(
            User::withoutGlobalScopes()->firstWhere('email', 'admin@mediadesk.ma')->tenant,
        );

        $this->client = app(TiersService::class)->create([
            'name' => 'CLIM & COOL', 'is_client' => true, 'is_supplier' => false,
        ]);
    }

    private function facture(?string $echeance, bool $soldee = false): DocumentVente
    {
        $doc = app(VenteService::class)->valider(app(VenteService::class)->create([
            'type' => DocumentVente::TYPE_FACTURE,
            'tiers_id' => $this->client->id,
            'date_document' => now()->subMonth()->toDateString(),
            'date_echeance' => $echeance,
            'lignes' => [['designation' => 'Toner', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ]));

        if ($soldee) {
            app(VenteService::class)->ajouterPaiement($doc, ['montant' => 120, 'mode' => 'virement']);
        }

        return $doc->refresh();
    }

    /** @return list<string> Les codes rendus par la liste. */
    private function codes(string $echeance): array
    {
        return collect(
            $this->withToken($this->jeton)
                ->getJson('/api/v1/ventes/documents?type=facture&echeance='.$echeance)
                ->assertOk()
                ->json('data'),
        )->pluck('code')->all();
    }

    /* ---------------------------------------------------------------- */

    public function test_le_filtre_echue_ne_rend_que_les_depassees_et_non_soldees(): void
    {
        $depassee = $this->facture(now()->subDays(10)->toDateString());
        $this->facture(now()->addDays(10)->toDateString());

        $this->assertSame([$depassee->code], $this->codes('echue'));
    }

    /**
     * LA confusion à éviter : une facture PAYÉE dont la date est passée n'est
     * pas « échue », elle est réglée. L'afficher comme échue ferait relancer un
     * client qui ne doit rien.
     */
    public function test_une_facture_soldee_n_est_jamais_echue(): void
    {
        $this->facture(now()->subDays(10)->toDateString(), soldee: true);

        $this->assertSame([], $this->codes('echue'));
    }

    public function test_une_facture_sans_echeance_n_est_ni_echue_ni_a_echoir(): void
    {
        $this->facture(null);

        $this->assertSame([], $this->codes('echue'));
        $this->assertSame([], $this->codes('a_echoir'));
    }

    public function test_le_filtre_a_echoir_rend_celles_qui_restent_a_courir(): void
    {
        $this->facture(now()->subDays(10)->toDateString());
        $aVenir = $this->facture(now()->addDays(10)->toDateString());

        $this->assertSame([$aVenir->code], $this->codes('a_echoir'));
    }

    /** Une échéance tombant AUJOURD'HUI n'est pas encore dépassée. */
    public function test_une_echeance_du_jour_reste_a_echoir(): void
    {
        $aujourdhui = $this->facture(now()->toDateString());

        $this->assertSame([], $this->codes('echue'));
        $this->assertSame([$aujourdhui->code], $this->codes('a_echoir'));
    }

    /** L'échéance voyage jusqu'à l'écran : sans elle, rien à décompter. */
    public function test_la_date_d_echeance_est_rendue_par_l_api(): void
    {
        $this->facture(now()->addDays(30)->toDateString());

        $facture = $this->withToken($this->jeton)
            ->getJson('/api/v1/ventes/documents?type=facture')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(now()->addDays(30)->toDateString(), $facture['date_echeance']);
    }
}
