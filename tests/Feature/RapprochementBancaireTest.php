<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Rapprochement bancaire : import d'un relevé, pointage automatique par montant,
 * pointage/dépointage manuel et calcul du solde rapproché.
 */
class RapprochementBancaireTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** @var array<string,int> code de compte → id */
    private array $comptes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Banque Test', 'name' => 'Admin', 'email' => 'a@bank.ma', 'password' => 'password123',
        ])->json('token');

        // Le plan comptable est amorcé au 1er accès compta.
        $comptes = $this->withToken($this->token)->getJson('/api/v1/compta/comptes')->json('data');
        foreach ($comptes as $c) {
            $this->comptes[$c['code']] = $c['id'];
        }
    }

    private function ecriture(string $date, array $lignes): void
    {
        $this->withToken($this->token)->postJson('/api/v1/compta/ecritures', [
            'date_ecriture' => $date,
            'libelle' => 'Mouvement banque',
            'lignes' => $lignes,
        ])->assertCreated();
    }

    private function releveCsv(string $contenu): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('releve.csv', $contenu);
    }

    public function test_import_auto_pointage_et_solde_rapproche(): void
    {
        $banque = $this->comptes['5141'];
        $clients = $this->comptes['3421'];
        $frais = $this->comptes['6147'] ?? $this->comptes['6111'];

        // Deux mouvements sur la banque, en janvier : +1000 (encaissement), −200 (frais).
        $this->ecriture('2026-01-05', [
            ['compte_id' => $banque, 'debit' => 1000, 'credit' => 0],
            ['compte_id' => $clients, 'debit' => 0, 'credit' => 1000],
        ]);
        $this->ecriture('2026-01-06', [
            ['compte_id' => $frais, 'debit' => 200, 'credit' => 0],
            ['compte_id' => $banque, 'debit' => 0, 'credit' => 200],
        ]);

        // Le compte 5141 est proposé au rapprochement (il a des mouvements).
        $index = $this->withToken($this->token)->getJson('/api/v1/compta/rapprochement')->assertOk();
        $this->assertContains('5141', collect($index->json('data.comptes'))->pluck('code')->all());

        // Relevé : encaissement 1000, frais 200, + des agios 50 pas encore comptabilisés.
        $csv = "Date,Libelle,Debit,Credit\n"
            ."2026-01-05,Virement recu,,1000\n"
            ."2026-01-06,Frais tenue compte,200,\n"
            ."2026-01-07,Agios,50,\n";

        $import = $this->withToken($this->token)->post('/api/v1/compta/rapprochement/import', [
            'compte_id' => $banque,
            'fichier' => $this->releveCsv($csv),
            'date_fin' => '2026-01-31',
            'solde_final' => 750,
        ])->assertCreated();

        $statementId = $import->json('data.statement.id');
        $this->assertCount(3, $import->json('data.lignes'));
        $this->assertSame('800.00', $import->json('data.soldes.comptable')); // 1000 − 200

        // Pointage auto : les 2 lignes qui ont une écriture correspondante.
        $auto = $this->withToken($this->token)->postJson("/api/v1/compta/rapprochement/{$statementId}/auto")->assertOk();
        $this->assertSame(2, $auto->json('pointees'));

        // Solde rapproché = 800 + (−50 agios non pointés) − 0 = 750 = solde du relevé → écart nul.
        $this->assertSame('750.00', $auto->json('data.soldes.rapproche'));
        $this->assertSame('0.00', $auto->json('data.soldes.ecart'));
        $this->assertSame('-50.00', $auto->json('data.soldes.releve_non_pointe'));
    }

    public function test_pointage_manuel_refuse_montant_different_puis_depointage(): void
    {
        $banque = $this->comptes['5141'];
        $clients = $this->comptes['3421'];

        $this->ecriture('2026-02-10', [
            ['compte_id' => $banque, 'debit' => 500, 'credit' => 0],
            ['compte_id' => $clients, 'debit' => 0, 'credit' => 500],
        ]);

        $csv = "Date,Libelle,Debit,Credit\n2026-02-10,Encaissement,,500\n2026-02-11,Autre,,300\n";
        $import = $this->withToken($this->token)->post('/api/v1/compta/rapprochement/import', [
            'compte_id' => $banque, 'fichier' => $this->releveCsv($csv), 'solde_final' => 500,
        ])->assertCreated();

        $etat = $import->json('data');
        $ligne500 = collect($etat['lignes'])->firstWhere('montant', '500.00')['id'];
        $ligne300 = collect($etat['lignes'])->firstWhere('montant', '300.00')['id'];
        $ecritureId = $etat['ecritures_non_pointees'][0]['id'];

        // Montant différent (300 sur une écriture de 500) → refus.
        $this->withToken($this->token)->postJson("/api/v1/compta/rapprochement/lignes/{$ligne300}/pointer", [
            'ecriture_ligne_id' => $ecritureId,
        ])->assertUnprocessable();

        // Montant égal → OK.
        $ok = $this->withToken($this->token)->postJson("/api/v1/compta/rapprochement/lignes/{$ligne500}/pointer", [
            'ecriture_ligne_id' => $ecritureId,
        ])->assertOk();
        $this->assertTrue(collect($ok->json('data.lignes'))->firstWhere('id', $ligne500)['rapprochee']);

        // Dépointage.
        $apres = $this->withToken($this->token)->postJson("/api/v1/compta/rapprochement/lignes/{$ligne500}/depointer")->assertOk();
        $this->assertFalse(collect($apres->json('data.lignes'))->firstWhere('id', $ligne500)['rapprochee']);
    }

    public function test_releve_d_un_autre_tenant_inaccessible(): void
    {
        $banque = $this->comptes['5141'];
        $csv = "Date,Libelle,Debit,Credit\n2026-03-01,X,,100\n";
        $statementId = $this->withToken($this->token)->post('/api/v1/compta/rapprochement/import', [
            'compte_id' => $banque, 'fichier' => $this->releveCsv($csv), 'solde_final' => 100,
        ])->assertCreated()->json('data.statement.id');

        $autre = $this->postJson('/api/v1/auth/register', [
            'company_name' => 'Intrus', 'name' => 'X', 'email' => 'x@intrus.ma', 'password' => 'password123',
        ])->json('token');

        $this->withToken($autre)->getJson("/api/v1/compta/rapprochement/{$statementId}")->assertNotFound();
    }
}
