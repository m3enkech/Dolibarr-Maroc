<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EFactureTest extends TestCase
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

    private function factureValidee(string $token): array
    {
        $client = $this->withToken($token)->postJson('/api/v1/tiers', [
            'name' => 'Client X', 'ice' => '001234567890123', 'if_number' => '55667788',
        ])->json('data');
        $facture = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [
                ['designation' => 'Prestation conseil', 'quantite' => 2, 'prix_unitaire' => 500, 'tva_rate' => 20],
            ],
        ])->json('data');

        // Renvoie le document validé (code définitif FA- au lieu de PROV-).
        return $this->withToken($token)
            ->postJson("/api/v1/ventes/documents/{$facture['id']}/valider")
            ->json('data');
    }

    public function test_efacture_produces_valid_ubl_xml(): void
    {
        $token = $this->registerTenant('MediaDesk', 'a@test.ma');
        $facture = $this->factureValidee($token);

        $response = $this->withToken($token)->get("/api/v1/ventes/documents/{$facture['id']}/efacture");
        $response->assertOk();
        $this->assertSame('application/xml', $response->headers->get('content-type'));

        $xml = $response->getContent();

        // XML bien formé + éléments UBL clés.
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Le XML doit être bien formé.');
        $this->assertStringContainsString('UBLVersionID', $xml);
        $this->assertStringContainsString($facture['code'], $xml);           // cbc:ID
        $this->assertStringContainsString('001234567890123', $xml);          // ICE client
        $this->assertStringContainsString('Prestation conseil', $xml);       // ligne

        // Namespace UBL Invoice.
        $ubl = new \SimpleXMLElement($xml);
        $ns = $ubl->getNamespaces(true);
        $this->assertContains('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $ns);

        // Totaux : 1000 HT / 200 TVA / 1200 TTC.
        $this->assertStringContainsString('>1000.00<', $xml);
        $this->assertStringContainsString('>1200.00<', $xml);
    }

    public function test_efacture_rejects_brouillon(): void
    {
        $token = $this->registerTenant('MediaDesk', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $brouillon = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'facture', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->json('data');

        $this->withToken($token)->get("/api/v1/ventes/documents/{$brouillon['id']}/efacture")
            ->assertStatus(422);
    }

    public function test_efacture_not_available_for_devis(): void
    {
        $token = $this->registerTenant('MediaDesk', 'a@test.ma');
        $client = $this->withToken($token)->postJson('/api/v1/tiers', ['name' => 'Client'])->json('data');
        $devis = $this->withToken($token)->postJson('/api/v1/ventes/documents', [
            'type' => 'devis', 'tiers_id' => $client['id'],
            'lignes' => [['designation' => 'X', 'quantite' => 1, 'prix_unitaire' => 100, 'tva_rate' => 20]],
        ])->json('data');

        $this->withToken($token)->get("/api/v1/ventes/documents/{$devis['id']}/efacture")
            ->assertNotFound();
    }
}
