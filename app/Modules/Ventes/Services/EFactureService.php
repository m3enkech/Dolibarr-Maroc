<?php

namespace App\Modules\Ventes\Services;

use App\Modules\Ventes\Models\DocumentVente;
use XMLWriter;

/**
 * Génère la facture électronique au format UBL 2.1 (standard international retenu
 * par la réforme e-facturation marocaine, modèle « clearance » DGI).
 *
 * NB : ce service produit le document structuré conforme UBL 2.1. La transmission
 * temps réel à la plateforme DGI (validation « clearance ») nécessitera un
 * connecteur dédié une fois l'API officielle publiée (décret d'application).
 */
class EFactureService
{
    private const NS = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const DEVISE = 'MAD';

    public function genererXml(DocumentVente $facture): string
    {
        $facture->loadMissing(['lignes', 'tiers', 'tenant']);
        $settings = $facture->tenant?->settings ?? [];

        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');

        $w->startElementNs(null, 'Invoice', self::NS);
        $w->writeAttributeNs('xmlns', 'cac', null, self::CAC);
        $w->writeAttributeNs('xmlns', 'cbc', null, self::CBC);

        $this->cbc($w, 'UBLVersionID', '2.1');
        $this->cbc($w, 'ID', $facture->code);
        $this->cbc($w, 'IssueDate', $facture->date_document?->format('Y-m-d'));
        $this->cbc($w, 'InvoiceTypeCode', '380'); // facture commerciale
        $this->cbc($w, 'DocumentCurrencyCode', self::DEVISE);

        $this->partie($w, 'AccountingSupplierParty', $facture->tenant?->name, $settings['ice'] ?? null, $settings['if'] ?? null);
        $this->partie($w, 'AccountingCustomerParty', $facture->tiers?->name, $facture->tiers?->ice, $facture->tiers?->if_number);

        $this->taxTotal($w, $facture);
        $this->totaux($w, $facture);
        $this->lignes($w, $facture);

        $w->endElement(); // Invoice
        $w->endDocument();

        return $w->outputMemory();
    }

    private function cbc(XMLWriter $w, string $nom, ?string $valeur): void
    {
        $w->writeElementNs('cbc', $nom, null, (string) $valeur);
    }

    private function montant(XMLWriter $w, string $nom, float $valeur): void
    {
        $w->startElementNs('cbc', $nom, null);
        $w->writeAttribute('currencyID', self::DEVISE);
        $w->text(number_format($valeur, 2, '.', ''));
        $w->endElement();
    }

    private function partie(XMLWriter $w, string $role, ?string $nom, ?string $ice, ?string $if): void
    {
        $w->startElementNs('cac', $role, null);
        $w->startElementNs('cac', 'Party', null);

        if ($ice) {
            $w->startElementNs('cac', 'PartyIdentification', null);
            $w->startElementNs('cbc', 'ID', null);
            $w->writeAttribute('schemeName', 'ICE');
            $w->text($ice);
            $w->endElement();
            $w->endElement();
        }

        $w->startElementNs('cac', 'PartyName', null);
        $this->cbc($w, 'Name', $nom);
        $w->endElement();

        if ($if) {
            $w->startElementNs('cac', 'PartyTaxScheme', null);
            $this->cbc($w, 'CompanyID', $if);
            $w->startElementNs('cac', 'TaxScheme', null);
            $this->cbc($w, 'ID', 'VAT');
            $w->endElement();
            $w->endElement();
        }

        $w->startElementNs('cac', 'PartyLegalEntity', null);
        $this->cbc($w, 'RegistrationName', $nom);
        $w->endElement();

        $w->endElement(); // Party
        $w->endElement(); // role
    }

    private function taxTotal(XMLWriter $w, DocumentVente $facture): void
    {
        $w->startElementNs('cac', 'TaxTotal', null);
        $this->montant($w, 'TaxAmount', (float) $facture->total_tva);

        // Une sous-catégorie de TVA par taux.
        $parTaux = $facture->lignes->groupBy(fn ($l) => (string) (float) $l->tva_rate);
        foreach ($parTaux->sortKeys() as $taux => $lignes) {
            $ht = $lignes->sum(fn ($l) => (float) $l->montant_ht);
            $tva = $lignes->sum(fn ($l) => (float) $l->montant_tva);

            $w->startElementNs('cac', 'TaxSubtotal', null);
            $this->montant($w, 'TaxableAmount', (float) $ht);
            $this->montant($w, 'TaxAmount', (float) $tva);
            $w->startElementNs('cac', 'TaxCategory', null);
            $this->cbc($w, 'Percent', number_format((float) $taux, 2, '.', ''));
            $w->startElementNs('cac', 'TaxScheme', null);
            $this->cbc($w, 'ID', 'VAT');
            $w->endElement();
            $w->endElement();
            $w->endElement();
        }

        $w->endElement(); // TaxTotal
    }

    private function totaux(XMLWriter $w, DocumentVente $facture): void
    {
        $w->startElementNs('cac', 'LegalMonetaryTotal', null);
        $this->montant($w, 'LineExtensionAmount', (float) $facture->total_ht);
        $this->montant($w, 'TaxExclusiveAmount', (float) $facture->total_ht);
        $this->montant($w, 'TaxInclusiveAmount', (float) $facture->total_ttc);
        $this->montant($w, 'PayableAmount', (float) $facture->total_ttc);
        $w->endElement();
    }

    private function lignes(XMLWriter $w, DocumentVente $facture): void
    {
        foreach ($facture->lignes as $i => $ligne) {
            $w->startElementNs('cac', 'InvoiceLine', null);
            $this->cbc($w, 'ID', (string) ($i + 1));

            $w->startElementNs('cbc', 'InvoicedQuantity', null);
            $w->text(number_format((float) $ligne->quantite, 3, '.', ''));
            $w->endElement();

            $this->montant($w, 'LineExtensionAmount', (float) $ligne->montant_ht);

            $w->startElementNs('cac', 'Item', null);
            $this->cbc($w, 'Name', $ligne->designation);
            $w->endElement();

            $w->startElementNs('cac', 'Price', null);
            $this->montant($w, 'PriceAmount', (float) $ligne->prix_unitaire);
            $w->endElement();

            $w->endElement(); // InvoiceLine
        }
    }
}
