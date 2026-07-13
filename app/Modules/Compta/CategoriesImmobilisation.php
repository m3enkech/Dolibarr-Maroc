<?php

namespace App\Modules\Compta;

/**
 * Catégories d'immobilisations et leurs valeurs par défaut (comptes CGNC +
 * durée d'amortissement linéaire usuelle au Maroc). Les comptes restent
 * modifiables à la saisie — ce ne sont que des pré-remplissages.
 */
class CategoriesImmobilisation
{
    /** cle => [libellé, compte immobilisation, compte amortissement, durée années]. */
    public const CATEGORIES = [
        'construction' => ['Constructions', '2321', '2832', 20],
        'installations' => ['Installations techniques, matériel et outillage', '2332', '2833', 10],
        'materiel_transport' => ['Matériel de transport', '2340', '2834', 5],
        'mobilier_bureau' => ['Mobilier de bureau', '2351', '2835', 10],
        'materiel_bureau' => ['Matériel de bureau', '2352', '2835', 10],
        'materiel_informatique' => ['Matériel informatique', '2355', '28355', 5],
        'agencements' => ['Agencements et aménagements', '2356', '2835', 10],
        'logiciel' => ['Logiciels, brevets et licences', '2220', '2822', 3],
    ];

    public static function keys(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function get(string $cle): ?array
    {
        if (! isset(self::CATEGORIES[$cle])) {
            return null;
        }

        [$label, $compteImmo, $compteAmort, $duree] = self::CATEGORIES[$cle];

        return compact('label', 'compteImmo', 'compteAmort', 'duree');
    }

    /** Métadonnées exposées au frontend pour le pré-remplissage. */
    public static function forFrontend(): array
    {
        return collect(self::CATEGORIES)->map(fn ($v, $cle) => [
            'cle' => $cle,
            'label' => $v[0],
            'compte_immo' => $v[1],
            'compte_amort' => $v[2],
            'duree' => $v[3],
        ])->values()->all();
    }
}
