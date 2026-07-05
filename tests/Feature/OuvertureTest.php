<?php

namespace Tests\Feature;

use App\Modules\Compta\Models\Compte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OuvertureTest extends TestCase
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

    /** Fabrique un fichier xlsx de balance d'ouverture depuis un tableau de lignes. */
    private function fichierBalance(array $lignes, string $nom = 'balance.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach (['A' => 'Compte', 'B' => 'Libellé', 'C' => 'Débit', 'D' => 'Crédit'] as $col => $titre) {
            $sheet->setCellValue($col.'1', $titre);
        }
        $r = 2;
        foreach ($lignes as [$code, $lib, $d, $c]) {
            $sheet->setCellValueExplicit('A'.$r, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$r, $lib);
            $sheet->setCellValue('C'.$r, $d ?: null);
            $sheet->setCellValue('D'.$r, $c ?: null);
            $r++;
        }
        $path = tempnam(sys_get_temp_dir(), 'bal').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $nom, null, null, true);
    }

    public function test_preview_reports_balance_and_unknown_accounts(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->withToken($token)->getJson('/api/v1/compta/comptes'); // seed

        $fichier = $this->fichierBalance([
            ['5141', 'Banque', 25000, 0],
            ['3411', 'Clients', 5000, 0],
            ['1111', 'Capital', 0, 30000],
            ['3488', 'Compte hors plan', 0, 0], // classe valide, absent du plan
        ]);

        $preview = $this->withToken($token)->post('/api/v1/compta/ouverture/previsualiser', ['fichier' => $fichier]);
        $preview->assertOk()
            ->assertJsonPath('total_debit', '30000.00')
            ->assertJsonPath('total_credit', '30000.00')
            ->assertJsonPath('equilibre', true);

        // 5141 existe dans le plan, 3488 non (sera créé à l'import).
        $lignes = collect($preview->json('lignes'));
        $this->assertTrue($lignes->firstWhere('code', '5141')['existe']);
        $this->assertFalse($lignes->firstWhere('code', '3488')['existe']);
    }

    public function test_import_creates_balanced_an_entry_dated_first_january(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->withToken($token)->getJson('/api/v1/compta/comptes');
        $annee = now()->year;

        $fichier = $this->fichierBalance([
            ['5141', 'Banque', 25000, 0],
            ['3411', 'Clients', 18000, 0],
            ['1111', 'Capital social', 0, 30000],
            ['1151', 'Report à nouveau', 0, 8000],
            ['4411', 'Fournisseurs', 0, 5000],
        ]);

        $import = $this->withToken($token)->post('/api/v1/compta/ouverture/importer', ['fichier' => $fichier]);
        $import->assertCreated()
            ->assertJsonPath('date', "{$annee}-01-01")
            ->assertJsonPath('lignes', 5);

        // L'écriture AN est équilibrée (43000 = 43000) et datée du 01/01.
        $ecriture = collect($this->withToken($token)->getJson('/api/v1/compta/ecritures?journal=AN')->json('data'))->first();
        $lignes = collect($ecriture['lignes']);
        $this->assertSame('25000.00', $lignes->firstWhere('compte_code', '5141')['debit']);
        $this->assertSame('8000.00', $lignes->firstWhere('compte_code', '1151')['credit']);
        $this->assertSame(
            $lignes->sum(fn ($l) => (float) $l['debit']),
            $lignes->sum(fn ($l) => (float) $l['credit']),
        );

        // La balance reflète les soldes d'ouverture.
        $balance = collect($this->withToken($token)->getJson('/api/v1/compta/balance')->json('data'));
        $this->assertSame('25000.00', $balance->firstWhere('code', '5141')['solde_debiteur']);
        $this->assertSame('5000.00', $balance->firstWhere('code', '4411')['solde_crediteur']);
    }

    public function test_import_creates_missing_accounts(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->withToken($token)->getJson('/api/v1/compta/comptes');

        // 3455 (État — crédit de TVA) n'est pas dans le plan par défaut.
        $this->assertFalse(Compte::where('code', '3455')->exists());

        $fichier = $this->fichierBalance([
            ['5141', 'Banque', 10000, 0],
            ['3455', 'État crédit de TVA', 2000, 0],
            ['1111', 'Capital', 0, 12000],
        ]);

        $this->withToken($token)->post('/api/v1/compta/ouverture/importer', ['fichier' => $fichier])->assertCreated();

        // Le compte manquant a été créé (non système).
        $compte = Compte::where('code', '3455')->first();
        $this->assertNotNull($compte);
        $this->assertFalse($compte->is_system);
        $this->assertSame(3, $compte->classe);
    }

    public function test_unbalanced_file_is_rejected(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->withToken($token)->getJson('/api/v1/compta/comptes');

        $fichier = $this->fichierBalance([
            ['5141', 'Banque', 10000, 0],
            ['1111', 'Capital', 0, 9000], // déséquilibre
        ]);

        $this->withToken($token)->post('/api/v1/compta/ouverture/importer', ['fichier' => $fichier])
            ->assertUnprocessable();
    }

    public function test_double_import_is_blocked(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');
        $this->withToken($token)->getJson('/api/v1/compta/comptes');

        $lignes = [['5141', 'Banque', 10000, 0], ['1111', 'Capital', 0, 10000]];

        $this->withToken($token)->post('/api/v1/compta/ouverture/importer', ['fichier' => $this->fichierBalance($lignes)])
            ->assertCreated();
        $this->withToken($token)->post('/api/v1/compta/ouverture/importer', ['fichier' => $this->fichierBalance($lignes)])
            ->assertUnprocessable();
    }

    public function test_template_download(): void
    {
        $token = $this->registerTenant('Tenant A', 'a@test.ma');

        $response = $this->withToken($token)->get('/api/v1/compta/ouverture/modele');
        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
        $this->assertStringStartsWith('PK', $response->streamedContent());
    }

    public function test_import_is_isolated_per_tenant(): void
    {
        $tokenA = $this->registerTenant('Tenant A', 'a@test.ma');
        $tokenB = $this->registerTenant('Tenant B', 'b@test.ma');
        $this->withToken($tokenA)->getJson('/api/v1/compta/comptes');
        $this->withToken($tokenB)->getJson('/api/v1/compta/comptes');

        $this->withToken($tokenA)->post('/api/v1/compta/ouverture/importer', [
            'fichier' => $this->fichierBalance([['5141', 'Banque', 10000, 0], ['1111', 'Capital', 0, 10000]]),
        ])->assertCreated();

        // Le tenant B n'a aucune écriture AN.
        $this->withToken($tokenB)->getJson('/api/v1/compta/ecritures?journal=AN')
            ->assertJsonPath('meta.total', 0);
    }
}
