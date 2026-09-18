<?php

namespace App\Core\Format;

use NumberFormatter;

/**
 * Le montant d'une facture, écrit en toutes lettres.
 *
 * Ce n'est pas un ornement : l'article 145 du Code général des impôts impose la
 * mention en lettres sur les factures marocaines, et c'est elle qui fait foi en
 * cas de litige sur un chiffre raturé ou mal imprimé. Une facture qui ne la
 * porte pas est formellement irrégulière.
 *
 * TROIS PARTIS PRIS.
 *
 * 1. LES CENTIMES SONT ÉCRITS, EUX AUSSI. Le modèle que nous reproduisons les
 *    laissait tomber : 720,50 s'y imprimait « Sept cent vingt », et la mention
 *    censée lever l'ambiguïté en créait une de cinquante centimes. On les écrit
 *    donc, et seulement quand il y en a.
 *
 * 2. LA DEVISE EST NOMMÉE. « Sept cent vingt » ne dit pas de quoi. Le dirham
 *    s'accorde au pluriel, le centime aussi, et « un dirham » reste au
 *    singulier — d'où le passage par un tableau plutôt que par un « s » collé
 *    à la fin.
 *
 * 3. ON S'APPUIE SUR ICU, PAS SUR UN TABLEAU MAISON. Écrire les nombres
 *    français à la main veut dire retomber sur quatre-vingts, quatre-vingt-un,
 *    soixante-et-onze, mille invariable, cent qui s'accorde sauf devant un
 *    autre nombre… ICU tient déjà cette grammaire, et l'extension `intl` est
 *    présente dans l'image de production (Dockerfile) comme en développement.
 */
class MontantEnLettres
{
    /** Le dirham et sa subdivision, au singulier puis au pluriel. */
    private const DEVISES = [
        'MAD' => [['dirham', 'dirhams'], ['centime', 'centimes']],
        'EUR' => [['euro', 'euros'], ['centime', 'centimes']],
        'USD' => [['dollar', 'dollars'], ['cent', 'cents']],
    ];

    /**
     * « Sept cent vingt dirhams », « Mille deux cents dirhams et cinquante
     * centimes », « Zéro dirham ».
     */
    public static function pour(float|string $montant, string $devise = 'MAD'): string
    {
        [$unite, $subdivision] = self::DEVISES[strtoupper($devise)] ?? self::DEVISES['MAD'];

        // On arrondit AVANT de découper : 720,999 doit donner 721 dirhams, et
        // non « sept cent vingt dirhams et cent centimes ».
        $total = round((float) $montant, 2);
        $negatif = $total < 0;
        $total = abs($total);

        $entier = (int) floor($total);
        // Le détour par une chaîne évite l'erreur de virgule flottante qui fait
        // de 0,29 un 28,999… et donc 28 centimes au lieu de 29.
        $centimes = (int) round(($total - $entier) * 100);

        $mots = self::enLettres($entier).' '.self::accord($entier, $unite);

        if ($centimes > 0) {
            $mots .= ' et '.self::enLettres($centimes).' '.self::accord($centimes, $subdivision);
        }

        return self::capitaliser(($negatif ? 'moins ' : '').$mots);
    }

    /* ------------------------------------------------------------------ */

    private static function enLettres(int $nombre): string
    {
        // Sans ICU on préfère un chiffre lisible à une mention absente : la
        // facture reste imprimable, et l'anomalie se voit.
        if (! class_exists(NumberFormatter::class)) {
            return (string) $nombre;
        }

        $formateur = new NumberFormatter('fr', NumberFormatter::SPELLOUT);

        // ICU écrit « soixante-dix-huit » ; l'usage des factures met des
        // espaces. On garde les traits d'union internes aux dizaines composées
        // uniquement là où le français les exige toujours (dix-sept, vingt-et-un
        // reste écrit par ICU) — donc on ne touche à rien d'autre.
        return $formateur->format($nombre) ?: (string) $nombre;
    }

    /** @param  array{0: string, 1: string}  $forme */
    private static function accord(int $nombre, array $forme): string
    {
        return $nombre > 1 ? $forme[1] : $forme[0];
    }

    /** Première lettre en capitale, accents compris (ucfirst ne sait pas faire). */
    private static function capitaliser(string $texte): string
    {
        return mb_strtoupper(mb_substr($texte, 0, 1)).mb_substr($texte, 1);
    }
}
