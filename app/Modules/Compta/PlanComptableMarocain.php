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

    /** Clés des comptes par défaut → code PCGM. */
    public const MAPPINGS_DEFAUT = [
        'clients' => '3411',
        'ventes_marchandises' => '7111',
        'ventes_services' => '7114',
        'tva_facturee' => '4441',
        'banque' => '5141',
        'caisse' => '5161',
        'cheques' => '5111',
        'fournisseurs' => '4411',
        'achats_marchandises' => '6111',
        'achats_services' => '6117',
        'tva_recuperable' => '3442',
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

    /** [code, libellé] — la classe est le premier chiffre du code. */
    public const COMPTES = [
        // Classe 1 — Financement permanent
        ['1111', 'Capital social'],
        ['1141', 'Réserve légale'],
        ['1161', 'Résultat net de l\'exercice (bénéfice)'],
        ['1162', 'Résultat net de l\'exercice (perte)'],
        ['1415', 'Emprunts auprès des établissements de crédit'],

        // Classe 2 — Actif immobilisé
        ['2111', 'Frais de constitution'],
        ['2220', 'Brevets, marques, droits et valeurs similaires'],
        ['2230', 'Fonds commercial'],
        ['2321', 'Constructions'],
        ['2330', 'Installations techniques, matériel et outillage'],
        ['2340', 'Matériel de transport'],
        ['2351', 'Mobilier de bureau'],
        ['2352', 'Matériel de bureau'],
        ['2355', 'Matériel informatique'],
        ['2356', 'Agencements et aménagements'],
        ['2450', 'Dépôts et cautionnements versés'],
        ['2832', 'Amortissement des brevets, marques et logiciels'],
        ['2921', 'Amortissement des constructions'],
        ['2922', 'Amortissement des installations techniques'],
        ['2924', 'Amortissement du matériel de transport'],
        ['2925', 'Amortissement du mobilier et matériel de bureau'],
        ['2926', 'Amortissement du matériel informatique'],

        // Classe 3 — Actif circulant
        ['3111', 'Matières et fournitures consommables'],
        ['3151', 'Marchandises'],
        ['3411', 'Clients'],
        ['3412', 'Clients — effets à recevoir'],
        ['3413', 'Clients douteux ou litigieux'],
        ['3421', 'Personnel — débiteurs'],
        ['3441', 'État — TVA récupérable sur immobilisations'],
        ['3442', 'État — TVA récupérable sur charges'],
        ['3443', 'État — Acomptes et versements sur IS'],
        ['3461', 'Débiteurs divers'],
        ['3491', 'Provisions pour dépréciation des comptes clients'],

        // Classe 4 — Passif circulant
        ['4411', 'Fournisseurs'],
        ['4412', 'Fournisseurs — effets à payer'],
        ['4417', 'Fournisseurs — factures non parvenues'],
        ['4421', 'Personnel — rémunérations dues'],
        ['4432', 'Associés — comptes courants'],
        ['4441', 'État — TVA facturée'],
        ['4442', 'État — TVA due'],
        ['4443', 'État — Acomptes sur IS'],
        ['4445', 'État — Impôts et taxes sur salaires (IR)'],
        ['4446', 'Organismes sociaux (CNSS, AMO, CIMR)'],
        ['4461', 'Créditeurs divers'],
        ['4491', 'Produits constatés d\'avance'],

        // Classe 5 — Trésorerie
        ['5111', 'Chèques à encaisser'],
        ['5141', 'Banques'],
        ['5161', 'Caisse'],

        // Classe 6 — Charges
        ['6111', 'Achats de marchandises revendues en l\'état'],
        ['6117', 'Achats de travaux, études et prestations de services'],
        ['6121', 'Locations et charges locatives'],
        ['6126', 'Primes d\'assurances'],
        ['6128', 'Transports'],
        ['6129', 'Déplacements, missions et réceptions'],
        ['6131', 'Frais postaux et de télécommunications'],
        ['6132', 'Commissions et honoraires'],
        ['6133', 'Frais de publicité'],
        ['6135', 'Services bancaires'],
        ['6141', 'Impôts et taxes directs — Taxe Professionnelle'],
        ['6151', 'Rémunérations du personnel'],
        ['6152', 'Charges sociales'],
        ['6161', 'Dotations aux amortissements des immobilisations'],
        ['6311', 'Charges d\'intérêts'],
        ['6511', 'Valeurs nettes d\'amortissements des immobilisations cédées'],
        ['6514', 'Pénalités et amendes fiscales et pénales'],
        ['6701', 'Impôt sur les sociétés (IS)'],
        ['6702', 'Cotisation minimale (CM)'],

        // Classe 7 — Produits
        ['7111', 'Ventes de marchandises en l\'état'],
        ['7114', 'Ventes de services'],
        ['7118', 'Rabais, remises et ristournes accordés'],
        ['7161', 'Produits d\'exploitation divers'],
        ['7511', 'Produits de cession des immobilisations'],
    ];
}
