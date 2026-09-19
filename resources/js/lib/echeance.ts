/**
 * Ce qu'il reste à courir avant une échéance — ou depuis combien de temps elle
 * est dépassée.
 *
 * Écrit ici plutôt que dans le composant parce que deux écrans posent déjà la
 * question (la liste des factures et son aperçu) et que les relances la poseront
 * aussi : trois calculs de jours séparés finiraient par diverger d'un jour,
 * précisément le genre d'écart qu'on ne voit pas en relisant.
 *
 * ⚠️ LE CALCUL SE FAIT EN JOURS DE CALENDRIER, pas en millisecondes divisées.
 * Une facture due demain à minuit n'est pas « dans 0,4 jour » : elle est due
 * demain. Et une soustraction d'horodatages se trompe d'un jour deux fois par
 * an, au changement d'heure.
 */

export type EtatEcheance = {
    /** Négatif = dépassée, 0 = aujourd'hui, positif = à venir. */
    jours: number;
    libelle: string;
    /** Assez grave pour être signalé en rouge. */
    depassee: boolean;
    /** Échéance proche : sept jours ou moins. */
    proche: boolean;
};

/** Minuit local, pour comparer des JOURS et non des instants. */
function jourDe(date: Date): number {
    return Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
}

export function etatEcheance(dateEcheance: string | null, aujourdhui = new Date()): EtatEcheance | null {
    if (!dateEcheance) return null;

    // `new Date('2026-09-18')` est interprété en UTC ; on découpe nous-mêmes
    // pour rester dans le fuseau de l'utilisateur.
    const [an, mois, jour] = dateEcheance.split('-').map(Number);

    if (!an || !mois || !jour) return null;

    const jours = Math.round((Date.UTC(an, mois - 1, jour) - jourDe(aujourdhui)) / 86_400_000);

    return {
        jours,
        libelle: libelleEcheance(jours),
        depassee: jours < 0,
        proche: jours >= 0 && jours <= 7,
    };
}

function libelleEcheance(jours: number): string {
    if (jours === 0) return "aujourd'hui";
    if (jours === 1) return 'demain';
    if (jours === -1) return 'hier';

    // La clé de traduction est la phrase française, convention du projet : on
    // rend donc une phrase complète, pas un gabarit à trous.
    return jours > 0 ? `dans ${jours} jours` : `en retard de ${Math.abs(jours)} jours`;
}
