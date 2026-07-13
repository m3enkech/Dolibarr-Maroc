<?php

namespace Tests\Feature;

use App\Core\Tenancy\Tenant;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Facturation d'abonnement : l'encaissement enregistré par le superadmin émet
 * une facture (vente de service) dans la compta de l'opérateur et la met à
 * disposition de l'abonné en PDF.
 */
class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    private function entreprise(string $company, string $email): array
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company, 'name' => 'Admin', 'email' => $email, 'password' => 'password123',
        ])->assertCreated();

        return [$res->json('token'), Tenant::find($res->json('tenant.id'))];
    }

    public function test_encaissement_emet_une_facture_dans_la_compta_de_l_operateur(): void
    {
        [$superToken, $operateur] = $this->entreprise('MediaDesk', 'super@media.ma');
        $operateur->users()->first()->update(['is_superadmin' => true]);

        [, $abonne] = $this->entreprise('Client Beta', 'beta@client.ma');
        $abonne->update(['plan' => 'business']); // 249 HT / mois

        // Encaissement TTC (249 * 1.2 = 298.80).
        $this->withToken($superToken)->postJson("/api/v1/superadmin/tenants/{$abonne->id}/paiements", [
            'amount' => 298.80, 'method' => 'cmi', 'reference' => 'CMI-9',
        ])->assertOk();

        // Une facture existe chez l'opérateur, pour un tiers "Client Beta".
        $facture = DocumentVente::withoutGlobalScopes()
            ->where('tenant_id', $operateur->id)->where('type', 'facture')->with('tiers')->first();
        $this->assertNotNull($facture);
        $this->assertSame('Client Beta', $facture->tiers->name);
        $this->assertSame('298.80', $facture->total_ttc);
        $this->assertSame('249.00', $facture->total_ht);

        // Écritures VT + BQ générées dans la compta de l'opérateur.
        $this->assertTrue(
            Ecriture::withoutGlobalScopes()->where('tenant_id', $operateur->id)->where('journal', 'VT')->exists(),
        );
        $this->assertTrue(
            Ecriture::withoutGlobalScopes()->where('tenant_id', $operateur->id)->where('journal', 'BQ')->exists(),
        );

        // Le paiement d'abonnement est lié à la facture.
        $this->assertSame(1, $abonne->payments()->whereNotNull('document_vente_id')->count());
    }

    public function test_l_abonne_voit_et_telecharge_sa_facture(): void
    {
        [$superToken, $operateur] = $this->entreprise('MediaDesk', 'super@media.ma');
        $operateur->users()->first()->update(['is_superadmin' => true]);

        [$betaToken, $abonne] = $this->entreprise('Client Beta', 'beta@client.ma');
        $abonne->update(['plan' => 'essentiel']);

        $this->withToken($superToken)->postJson("/api/v1/superadmin/tenants/{$abonne->id}/paiements", [
            'amount' => 118.80, 'method' => 'virement',
        ])->assertOk();

        // Côté abonné : sa facture apparaît.
        $res = $this->withToken($betaToken)->getJson('/api/v1/abonnement')->assertOk();
        $res->assertJsonPath('data.subscription.status', 'actif')
            ->assertJsonPath('data.factures.0.has_invoice', true);
        $paymentId = $res->json('data.factures.0.id');

        // Téléchargement du PDF.
        $this->withToken($betaToken)->get("/api/v1/abonnement/factures/{$paymentId}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Une autre entreprise ne peut pas y accéder.
        [$autreToken] = $this->entreprise('Intrus', 'x@intrus.ma');
        $this->withToken($autreToken)->get("/api/v1/abonnement/factures/{$paymentId}/pdf")->assertNotFound();
    }
}
