/**
 * Application des tarifs client dans la caisse.
 *
 * La grille du client est chargée une fois (elle passe par le cache du service
 * worker, donc elle reste disponible hors ligne) puis appliquée localement à
 * chaque changement de quantité. Le serveur reste l'autorité : la caisse ne lui
 * envoie un prix que lorsque le caissier l'a saisi à la main.
 */

export interface PalierTarif {
    quantite_min: number;
    prix: string;
}

export interface TarifProduit {
    produit_id: number;
    paliers: PalierTarif[];
}

export type GrilleTarifaire = Map<number, PalierTarif[]>;

export function construireGrille(tarifs: TarifProduit[]): GrilleTarifaire {
    return new Map(tarifs.map((t) => [t.produit_id, t.paliers]));
}

/**
 * Prix applicable à cette quantité : le palier le plus élevé atteint.
 * Renvoie null si l'article n'a pas de tarif — on garde alors le prix catalogue.
 */
export function prixSelonPaliers(paliers: PalierTarif[] | undefined, quantite: number): number | null {
    if (!paliers || paliers.length === 0) return null;

    const atteint = paliers
        .filter((p) => quantite >= p.quantite_min)
        .sort((a, b) => b.quantite_min - a.quantite_min)[0];

    return atteint ? parseFloat(atteint.prix) : null;
}

/** Prix à afficher pour un article, tarif du client s'il existe. */
export function prixApplicable(
    grille: GrilleTarifaire | undefined,
    produitId: number | null,
    quantite: number,
    prixCatalogue: number,
): number {
    if (grille === undefined || produitId === null) return prixCatalogue;

    return prixSelonPaliers(grille.get(produitId), quantite) ?? prixCatalogue;
}
