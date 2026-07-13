<?php

namespace App\Modules\Compta;

/**
 * Sous-ensemble PME du Plan Comptable Général Marocain (CGNC).
 * ~60 comptes essentiels — l'entreprise peut ajouter les siens ensuite.
 * Source : hiérarchie officielle CGNC, classes 1 à 7.
 */
class PlanComptableMarocain
{
    public const CLASSES = [
        1 => 'Financement permanent',
        2 => 'Actif immobilisé',
        3 => 'Actif circulant',
        4 => 'Passif circulant',
        5 => 'Trésorerie',
        6 => 'Charges',
        7 => 'Produits',
    ];

    /** Clés des comptes par défaut → code CGNC officiel. */
    public const MAPPINGS_DEFAUT = [
        'clients' => '3421',
        'ventes_marchandises' => '7111',
        'ventes_services' => '7124',
        'tva_facturee' => '4455',
        'banque' => '5141',
        'caisse' => '5161',
        'cheques' => '5111',
        'fournisseurs' => '4411',
        'achats_marchandises' => '6111',
        'achats_services' => '6126',
        'tva_recuperable' => '34552',
    ];

    public const MAPPING_LABELS = [
        'clients' => 'Compte clients',
        'ventes_marchandises' => 'Ventes de marchandises',
        'ventes_services' => 'Ventes de services',
        'tva_facturee' => 'TVA facturée',
        'banque' => 'Banque',
        'caisse' => 'Caisse',
        'cheques' => 'Chèques à encaisser',
        'fournisseurs' => 'Compte fournisseurs',
        'achats_marchandises' => 'Achats de marchandises',
        'achats_services' => 'Achats de services et prestations',
        'tva_recuperable' => 'TVA récupérable sur charges',
    ];

    /**
     * [code, libellé] — plan de référence conforme au CGNC officiel marocain.
     * La classe est le premier chiffre du code.
     */
    public const COMPTES = [
        // Classe 1 — Financement permanent
        ['1111', 'Capital social'],
        ['1140', 'Réserve légale'],
        ['1161', 'Report à nouveau (solde créditeur)'],
        ['1169', 'Report à nouveau (solde débiteur)'],
        ['1191', 'Résultat net de l\'exercice (bénéfice)'],
        ['1199', 'Résultat net de l\'exercice (perte)'],
        ['1481', 'Emprunts auprès des établissements de crédit'],

        // Classe 2 — Actif immobilisé
        ['2111', 'Frais de constitution'],
        ['2220', 'Brevets, marques, droits et valeurs similaires'],
        ['2230', 'Fonds commercial'],
        ['2321', 'Constructions'],
        ['2332', 'Installations techniques, matériel et outillage'],
        ['2340', 'Matériel de transport'],
        ['2351', 'Mobilier de bureau'],
        ['2352', 'Matériel de bureau'],
        ['2355', 'Matériel informatique'],
        ['2356', 'Agencements et aménagements'],
        ['2486', 'Dépôts et cautionnements versés'],
        ['2822', 'Amortissement des brevets, marques et logiciels'],
        ['2832', 'Amortissement des constructions'],
        ['2833', 'Amortissement des installations techniques'],
        ['2834', 'Amortissement du matériel de transport'],
        ['2835', 'Amortissement du mobilier et matériel de bureau'],
        ['28355', 'Amortissement du matériel informatique'],

        // Classe 3 — Actif circulant
        ['3111', 'Marchandises'],
        ['3122', 'Matières et fournitures consommables'],
        ['3421', 'Clients'],
        ['3424', 'Clients douteux ou litigieux'],
        ['3425', 'Clients — effets à recevoir'],
        ['3431', 'Personnel — débiteurs'],
        ['34551', 'État — TVA récupérable sur immobilisations'],
        ['34552', 'État — TVA récupérable sur charges'],
        ['3453', 'État — Acomptes sur impôts sur les résultats'],
        ['3488', 'Autres débiteurs divers'],
        ['3942', 'Provisions pour dépréciation des clients et comptes rattachés'],

        // Classe 4 — Passif circulant
        ['4411', 'Fournisseurs'],
        ['4415', 'Fournisseurs — effets à payer'],
        ['4417', 'Fournisseurs — factures non parvenues'],
        ['4432', 'Personnel — rémunérations dues'],
        ['4441', 'Organismes sociaux (CNSS, AMO, CIMR)'],
        ['44525', 'État — Impôts et taxes sur les salaires (IR)'],
        ['4453', 'État — Acomptes sur impôts sur les résultats'],
        ['4455', 'État — TVA facturée'],
        ['4456', 'État — TVA due'],
        ['4463', 'Associés — comptes courants créditeurs'],
        ['4488', 'Autres créanciers divers'],
        ['4491', 'Produits constatés d\'avance'],

        // Classe 5 — Trésorerie
        ['5111', 'Chèques à encaisser'],
        ['5141', 'Banques'],
        ['5161', 'Caisse'],

        // Classe 6 — Charges
        ['6111', 'Achats de marchandises revendues en l\'état'],
        ['6126', 'Achats de travaux, études et prestations de services'],
        ['6131', 'Locations et charges locatives'],
        ['6134', 'Primes d\'assurances'],
        ['6136', 'Rémunérations d\'intermédiaires et honoraires'],
        ['6142', 'Transports'],
        ['6143', 'Déplacements, missions et réceptions'],
        ['6144', 'Publicité, publications et relations publiques'],
        ['6145', 'Frais postaux et de télécommunications'],
        ['6147', 'Services bancaires'],
        ['6161', 'Impôts et taxes directs (Taxe Professionnelle)'],
        ['6171', 'Rémunérations du personnel'],
        ['6174', 'Charges sociales'],
        ['6193', 'Dotations d\'exploitation aux amortissements des immobilisations'],
        ['6311', 'Charges d\'intérêts'],
        ['6513', 'Valeurs nettes d\'amortissements des immobilisations cédées'],
        ['6583', 'Pénalités et amendes fiscales et pénales'],
        ['6701', 'Impôt sur les sociétés (IS)'],
        ['6702', 'Cotisation minimale (CM)'],

        // Classe 7 — Produits
        ['7111', 'Ventes de marchandises en l\'état'],
        ['7124', 'Ventes de services'],
        ['7119', 'Rabais, remises et ristournes accordés'],
        ['7181', 'Produits d\'exploitation divers'],
        ['7513', 'Produits de cession des immobilisations'],
    ];
}
