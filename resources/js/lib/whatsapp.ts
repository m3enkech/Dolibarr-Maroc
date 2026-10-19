/*
 * WhatsApp côté navigateur : le numéro ramené au format wa.me, et le lien
 * « click-to-chat ».
 *
 * POURQUOI UNE COPIE DU SERVEUR. Le lien doit être construit DANS le
 * gestionnaire du clic : un window.open (ou une navigation) placé après un
 * aller-retour réseau est bloqué comme fenêtre surgissante. Le numéro saisi à
 * la main doit donc être normalisé ici, sans attendre le serveur. Cette copie
 * suit App\Core\Format\NumeroWhatsApp règle par règle — mêmes formats
 * acceptés, mêmes refus, mêmes phrases — et le serveur re-normalise de toute
 * façon au journal : une divergence se verrait en 422, jamais en message parti
 * vers un inconnu. Toute retouche de l'une se reporte sur l'autre.
 */

export type NatureNumero = 'mobile' | 'fixe' | 'etranger';

/** Les raisons de refus, codées : l'écran les écrit en toutes lettres (et les traduit). */
export type RaisonNumero = 'vide' | 'texte' | 'longueur_maroc' | 'prefixe_maroc' | 'indicatif' | 'longueur_internationale';

export interface NumeroAnalyse {
    /** Ce qui était saisi, tel quel. */
    saisie: string;
    /** Chiffres seuls, indicatif compris : 212612345678. Null si refusé. */
    numero: string | null;
    nature: NatureNumero | null;
    raison: RaisonNumero | null;
}

/** Indicatif du Maroc, et longueur du numéro national sans le 0 initial. */
const MAROC = '212';
const LONGUEUR_NATIONALE_MAROC = 9;

/** « 06… / 05… », « 06… ; 05… », « 06… ou 05… » — le tiret, lui, groupe les chiffres d'UN numéro. */
const SEPARATEURS = /\s*(?:[/,;|]|\s(?:ou|et|or)\s)\s*/iu;

/**
 * Les séparateurs AMBIGUS — saut de ligne, tiret entouré d'espaces, tiret
 * demi-cadratin ou cadratin : ils séparent souvent deux numéros, mais groupent
 * parfois les chiffres d'un seul (« 06 - 12 - 34 - 56 - 78 »). On ne coupe que
 * si CHAQUE morceau a la taille d'un numéro. Le `\R` de PHP s'écrit ici en
 * clair (\p{Zl}, \p{Zp} : séparateurs de ligne et de paragraphe Unicode).
 */
const SEPARATEURS_AMBIGUS = /\s*(?:\r\n|[\n\v\f\r\x85\p{Zl}\p{Zp}])\s*|\s+\p{Pd}\s+|\s*[‒–—]\s*/u;

/** Le moins de chiffres d'un numéro complet (plancher international). */
const CHIFFRES_MINIMUM = 8;

/** L'étiquette de tête de case (« Tél : », « GSM »…), et elle seule : tout autre texte est un refus. */
const ETIQUETTE = /^\s*(?:t[ée]l[ée]phone|t[ée]l|phone|gsm|mobile|mob|portable|fixe|whats\s?app)\b\s*\.?\s*:?\s*/iu;

function refus(saisie: string, raison: RaisonNumero): NumeroAnalyse {
    return { saisie, numero: null, nature: null, raison };
}

/** Chiffres arabes orientaux (٠…٩) et persans (۰…۹), tapés depuis un clavier arabe. */
function chiffresLatins(texte: string): string {
    return texte
        .replace(/[٠-٩]/g, (c) => String(c.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (c) => String(c.charCodeAt(0) - 0x06f0));
}

function couperSiAmbigu(morceau: string): string[] {
    const parts = morceau.split(SEPARATEURS_AMBIGUS);
    if (parts.length < 2) return [morceau];

    return parts.every((p) => (p.match(/\p{Nd}/gu) ?? []).length >= CHIFFRES_MINIMUM) ? parts : [morceau];
}

function morceaux(saisie: string): string[] {
    const texte = saisie.trim();
    if (texte === '') return [];

    // Un morceau sans chiffre est un reste d'étiquette (« GSM / WhatsApp : 06… ») ;
    // si AUCUN n'en a, c'est la case entière qu'on refuse, avec sa raison.
    const avecChiffres = texte
        .split(SEPARATEURS)
        .flatMap(couperSiAmbigu)
        .map((m) => m.trim())
        .filter((m) => /\p{Nd}/u.test(m));

    return avecChiffres.length > 0 ? avecChiffres : [texte];
}

function marocain(brut: string, national: string): NumeroAnalyse {
    // « +212 06… » : le zéro national recopié par habitude.
    const n = national.startsWith('0') ? national.slice(1) : national;
    if (n.length !== LONGUEUR_NATIONALE_MAROC) return refus(brut, 'longueur_maroc');

    const numero = MAROC + n;
    if (n[0] === '6' || n[0] === '7') return { saisie: brut, numero, nature: 'mobile', raison: null };
    // 05 : lignes fixes ; 08 : fixes et numéros spéciaux. Signalés, pas refusés.
    if (n[0] === '5' || n[0] === '8') return { saisie: brut, numero, nature: 'fixe', raison: null };

    return refus(brut, 'prefixe_maroc');
}

function etranger(brut: string, complet: string): NumeroAnalyse {
    // Aucun indicatif pays ne commence par 0 : « +0612… » est un national précédé d'un « + ».
    if (complet.startsWith('0')) return refus(brut, 'indicatif');
    // E.164 plafonne à 15 chiffres ; le plancher écarte les saisies tronquées.
    if (complet.length < 8 || complet.length > 15) return refus(brut, 'longueur_internationale');

    return { saisie: brut, numero: complet, nature: 'etranger', raison: null };
}

function analyser(brut: string): NumeroAnalyse {
    // Les marques de direction invisibles (U+202A…, U+200E, U+2066…) d'un numéro
    // copié depuis un iPhone, WhatsApp ou une interface arabe : elles masqueraient
    // le « + » de tête.
    let texte = chiffresLatins(brut).replace(/\p{Cf}/gu, '').replace(ETIQUETTE, '');
    // « +212 (0)6… » : le (0) national qu'on ne compose pas depuis l'étranger.
    texte = texte.replace(/\(\s*0\s*\)/g, '');

    if (/\p{L}/u.test(texte)) return refus(brut, 'texte');

    const international = /^[\s(]*\+/.test(texte);
    const chiffres = texte.replace(/\D+/g, '');

    if (chiffres === '') return refus(brut, 'vide');

    let complet: string;
    if (international) {
        complet = chiffres;
    } else if (chiffres.startsWith('00')) {
        complet = chiffres.slice(2);
    } else if (chiffres.startsWith(MAROC)) {
        complet = chiffres;
    } else if (chiffres.startsWith('0')) {
        if (chiffres.length !== LONGUEUR_NATIONALE_MAROC + 1) return refus(brut, 'longueur_maroc');
        complet = MAROC + chiffres.slice(1);
    } else if (chiffres.length === LONGUEUR_NATIONALE_MAROC) {
        // « 612345678 » : le zéro initial oublié.
        complet = MAROC + chiffres;
    } else if (chiffres.length < LONGUEUR_NATIONALE_MAROC) {
        return refus(brut, 'longueur_maroc');
    } else {
        // Trop long pour le Maroc, sans « + » ni « 00 » : où commence l'indicatif ?
        return refus(brut, 'indicatif');
    }

    return complet.startsWith(MAROC) ? marocain(brut, complet.slice(MAROC.length)) : etranger(brut, complet);
}

/**
 * Le numéro d'une saisie — le PREMIER s'il y en a plusieurs, comme
 * NumeroWhatsApp::depuis() que rejoue le serveur au journal.
 */
export function normaliserNumero(saisie: string): NumeroAnalyse {
    const liste = morceaux(saisie);

    return liste.length === 0 ? refus(saisie.trim(), 'vide') : analyser(liste[0]);
}

/** « +212 6 12 34 56 78 » ; un numéro étranger reste d'un bloc, faute de connaître son plan de numérotation. */
export function affichageNumero(analyse: Pick<NumeroAnalyse, 'numero' | 'nature'>): string | null {
    const { numero, nature } = analyse;
    if (numero === null) return null;
    if (nature === 'etranger') return `+${numero}`;

    const n = numero.slice(MAROC.length);

    return `+212 ${n[0]} ${n.slice(1, 3)} ${n.slice(3, 5)} ${n.slice(5, 7)} ${n.slice(7, 9)}`;
}

/**
 * Ce que le serveur relit SANS ambiguïté : avec le « + ». Les seuls chiffres
 * d'un numéro étranger (« 33612… ») seraient refusés là-bas faute d'indicatif.
 */
export function numeroPourServeur(numero: string | null): string | null {
    return numero === null ? null : `+${numero}`;
}

/**
 * Le codage de `rawurlencode` (RFC 3986) : encodeURIComponent laisse passer
 * ! ' ( ) *, que PHP code. Sans cette retouche, le lien ouvert ici et celui
 * que renvoie le journal différeraient à chaque apostrophe (« d'un montant »).
 * Les demi-paires UTF-16 orphelines (un collage abîmé) feraient lever
 * encodeURIComponent : elles deviennent le caractère de remplacement.
 */
function coderTexte(texte: string): string {
    // Parcours par point de code : une demi-paire orpheline y sort seule, sur
    // une unité. (Pas d'assertion arrière dans une expression régulière : un
    // Safari d'avant 16.4 refuserait d'analyser tout le module.)
    const propre = [...texte].map((c) => (c.length === 1 && c >= '\ud800' && c <= '\udfff' ? '�' : c)).join('');

    return encodeURIComponent(propre).replace(/[!'()*]/g, (c) => `%${c.charCodeAt(0).toString(16).toUpperCase()}`);
}

/**
 * Le lien wa.me, comme App\Modules\Communications\Transport\ClickToChat::lien.
 * Sans numéro, WhatsApp laisse choisir le contact, message prêt.
 */
export function lienClickToChat(numero: string | null, message: string): string {
    return `https://wa.me/${numero ?? ''}?text=${coderTexte(message)}`;
}

/** Longueur comptée comme `max:` de Laravel (mb_strlen) : en caractères, pas en unités UTF-16. */
export function longueurMessage(texte: string): number {
    return [...texte].length;
}

/** Miroir de config('communications.message_longueur_max'). */
export const MESSAGE_LONGUEUR_MAX = 2000;
