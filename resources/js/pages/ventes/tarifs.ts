/**
 * Tarif du client dans le formulaire de vente.
 *
 * Le calcul reste au SERVEUR (GET /ventes/prix, TarifService) : une copie de
 * la règle dans le navigateur finirait par diverger de celle qui facture. La
 * caisse, elle, garde sa grille locale (pages/pos/tarifs.ts) parce qu'elle
 * doit vendre hors ligne ; ce formulaire n'a pas cette contrainte.
 */
import { api } from '@/lib/api';

export interface TarifLigne {
    produit_id: number;
    quantite: number;
    /** Prix unitaire HT, au format des montants du serveur ("72.00"). */
    prix: string;
    /** client = prix négocié ; categorie = niveau de tarif du client (ou par défaut). */
    origine: 'client' | 'categorie' | 'catalogue';
    /** Quantité à partir de laquelle ce prix s'applique ; `null` au catalogue. */
    palier: number | null;
    /** Nom de la catégorie tarifaire, quand le prix en vient. */
    categorie: string | null;
    /**
     * Catégorie PAR DÉFAUT de l'entreprise, faute de client choisi ou de
     * catégorie sur sa fiche : ce n'est pas un tarif propre au client.
     */
    par_defaut: boolean;
}

/*
 * Lignes par appel. Le lot part en GET (une lecture, permise au rôle qui ne
 * fait que consulter les ventes) : cinquante lignes tiennent en moins de 4 Ko
 * d'adresse, loin des 8 Ko au-delà desquels un serveur frontal refuse la
 * requête. Le serveur en accepte cent.
 */
const LIGNES_PAR_APPEL = 50;

/** Tarifs de ces lignes pour ce client (`''` : pas encore de client), dans l'ordre demandé. */
export async function lireTarifs(
    tiersId: string,
    lignes: { produit_id: number; quantite: number }[],
): Promise<TarifLigne[]> {
    const lots: (typeof lignes)[] = [];
    for (let i = 0; i < lignes.length; i += LIGNES_PAR_APPEL) {
        lots.push(lignes.slice(i, i + LIGNES_PAR_APPEL));
    }

    const reponses = await Promise.all(
        lots.map(async (lot) => {
            const params = new URLSearchParams();
            if (tiersId) params.set('tiers_id', tiersId);
            lot.forEach((l, i) => {
                params.set(`lignes[${i}][produit_id]`, String(l.produit_id));
                params.set(`lignes[${i}][quantite]`, String(l.quantite));
            });
            // Borné : tant qu'un tarif est attendu, l'enregistrement l'attend
            // aussi. Mieux vaut « tarif indisponible » qu'un bouton grisé sans fin.
            const { data } = await api.get<{ data: TarifLigne[] }>('/ventes/prix', { params, timeout: 15_000 });
            return data.data;
        }),
    );

    const tarifs = reponses.flat();
    // Les réponses se lisent par position : une réponse qui ne correspond pas
    // ligne à ligne ne doit rien écrire, plutôt qu'écrire le prix d'un autre
    // article.
    if (tarifs.length !== lignes.length || tarifs.some((t, i) => t.produit_id !== lignes[i].produit_id)) {
        throw new Error('Réponse de tarif inattendue.');
    }
    return tarifs;
}

const nombre = (n: number) => n.toLocaleString('fr-FR', { maximumFractionDigits: 3 });

/**
 * Mention affichée sous le prix : d'où il vient. `detail` dit la source exacte
 * (prix négocié, nom de la catégorie) à qui survole ou utilise un lecteur
 * d'écran ; il complète `texte` sans le répéter.
 *
 * « Remise quantité » quand le palier retenu commence au-delà d'une unité :
 * c'est la quantité qui a ouvert ce prix, et le vendeur doit savoir qu'il
 * remontera s'il baisse la quantité.
 *
 * « Tarif par défaut » quand le prix vient de la catégorie par défaut de
 * l'entreprise (aucun client choisi, ou client sans catégorie) : « Tarif
 * client » ferait croire à un prix négocié qui n'existe pas.
 */
export function libelleTarif(t: TarifLigne): { texte: string; detail: string } {
    if (t.origine === 'catalogue') {
        return { texte: 'Prix catalogue', detail: 'aucun tarif client pour cet article' };
    }

    const detail =
        t.origine === 'client'
            ? 'prix négocié pour ce client'
            : `${t.par_defaut ? 'catégorie par défaut' : 'catégorie tarifaire'} « ${t.categorie ?? '—'} »`;

    if (t.palier !== null && t.palier > 1) {
        return { texte: `Remise quantité dès ${nombre(t.palier)}`, detail };
    }

    return { texte: t.par_defaut ? 'Tarif par défaut' : 'Tarif client', detail };
}
