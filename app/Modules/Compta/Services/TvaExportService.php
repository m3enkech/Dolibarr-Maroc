<?php

namespace App\Modules\Compta\Services;

use App\Core\Tenancy\TenantContext;
use App\Modules\Achats\Models\PaiementFournisseur;
use App\Modules\Ventes\Models\DocumentVente;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Génère le classeur de déclaration TVA au format DGI :
 *   - feuille « EDI »   : relevé de déductions (Article 112 du CGI, modèle
 *                         ADC082F-15I) — achats payés dans la période ;
 *   - feuille « CAMMAAAA » : chiffre d'affaires — factures de vente de la période.
 *
 * Régime des encaissements : la déduction est portée sur le mois du paiement
 * (colonne DATE_PAIE), d'où la source « paiements fournisseurs de la période ».
 */
class TvaExportService
{
    /** Mode de paiement → code ID_PAIE de la nomenclature DGI. */
    private const MODE_CODE = [
        'especes' => 1,
        'cheque' => 2,
        'virement' => 3,
        'carte' => 4,
        'autre' => 5,
    ];

    /** Mode de paiement → libellé « MOYEN DE REGLEMENT » (feuille CA). */
    private const MODE_LIBELLE = [
        'especes' => 'ESPECE',
        'cheque' => 'CHEQUE',
        'virement' => 'VIREMENT',
        'carte' => 'CARTE',
        'autre' => 'AUTRE',
    ];

    public function __construct(private TenantContext $context) {}

    public function build(string $mois): Spreadsheet
    {
        [$annee, $moisNum] = array_map('intval', explode('-', $mois));
        $debut = sprintf('%04d-%02d-01', $annee, $moisNum);
        $fin = date('Y-m-t', strtotime($debut));

        $spreadsheet = new Spreadsheet();
        $this->construireReleveDeductions($spreadsheet->getActiveSheet(), $annee, $moisNum, $debut, $fin);
        $this->construireChiffreAffaires($spreadsheet->createSheet(), $moisNum, $annee, $debut, $fin);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /* ------------------------------------------------------------------ */
    /* Feuille EDI — Relevé de déductions (achats)                         */
    /* ------------------------------------------------------------------ */

    private function construireReleveDeductions($sheet, int $annee, int $moisNum, string $debut, string $fin): void
    {
        $sheet->setTitle('EDI');
        $tenant = $this->context->get();
        $settings = $tenant?->settings ?? [];

        $sheet->setCellValue('L1', 'Modèle n° ADC082F-15I');
        $sheet->setCellValue('A2', 'RAISON SOCIAL');
        $sheet->setCellValue('C2', $tenant?->name);
        $sheet->setCellValue('A3', 'ID_FISCAL');
        $sheet->setCellValue('C3', $settings['if'] ?? '');
        $sheet->setCellValue('A4', 'ANNEE');
        $sheet->setCellValue('C4', $annee);
        $sheet->setCellValue('A5', 'PERIODE(trimestre)');
        $sheet->setCellValue('C5', (int) ceil($moisNum / 3));
        $sheet->setCellValue('F5', 'Relevé de déduction');
        $sheet->setCellValue('A6', 'REGIME(Encais-1)');
        $sheet->setCellValue('C6', 1); // régime des encaissements
        $sheet->setCellValue('F6', '(Article 112 du Code Général des Impôts)');

        $entetes = ['A' => 'OR', 'B' => 'FACT_NUM', 'C' => 'DESIGNATION', 'D' => 'M_HT',
            'E' => 'TVA', 'F' => 'M_TTC', 'G' => 'IF', 'H' => 'LIB_FRSS', 'I' => 'ICE_FRS',
            'J' => 'TAUX', 'K' => 'ID_PAIE', 'L' => 'DATE_PAIE', 'M' => 'DATE_FAC'];
        foreach ($entetes as $col => $titre) {
            $sheet->setCellValue($col.'8', $titre);
        }
        $this->styleEntete($sheet, 'A8:M8');

        // Achats payés dans la période (régime encaissements) : une ligne par
        // facture fournisseur réglée ce mois-ci.
        $paiements = PaiementFournisseur::query()
            ->whereBetween('date_paiement', [$debut, $fin])
            ->with(['document.tiers'])
            ->get()
            ->filter(fn ($p) => $p->document !== null && $p->document->type === 'facture')
            ->groupBy('document_achat_id');

        $ligne = 9;
        $ordre = 1;
        $totalHt = 0.0;
        $totalTva = 0.0;
        $totalTtc = 0.0;

        foreach ($paiements as $groupe) {
            $doc = $groupe->first()->document;
            $dernierPaiement = $groupe->sortByDesc('date_paiement')->first();
            $tiers = $doc->tiers;

            $ht = (float) $doc->total_ht;
            $tva = (float) $doc->total_tva;
            $ttc = (float) $doc->total_ttc;
            $taux = $ht > 0 ? (int) round($tva / $ht * 100) : 20;

            $sheet->setCellValue('A'.$ligne, $ordre);
            // Identifiants en texte : préserve les zéros initiaux (ICE, IF, n° facture).
            $this->cellTexte($sheet, 'B'.$ligne, $doc->ref_fournisseur ?: $doc->code);
            $sheet->setCellValue('C'.$ligne, 'MARCHANDISE');
            $sheet->setCellValue('D'.$ligne, round($ht, 2));
            $sheet->setCellValue('E'.$ligne, round($tva, 2));
            $sheet->setCellValue('F'.$ligne, round($ttc, 2));
            $this->cellTexte($sheet, 'G'.$ligne, $tiers?->if_number ?? '');
            $sheet->setCellValue('H'.$ligne, $tiers?->name ?? '');
            $this->cellTexte($sheet, 'I'.$ligne, $tiers?->ice ?? '');
            $sheet->setCellValue('J'.$ligne, $taux);
            $sheet->setCellValue('K'.$ligne, self::MODE_CODE[$dernierPaiement->mode] ?? 5);
            $this->cellDate($sheet, 'L'.$ligne, $dernierPaiement->date_paiement->format('Y-m-d'));
            $this->cellDate($sheet, 'M'.$ligne, $doc->date_document->format('Y-m-d'));

            $totalHt += $ht;
            $totalTva += $tva;
            $totalTtc += $ttc;
            $ligne++;
            $ordre++;
        }

        // Ligne de total.
        $sheet->setCellValue('C'.$ligne, 'Total');
        $sheet->setCellValue('D'.$ligne, round($totalHt, 2));
        $sheet->setCellValue('E'.$ligne, round($totalTva, 2));
        $sheet->setCellValue('F'.$ligne, round($totalTtc, 2));
        $sheet->getStyle('C'.$ligne.':F'.$ligne)->getFont()->setBold(true);

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Feuille CA — Chiffre d'affaires (ventes)                            */
    /* ------------------------------------------------------------------ */

    private function construireChiffreAffaires($sheet, int $moisNum, int $annee, string $debut, string $fin): void
    {
        $sheet->setTitle(sprintf('CA%02d%04d', $moisNum, $annee));

        $entetes = ['A' => 'DATE', 'B' => 'NUM DE FACTURE', 'C' => 'CLIENT',
            'D' => 'MOYEN DE REGLEMENT', 'E' => 'TTC', 'F' => 'HT', 'G' => 'TVA', 'H' => 'TAUX'];
        foreach ($entetes as $col => $titre) {
            $sheet->setCellValue($col.'1', $titre);
        }
        $this->styleEntete($sheet, 'A1:H1');

        $factures = DocumentVente::query()
            ->where('type', 'facture')
            ->where('statut', '!=', 'brouillon')
            ->whereBetween('date_document', [$debut, $fin])
            ->with(['tiers', 'paiements'])
            ->orderBy('date_document')
            ->orderBy('id')
            ->get();

        $ligne = 2;
        $totalTtc = 0.0;
        $totalHt = 0.0;
        $totalTva = 0.0;

        foreach ($factures as $facture) {
            $ht = (float) $facture->total_ht;
            $tva = (float) $facture->total_tva;
            $ttc = (float) $facture->total_ttc;
            $taux = $ht > 0 ? round($tva / $ht, 2) : 0.2;

            $modes = $facture->paiements
                ->pluck('mode')
                ->unique()
                ->map(fn ($m) => self::MODE_LIBELLE[$m] ?? strtoupper($m))
                ->implode(' / ');

            $this->cellDate($sheet, 'A'.$ligne, $facture->date_document->format('Y-m-d'));
            $this->cellTexte($sheet, 'B'.$ligne, $facture->code);
            $sheet->setCellValue('C'.$ligne, $facture->tiers?->name ?? '');
            $sheet->setCellValue('D'.$ligne, $modes ?: '—');
            $sheet->setCellValue('E'.$ligne, round($ttc, 2));
            $sheet->setCellValue('F'.$ligne, round($ht, 2));
            $sheet->setCellValue('G'.$ligne, round($tva, 2));
            $sheet->setCellValue('H'.$ligne, $taux);

            $totalTtc += $ttc;
            $totalHt += $ht;
            $totalTva += $tva;
            $ligne++;
        }

        $sheet->setCellValue('C'.$ligne, 'Total');
        $sheet->setCellValue('E'.$ligne, round($totalTtc, 2));
        $sheet->setCellValue('F'.$ligne, round($totalHt, 2));
        $sheet->setCellValue('G'.$ligne, round($totalTva, 2));
        $sheet->getStyle('C'.$ligne.':G'.$ligne)->getFont()->setBold(true);

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function cellDate($sheet, string $cell, string $date): void
    {
        $sheet->setCellValue($cell, ExcelDate::PHPToExcel(strtotime($date)));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    }

    /** Écrit une valeur en texte pur (identifiants à zéros initiaux). */
    private function cellTexte($sheet, string $cell, ?string $valeur): void
    {
        $sheet->setCellValueExplicit($cell, (string) $valeur, DataType::TYPE_STRING);
    }

    private function styleEntete($sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
}
