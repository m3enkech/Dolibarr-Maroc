<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Services\OuvertureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OuvertureController extends Controller
{
    public function __construct(private OuvertureService $service) {}

    /** Aperçu du fichier importé avant validation. */
    public function previsualiser(Request $request): JsonResponse
    {
        $request->validate(['fichier' => ['required', 'file', 'max:5120']]);

        return response()->json($this->service->previsualiser($request->file('fichier')));
    }

    /** Import effectif : crée l'écriture d'à-nouveaux. */
    public function importer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fichier' => ['required', 'file', 'max:5120'],
            'annee' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $ecriture = $this->service->importer(
            $request->file('fichier'),
            isset($data['annee']) ? (int) $data['annee'] : null,
        );

        return response()->json([
            'numero' => $ecriture->numero,
            'date' => $ecriture->date_ecriture?->format('Y-m-d'),
            'lignes' => $ecriture->lignes()->count(),
        ], 201);
    }

    /** Modèle Excel à remplir par l'entreprise. */
    public function modele(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Balance ouverture');

        $entetes = ['A' => 'Compte', 'B' => 'Libellé', 'C' => 'Débit', 'D' => 'Crédit'];
        foreach ($entetes as $col => $titre) {
            $sheet->setCellValue($col.'1', $titre);
        }
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');

        // Quelques lignes d'exemple (bilan d'ouverture équilibré).
        $exemples = [
            ['5141', 'Banque', 25000, 0],
            ['3411', 'Clients', 18000, 0],
            ['1111', 'Capital social', 0, 30000],
            ['1151', 'Report à nouveau', 0, 8000],
            ['4411', 'Fournisseurs', 0, 5000],
        ];
        $ligne = 2;
        foreach ($exemples as [$code, $lib, $d, $c]) {
            $sheet->setCellValueExplicit('A'.$ligne, $code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('B'.$ligne, $lib);
            $sheet->setCellValue('C'.$ligne, $d ?: null);
            $sheet->setCellValue('D'.$ligne, $c ?: null);
            $ligne++;
        }
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'modele-balance-ouverture.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
