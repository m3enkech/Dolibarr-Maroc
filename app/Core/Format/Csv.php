<?php

namespace App\Core\Format;

/**
 * Les fichiers CSV que l'on remet à un tableur — Excel en français, le plus
 * souvent : séparateur « ; » (la virgule y est le séparateur décimal), BOM
 * UTF-8 en tête (sans lui, « Société » s'ouvre en « SociÃ©tÃ© »).
 *
 * ET LA PROTECTION CONTRE L'INJECTION DE FORMULES. Un tableur exécute une
 * cellule qui commence par « = », « + », « - » ou « @ » : un nom de client
 * saisi « =HYPERLINK("http://…";"Cliquez") », ou un « =cmd|… » exploitant
 * DDE, partirait chez le comptable qui ouvre l'export — ce n'est plus du
 * texte, c'est un programme. La cellule est neutralisée par une apostrophe
 * en tête, qui la fait lire comme du texte (recommandation OWASP).
 */
final class Csv
{
    /** Le BOM UTF-8, à écrire une fois en tête du fichier. */
    public const BOM = "\u{FEFF}";

    public const SEPARATEUR = ';';

    /**
     * Une cellule de TEXTE prête à écrire.
     *
     * Neutralisée quand elle commence par = + - @, une tabulation ou un
     * retour à la ligne — ou par des blancs suivis de = + - @ : certains
     * tableurs ignorent les blancs de tête avant d'évaluer.
     *
     * Le prix à payer est connu : un téléphone « +212 6… » sort
     * « '+212 6… ». C'est voulu — sans l'apostrophe, Excel lirait
     * « +212522… » comme une formule et rendrait 212522000000, le « + » perdu.
     */
    public static function texte(?string $valeur): string
    {
        $valeur ??= '';

        if (preg_match('/^(?:[\t\r\n]|\s*[=+\-@])/', $valeur) === 1) {
            return "'".$valeur;
        }

        return $valeur;
    }

    /**
     * Un montant écrit À LA FRANÇAISE (« -1234,50 ») : Excel en français le lit
     * comme un nombre et peut le sommer. Jamais neutralisé : il sort d'un
     * calcul, pas d'une saisie, et l'apostrophe ferait d'un solde négatif du
     * texte que plus aucune somme ne compte.
     */
    public static function montant(float|string|null $valeur): string
    {
        if ($valeur === null || $valeur === '') {
            return '';
        }

        return number_format((float) $valeur, 2, ',', '');
    }

    /**
     * Écrit une ligne déjà préparée (cellules passées par texte() ou
     * montant()). `escape` vide et fin de ligne CRLF : la norme RFC 4180, où
     * seul le guillemet doublé échappe — l'antislash par défaut de PHP
     * corrompt un texte qui finit par « \ », et PHP 8.4 en déprécie l'usage
     * implicite.
     *
     * @param  resource  $flux
     * @param  list<string>  $cellules
     */
    public static function ligne($flux, array $cellules): void
    {
        fputcsv($flux, $cellules, self::SEPARATEUR, '"', '', "\r\n");
    }
}
