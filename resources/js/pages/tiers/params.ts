/**
 * Les paramètres qui appartiennent à la FICHE d'un tiers, pas à la liste.
 *
 * Liste et fiche partagent la même chaîne de requête : les liens des lignes
 * la portent, et c'est ce qui garde l'onglet d'un client à l'autre. Mais toute
 * sortie vers AUTRE CHOSE que la fiche du même tiers — fermer, revenir à la
 * liste, créer un tiers — doit les laisser derrière elle : sinon la liste
 * ouvrait chaque ligne sur « Transactions > Factures », et le tiers qu'on
 * venait de créer s'affichait sur un « Aucune pièce de ce type » au lieu de
 * sa vue d'ensemble.
 */
export const PARAMS_FICHE = ['onglet', 'type_piece', 'periode', 'du', 'au', 'compte'] as const;

/**
 * Une date du relevé lue dans l'URL (`?du=`, `?au=`) ou tapée dans un champ :
 * AAAA-MM-JJ, une vraie date, et une année d'au moins 1900 — sinon `null`.
 * L'onglet retombe alors sur le défaut (du 1er janvier à aujourd'hui) au lieu
 * d'envoyer au serveur une valeur qu'il refuserait.
 *
 * 1900 : une année tapée chiffre à chiffre passe par 0002, 0020 puis 0202
 * avant 2025. Aucune n'est une période de relevé ; 0202 partait pourtant au
 * serveur, qui calculait un relevé depuis l'an 202. Et `Date.UTC` lit une
 * année de 0 à 99 comme 1900 + année : 0002 devenait 1902.
 */
export const ANNEE_MIN = 1900;

export function lireDate(valeur: string | null): string | null {
    if (valeur === null || !/^\d{4}-\d{2}-\d{2}$/.test(valeur)) return null;
    const [annee, mois, jour] = valeur.split('-').map(Number);
    if (annee < ANNEE_MIN) return null;
    const date = new Date(0);
    date.setUTCFullYear(annee, mois - 1, jour);

    return date.getUTCFullYear() === annee && date.getUTCMonth() === mois - 1 && date.getUTCDate() === jour ? valeur : null;
}

/** Aujourd'hui au format AAAA-MM-JJ, à l'heure LOCALE — `toISOString` rendrait la veille passé minuit à l'est de Greenwich. */
export function aujourdHui(): string {
    const d = new Date();

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * La période du graphique des revenus (`?periode=`), comme le serveur
 * l'accepte. Absente de l'URL : six mois, le défaut — on ne l'y écrit pas,
 * comme l'onglet « Vue d'ensemble ».
 */
export const PERIODES = ['6m', '12m', 'annee'] as const;
export type Periode = (typeof PERIODES)[number];
export const PERIODE_DEFAUT: Periode = '6m';

/**
 * Une valeur tapée ou périmée dans l'URL retombe sur le défaut SANS appel :
 * le serveur répondrait 422, et la vue d'ensemble entière tomberait pour un
 * paramètre de graphique.
 */
export function lirePeriode(valeur: string | null): Periode {
    return PERIODES.find((p) => p === valeur) ?? PERIODE_DEFAUT;
}

/** La chaîne de requête sans les paramètres de la fiche (`?` compris, ou vide). */
export function sansParamsFiche(search: string): string {
    const params = new URLSearchParams(search);
    for (const cle of PARAMS_FICHE) params.delete(cle);
    const reste = params.toString();

    return reste === '' ? '' : `?${reste}`;
}

/** Ce qu'une fiche supprimée laisse à la liste, pour qu'elle le dise (état de navigation). */
export type EtatRetourListe = { depuis?: number; supprime?: { nom: string; code: string } };
