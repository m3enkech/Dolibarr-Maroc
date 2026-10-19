<?php

namespace App\Core\Format;

/**
 * Un numéro de téléphone, ramené au format qu'attend WhatsApp.
 *
 * Le lien « click-to-chat » (https://wa.me/<numéro>) n'accepte que des
 * CHIFFRES, indicatif pays compris, sans « + » ni « 00 » ni zéro national :
 * 212612345678. Or les fiches contiennent ce que les gens tapent — « 06 12 34
 * 56 78 », « 06.12.34.56.78 », « +212 6 12… », « 00212… », « Tél : 06… »,
 * parfois deux numéros dans la même case. Un numéro mal ramené n'échoue pas
 * bruyamment : WhatsApp ouvre une conversation avec un inconnu, ou annonce
 * « ce numéro n'est pas sur WhatsApp ». D'où une classe à part, testée format
 * par format, sans aucune dépendance (ni Laravel, ni bibliothèque externe).
 *
 * QUATRE PARTIS PRIS.
 *
 *  1. SANS INDICATIF, C'EST LE MAROC. Le produit sert des entreprises
 *     marocaines : « 0612345678 » et « 612345678 » sont des mobiles marocains.
 *     Un numéro étranger doit donc porter son indicatif (« +33… », « 0033… »)
 *     — deviner le pays d'une suite de chiffres serait écrire au mauvais
 *     destinataire.
 *
 *  2. UN FIXE N'EST PAS REFUSÉ, IL EST SIGNALÉ. Un 05… marocain n'a presque
 *     jamais WhatsApp, mais certaines lignes professionnelles l'ont (WhatsApp
 *     Business sur un fixe existe). On laisse le choix à l'utilisateur, averti.
 *
 *  3. UN REFUS DIT POURQUOI. « Numéro invalide » ne permet pas de corriger la
 *     fiche ; « un numéro marocain compte 10 chiffres » si. Les raisons sont
 *     des phrases FRANÇAISES qui servent aussi de clés de traduction
 *     (lang/ar.json) : la classe reste pure, et la couche HTTP les traduit.
 *
 *  4. DU TEXTE DANS LA CASE, C'EST UN REFUS — sauf l'étiquette de tête.
 *     « Tél : 06… » ou « GSM 06… » se nettoient sans risque : l'étiquette
 *     précède le numéro et ne contient aucun chiffre. « 0522 30 30 30 poste
 *     12 », non : en retirant les lettres on collerait l'extension au numéro
 *     et on fabriquerait un numéro qui n'existe pas. Mieux vaut demander de
 *     corriger la fiche.
 */
final class NumeroWhatsApp
{
    public const NATURE_MOBILE = 'mobile';

    public const NATURE_FIXE = 'fixe';

    public const NATURE_ETRANGER = 'etranger';

    public const AVERTISSEMENT_FIXE = 'fixe — WhatsApp peu probable';

    public const RAISON_VIDE = 'Aucun numéro saisi.';

    public const RAISON_TEXTE = 'Le numéro contient du texte : corrigez la fiche.';

    public const RAISON_LONGUEUR_MAROC = 'Un numéro marocain compte 10 chiffres (06 12 34 56 78).';

    public const RAISON_PREFIXE_MAROC = 'Un numéro marocain commence par 05, 06, 07 ou 08.';

    public const RAISON_INDICATIF = 'Indicatif pays manquant : saisissez un numéro étranger au format international (+33…).';

    public const RAISON_LONGUEUR_INTERNATIONALE = 'Numéro international invalide : de 8 à 15 chiffres, indicatif compris.';

    /** Indicatif du Maroc, et longueur du numéro national sans le 0 initial. */
    private const MAROC = '212';

    private const LONGUEUR_NATIONALE_MAROC = 9;

    /** Chiffres arabes orientaux et persans, tapés depuis un clavier arabe. */
    private const CHIFFRES_ORIENTAUX = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Ce qui sépare deux numéros dans une même case : « 06… / 05… »,
     * « 06… ; 05… », « 06… ou 05… ». Le tiret n'en fait PAS partie : il
     * groupe les chiffres d'un seul numéro (« 06-12-34-56-78 »).
     */
    private const SEPARATEURS = '#\s*(?:[/,;|]|\s(?:ou|et|or)\s)\s*#iu';

    /**
     * Les séparateurs AMBIGUS : le saut de ligne, un tiret entouré d'espaces
     * (« 0661234567 - 0522303030 »), un tiret demi-cadratin ou cadratin. Ils
     * séparent souvent deux numéros, mais groupent parfois les chiffres d'un
     * seul (« 06 - 12 - 34 - 56 - 78 »). On ne coupe donc que si CHAQUE
     * morceau a la taille d'un numéro : sinon, la case reste d'un bloc.
     * (\p{Pd} : tout tiret, trait d'union compris ; [‒–—] : tiret numérique,
     * demi-cadratin, cadratin, qui séparent même collés.)
     */
    private const SEPARATEURS_AMBIGUS = '#\s*\R\s*|\s+\p{Pd}\s+|\s*[‒–—]\s*#u';

    /** Le moins de chiffres d'un numéro complet (plancher international). */
    private const CHIFFRES_MINIMUM = 8;

    /**
     * L'étiquette qu'on trouve en tête de case, reprise d'un ancien logiciel
     * ou d'une signature de courriel. Seulement EN TÊTE, et seulement ces
     * mots-là : tout autre texte reste un refus.
     */
    private const ETIQUETTE = '/^\s*(?:t[ée]l[ée]phone|t[ée]l|phone|gsm|mobile|mob|portable|fixe|whats\s?app)\b\s*\.?\s*:?\s*/iu';

    private function __construct(
        /** Ce qui était saisi, tel quel (pour l'afficher à côté du refus). */
        public readonly string $saisie,
        /** Chiffres seuls, indicatif compris : 212612345678. Null si invalide. */
        public readonly ?string $numero,
        /** mobile | fixe | etranger ; null si invalide. */
        public readonly ?string $nature,
        public readonly ?string $avertissement,
        /** Pourquoi le numéro est refusé ; null s'il est valide. */
        public readonly ?string $raison,
    ) {}

    /**
     * Le numéro d'une saisie. S'il y en a plusieurs dans la case, le PREMIER,
     * qui est presque toujours le mobile — voir plusieurs() pour les avoir tous.
     */
    public static function depuis(?string $saisie): self
    {
        $morceaux = self::morceaux($saisie);

        return $morceaux === []
            ? self::refus(trim((string) $saisie), self::RAISON_VIDE)
            : self::analyser($morceaux[0]);
    }

    /**
     * Tous les numéros d'une case, dans l'ordre de la saisie : « 0612345678 /
     * 0522303030 » en donne deux. Une case vide n'en donne aucun — ce n'est
     * pas un refus, il n'y a simplement rien à proposer.
     *
     * @return list<self>
     */
    public static function plusieurs(?string $saisie): array
    {
        return array_map(self::analyser(...), self::morceaux($saisie));
    }

    /** @return list<string> */
    private static function morceaux(?string $saisie): array
    {
        $texte = trim((string) $saisie);

        if ($texte === '') {
            return [];
        }

        // preg_split rend false sur une saisie qui n'est pas de l'UTF-8
        // valide : elle reste alors d'un bloc, et analyser() la refusera.
        $morceaux = [];
        foreach (preg_split(self::SEPARATEURS, $texte) ?: [$texte] as $morceau) {
            array_push($morceaux, ...self::couperSiAmbigu($morceau));
        }

        // Un morceau sans le moindre chiffre n'est pas un numéro mais un reste
        // d'étiquette (« GSM / WhatsApp : 06… » se coupe en « GSM » et
        // « WhatsApp : 06… ») : le signaler comme numéro refusé serait du
        // bruit. Si AUCUN morceau n'a de chiffre, la case entière est du texte,
        // et c'est elle qu'on refuse, avec sa raison.
        $avecChiffres = array_values(array_filter(
            array_map('trim', $morceaux),
            fn (string $m) => preg_match('/\p{Nd}/u', $m) === 1,
        ));

        return $avecChiffres !== [] ? $avecChiffres : [$texte];
    }

    /** @return list<string> */
    private static function couperSiAmbigu(string $morceau): array
    {
        $parts = preg_split(self::SEPARATEURS_AMBIGUS, $morceau);

        if ($parts === false || count($parts) < 2) {
            return [$morceau];
        }

        foreach ($parts as $part) {
            if (preg_match_all('/\p{Nd}/u', $part) < self::CHIFFRES_MINIMUM) {
                return [$morceau];
            }
        }

        return $parts;
    }

    private static function analyser(string $brut): self
    {
        // Une saisie qui n'est pas de l'UTF-8 valide (copiée d'un vieux
        // fichier) ferait échouer les expressions /u ci-dessous — preg_replace
        // rendrait null, et la chaîne vide qui en sortirait passerait pour
        // « aucun numéro ». Ce n'est pas un numéro : c'est du texte illisible.
        if (preg_match('//u', $brut) !== 1) {
            return self::refus($brut, self::RAISON_TEXTE);
        }

        // Les marques de direction invisibles (U+202A…U+202E, U+200E,
        // U+2066…U+2069, U+FEFF…) qu'ajoutent les contacts d'un iPhone, WhatsApp
        // ou toute interface en arabe autour d'un numéro copié : ni chiffres ni
        // lettres, elles masqueraient le « + » de tête — et un numéro étranger
        // serait refusé faute d'un indicatif pourtant bien là.
        $texte = (string) preg_replace('/\p{Cf}/u', '', strtr($brut, self::CHIFFRES_ORIENTAUX));
        $texte = (string) preg_replace(self::ETIQUETTE, '', $texte);

        // « +212 (0)6 12… », « +33 (0)6… » : le (0) est le préfixe national
        // qu'on ne compose PAS depuis l'étranger.
        $texte = (string) preg_replace('/\(\s*0\s*\)/', '', $texte);

        if (preg_match('/\p{L}/u', $texte) === 1) {
            return self::refus($brut, self::RAISON_TEXTE);
        }

        // « +33… », mais aussi « (+33) 6… » : le « + » peut suivre une
        // parenthèse ouvrante.
        $international = preg_match('/^[\s(]*\+/', $texte) === 1;
        $chiffres = (string) preg_replace('/\D+/', '', $texte);

        if ($chiffres === '') {
            return self::refus($brut, self::RAISON_VIDE);
        }

        if ($international) {
            $complet = $chiffres;
        } elseif (str_starts_with($chiffres, '00')) {
            $complet = substr($chiffres, 2);
        } elseif (str_starts_with($chiffres, self::MAROC)) {
            // « 212612345678 » : déjà au format attendu. Aucun numéro national
            // marocain ne commence par 2, l'ambiguïté n'existe pas.
            $complet = $chiffres;
        } elseif (str_starts_with($chiffres, '0')) {
            if (strlen($chiffres) !== self::LONGUEUR_NATIONALE_MAROC + 1) {
                return self::refus($brut, self::RAISON_LONGUEUR_MAROC);
            }
            $complet = self::MAROC.substr($chiffres, 1);
        } elseif (strlen($chiffres) === self::LONGUEUR_NATIONALE_MAROC) {
            // « 612345678 » : le zéro initial a été oublié.
            $complet = self::MAROC.$chiffres;
        } elseif (strlen($chiffres) < self::LONGUEUR_NATIONALE_MAROC) {
            // Trop court pour être autre chose qu'un numéro marocain tronqué.
            return self::refus($brut, self::RAISON_LONGUEUR_MAROC);
        } else {
            // Trop long pour le Maroc, sans « + » ni « 00 » : un numéro
            // étranger dont on ne sait pas où commence l'indicatif.
            return self::refus($brut, self::RAISON_INDICATIF);
        }

        return str_starts_with($complet, self::MAROC)
            ? self::marocain($brut, substr($complet, strlen(self::MAROC)))
            : self::etranger($brut, $complet);
    }

    private static function marocain(string $brut, string $national): self
    {
        // « +212 06… » et « 2120612… » : le zéro national recopié par habitude.
        if (str_starts_with($national, '0')) {
            $national = substr($national, 1);
        }

        if (strlen($national) !== self::LONGUEUR_NATIONALE_MAROC) {
            return self::refus($brut, self::RAISON_LONGUEUR_MAROC);
        }

        $numero = self::MAROC.$national;

        return match ($national[0]) {
            '6', '7' => new self($brut, $numero, self::NATURE_MOBILE, null, null),
            // 05 : lignes fixes ; 08 : numéros fixes et spéciaux (0800, 0808…).
            '5', '8' => new self($brut, $numero, self::NATURE_FIXE, self::AVERTISSEMENT_FIXE, null),
            default => self::refus($brut, self::RAISON_PREFIXE_MAROC),
        };
    }

    private static function etranger(string $brut, string $complet): self
    {
        // Aucun indicatif pays ne commence par 0 : « +0612… » est un numéro
        // national précédé d'un « + » par erreur.
        if (str_starts_with($complet, '0')) {
            return self::refus($brut, self::RAISON_INDICATIF);
        }

        // Plafond de la norme E.164 (15 chiffres) ; le plancher écarte les
        // saisies tronquées sans exclure les petits pays.
        $longueur = strlen($complet);
        if ($longueur < 8 || $longueur > 15) {
            return self::refus($brut, self::RAISON_LONGUEUR_INTERNATIONALE);
        }

        return new self($brut, $complet, self::NATURE_ETRANGER, null, null);
    }

    private static function refus(string $brut, string $raison): self
    {
        return new self($brut, null, null, null, $raison);
    }

    public function valide(): bool
    {
        return $this->numero !== null;
    }

    /**
     * Le numéro lisible : « +212 6 12 34 56 78 » pour le Maroc. Un numéro
     * étranger reste groupé d'un bloc — découper sans connaître le plan de
     * numérotation du pays inventerait des coupures fausses.
     */
    public function affichage(): ?string
    {
        if ($this->numero === null) {
            return null;
        }

        if ($this->nature === self::NATURE_ETRANGER) {
            return '+'.$this->numero;
        }

        $n = substr($this->numero, strlen(self::MAROC));

        return sprintf('+212 %s %s %s %s %s', $n[0], substr($n, 1, 2), substr($n, 3, 2), substr($n, 5, 2), substr($n, 7, 2));
    }
}
