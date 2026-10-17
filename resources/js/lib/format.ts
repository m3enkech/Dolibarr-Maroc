const mad = new Intl.NumberFormat('fr-MA', {
    style: 'currency',
    currency: 'MAD',
    minimumFractionDigits: 2,
});

export function formatMAD(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }
    const numeric = typeof value === 'string' ? parseFloat(value) : value;
    return Number.isNaN(numeric) ? '—' : mad.format(numeric);
}

export function formatTva(rate: string | number): string {
    const numeric = typeof rate === 'string' ? parseFloat(rate) : rate;
    return `${Number.isInteger(numeric) ? numeric : numeric.toFixed(2)} %`;
}

/**
 * Ce qu'un mouvement de stock dit de son origine : la référence ET la note.
 * La référence seule ne nomme souvent que la pièce ou l'article — le stock
 * initial porte le code de l'article — ; c'est la note qui dit « Stock
 * initial » ou « Kit … ». Une note qui reprend déjà la référence
 * (« Inventaire INV-… ») la remplace plutôt que de la répéter.
 *
 * La note passe par t() : celles que pose le système sont stockées en
 * français ; une note libre, absente du dictionnaire, ressort telle quelle.
 */
export function origineMouvement(reference: string | null, note: string | null, t: (fr: string) => string): string[] {
    if (!note) {
        return reference ? [reference] : [];
    }

    return !reference || note.includes(reference) ? [t(note)] : [reference, t(note)];
}
