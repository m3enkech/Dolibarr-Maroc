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
export const PARAMS_FICHE = ['onglet', 'type_piece', 'periode'] as const;

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
