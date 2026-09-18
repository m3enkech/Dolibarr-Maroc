<?php

namespace App\Modules\Ventes\Services;

use App\Core\Format\MontantEnLettres;
use App\Modules\Ventes\Models\DocumentVente;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * Rendu PDF d'un document de vente.
 *
 * Extrait du contrôleur de l'ERP parce que le portail sert LE MÊME document au
 * client final : deux rendus séparés finiraient par diverger, et une facture
 * qui ne dit pas la même chose au vendeur et à l'acheteur ne vaut plus rien.
 */
class DocumentPdfService
{
    public function rendre(DocumentVente $document): PdfDocument
    {
        $document->load(['lignes', 'tiers', 'tenant', 'paiements', 'source']);

        // Ventilation de la TVA par taux (exigence des factures marocaines).
        $tvaBreakdown = $document->lignes
            ->groupBy(fn ($ligne) => (string) $ligne->tva_rate)
            ->map(fn ($lignes, $rate) => [
                'rate' => (float) $rate,
                'ht' => $lignes->sum(fn ($l) => (float) $l->montant_ht),
                'tva' => $lignes->sum(fn ($l) => (float) $l->montant_tva),
            ])
            ->sortByDesc('rate')
            ->values();

        return Pdf::loadView('pdf.document-vente', [
            'document' => $document,
            'tvaBreakdown' => $tvaBreakdown,
            // Article 145 du CGI : la facture porte le montant en toutes
            // lettres, et c'est cette mention qui fait foi en cas de litige sur
            // un chiffre mal imprimé.
            'montantEnLettres' => MontantEnLettres::pour($document->total_ttc),
        ]);
    }
}
