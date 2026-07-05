<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class TvaExportTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $company, string $email): string
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'company_name' => $company,
            'name' => 'Admin '.$company,
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertCreated();

        return $response->json('token');
    }

    /** Facture de vente 1200 TTC validée et encaissée par virement, datée ce mois-ci. */
    private function venteEncaissee(string $token): void
    {
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client Vente'])->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'date_document' => now()->toDateString(),
            'lignes' => [['designation' => 'Prestation', 'quantite' => 1, 'prix_unitaire' => 1000, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")->assertOk();
        $this->withToken($token)->postJson("/api/v1/ventes/documents/{$facture['id']}/paiements", [
            'montant' => 1200, 'mode' => 'virement', 'date_paiement' => now()->toDateString(),
        ])->assertOk();
    }

    /** Facture fournisseur 960 TTC validée et payée par chèque, datée ce mois-ci. */
    private function achatPaye(string $token): void
    {
        $fournisseur = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Fournisseur Achat', 'is_client' => false, 'is_supplier' => true,
            'if_number' => '12345678', 'ice' => '001234567890123',
        ])->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/achats/documents', [
            'type' => 'facture', 'tiers_id' => $fournisseur['id'],
            'ref_fournisseur' => 'F-2026-999', 'date_document' => now()->toDateString(),
            'lignes' => [['designation' => 'Fournitures', 'quantite' => 1, 'prix_unitaire' => 800, 'tva_rate' => 20]],
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/valider")->assertOk();
        $this->withToken($token)->postJson("/api/v1/achats/documents/{$facture['id']}/paiements", [
            'montant' => 960, 'mode' => 'cheque', 'date_paiement' => now()->toDateString(),
        ])->assertOk();
    }

    private function chargerExport(string $token, string $mois)
    {
        $response = $this->withToken($token)->get("/api/v1/compta/tva/export?mois={$mois}");
        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'tva').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $ss = IOFactory::createReader('Xlsx')->load($tmp);
        unlink($tmp);

        return $ss;
    }

    public function test_export_produces_two_sheets_with_dgi_headers(): void
    {
        $token = $this->registerTenant('Atlas Négoce', 'a@test.ma');
        $this->venteEncaissee($token);
        $this->achatPaye($token);

        $ss = $this->chargerExport($token, now()->format('Y-m'));

        // Feuille EDI : en-tête modèle + colonnes du relevé de déductions.
        $edi = $ss->getSheetByName('EDI');
        $this->assertNotNull($edi);
        $this->assertSame('Modèle n° ADC082F-15I', $edi->getCell('L1')->getValue());
        $this->assertSame('RAISON SOCIAL', $edi->getCell('A2')->getValue());
        $this->assertSame('Atlas Négoce', $edi->getCell('C2')->getValue());
        $this->assertSame('OR', $edi->getCell('A8')->getValue());
        $this->assertSame('FACT_NUM', $edi->getCell('B8')->getValue());
        $this->assertSame('ICE_FRS', $edi->getCell('I8')->getValue());
        $this->assertSame('DATE_PAIE', $edi->getCell('L8')->getValue());

        // Feuille CA nommée d'après le mois.
        $ca = $ss->getSheetByName('CA'.now()->format('mY'));
        $this->assertNotNull($ca);
        $this->assertSame('NUM DE FACTURE', $ca->getCell('B1')->getValue());
        $this->assertSame('MOYEN DE REGLEMENT', $ca->getCell('D1')->getValue());
    }

    public function test_releve_deductions_lists_paid_supplier_invoice(): void
    {
        $token = $this->registerTenant('Atlas Négoce', 'a@test.ma');
        $this->achatPaye($token);

        $edi = $this->chargerExport($token, now()->format('Y-m'))->getSheetByName('EDI');

        // Ligne 9 : la facture fournisseur payée ce mois.
        $this->assertSame('F-2026-999', $edi->getCell('B9')->getValue());
        $this->assertSame('MARCHANDISE', $edi->getCell('C9')->getValue());
        $this->assertEqualsWithDelta(800, (float) $edi->getCell('D9')->getValue(), 0.01);   // HT
        $this->assertEqualsWithDelta(160, (float) $edi->getCell('E9')->getValue(), 0.01);   // TVA
        $this->assertEqualsWithDelta(960, (float) $edi->getCell('F9')->getValue(), 0.01);   // TTC
        $this->assertSame('12345678', $edi->getCell('G9')->getValue());                      // IF
        $this->assertSame('Fournisseur Achat', $edi->getCell('H9')->getValue());             // LIB_FRSS
        $this->assertSame('001234567890123', $edi->getCell('I9')->getValue());               // ICE_FRS
        $this->assertSame(20, $edi->getCell('J9')->getValue());                              // TAUX
        $this->assertSame(2, $edi->getCell('K9')->getValue());                               // ID_PAIE chèque = 2

        // Ligne total.
        $this->assertSame('Total', $edi->getCell('C10')->getValue());
        $this->assertEqualsWithDelta(160, (float) $edi->getCell('E10')->getValue(), 0.01);
    }

    public function test_chiffre_affaires_lists_sales_invoices(): void
    {
        $token = $this->registerTenant('Atlas Négoce', 'a@test.ma');
        $this->venteEncaissee($token);

        $ca = $this->chargerExport($token, now()->format('Y-m'))->getSheetByName('CA'.now()->format('mY'));

        $this->assertSame('Client Vente', $ca->getCell('C2')->getValue());
        $this->assertSame('VIREMENT', $ca->getCell('D2')->getValue());
        $this->assertEqualsWithDelta(1200, (float) $ca->getCell('E2')->getValue(), 0.01); // TTC
        $this->assertEqualsWithDelta(1000, (float) $ca->getCell('F2')->getValue(), 0.01); // HT
        $this->assertEqualsWithDelta(200, (float) $ca->getCell('G2')->getValue(), 0.01);  // TVA
        $this->assertSame('Total', $ca->getCell('C3')->getValue());
    }

    public function test_export_only_covers_requested_month(): void
    {
        $token = $this->registerTenant('Atlas Négoce', 'a@test.ma');
        $this->venteEncaissee($token); // ce mois-ci

        // Un mois sans activité : CA sans ligne (total en ligne 2).
        $moisVide = now()->subYear()->format('Y-m');
        $ca = $this->chargerExport($token, $moisVide)->getSheetByName('CA'.now()->subYear()->format('mY'));
        $this->assertSame('Total', $ca->getCell('C2')->getValue());
    }

    public function test_export_is_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Atlas Négoce', 'a@test.ma');
        $tokenB = $this->registerTenant('Rif Distribution', 'b@test.ma');
        $this->achatPaye($tokenA);

        // Le tenant B n'a aucune déduction ce mois : ligne 9 vide, total en ligne 9.
        $edi = $this->chargerExport($tokenB, now()->format('Y-m'))->getSheetByName('EDI');
        $this->assertNull($edi->getCell('B9')->getValue());
        $this->assertSame('Total', $edi->getCell('C9')->getValue());
    }

    public function test_export_requires_valid_month(): void
    {
        $token = $this->registerTenant('Atlas Négoce', 'a@test.ma');
        $this->withToken($token)->getJson('/api/v1/compta/tva/export?mois=2026')
            ->assertUnprocessable(); // format de mois invalide (attendu YYYY-MM)
    }
}
