import { api } from '@/lib/api';
import type { Paginated } from '@/types';

/** Ce qui a pu être lu, et si c'est TOUT : la caisse le dit quand il en manque. */
export interface Lecture<T> {
    elements: T[];
    complet: boolean;
}

/**
 * Pages lues en même temps. Une à une, le catalogue de Media Desk (4 pages)
 * prenait quatre allers-retours avant d'afficher la moindre tuile ; toutes
 * d'un coup, un catalogue de 20 000 articles lancerait quarante requêtes
 * lourdes (sous-requêtes de stock) sur la base à chaque ouverture de caisse.
 */
const PAGES_EN_PARALLELE = 4;

/**
 * Lit une liste paginée en ENTIER, pour la caisse.
 *
 * La caisse cherche sur place (saisie, douchette, grille) et doit marcher hors
 * ligne : elle ne peut pas interroger le serveur à chaque frappe. Elle garde
 * donc en mémoire des listes complètes — catalogue, niveaux de stock, annuaire
 * client. Une seule page de 500 s'arrêtait en route : au-delà, un article
 * n'était ni affiché ni trouvé par la douchette.
 *
 * Les adresses sont FIXES (mêmes filtres, `per_page` puis `page`) : le service
 * worker met chaque page en cache par URL et la ressert après un rechargement
 * hors ligne. Il faut pour cela que le serveur trie de façon stable — nom PUIS
 * identifiant — sans quoi deux homonymes pourraient changer de page d'une
 * lecture à l'autre.
 *
 * La page 1 d'abord (elle dit combien il y en a), puis les suivantes par lots
 * parallèles. Une page 1 en échec est une erreur (rien à montrer). Une page
 * suivante en échec (hors ligne, jamais mise en cache) ne fait perdre qu'elle :
 * une liste partielle vaut mieux que pas de liste, et `complet` le signale.
 * `pagesMax` borne la lecture si le serveur annonçait un nombre de pages
 * déraisonnable.
 *
 * `cle` identifie un élément : voir `lireUneFois` pour ce qu'on en fait.
 */
export async function chargerParPages<T>(
    url: string,
    filtres: Record<string, string | number | undefined>,
    parPage: number,
    pagesMax: number,
    cle: (element: T) => number,
): Promise<Lecture<T>> {
    const premiere = await lireUneFois(url, filtres, parPage, pagesMax, cle);
    if (premiere.coherente || !premiere.toutLu) return premiere.lecture;

    // Toutes les pages sont arrivées, mais pas le même catalogue : il a changé
    // pendant la lecture. Une seconde lecture tombe presque toujours dans le
    // calme ; si elle aussi est bousculée, on le DIT plutôt que de relire sans fin.
    const seconde = await lireUneFois(url, filtres, parPage, pagesMax, cle);

    return seconde.coherente ? seconde.lecture : { ...seconde.lecture, complet: false };
}

interface Passage<T> {
    lecture: Lecture<T>;
    /** Chaque page annoncée a été reçue (et la borne `pagesMax` n'a pas coupé). */
    toutLu: boolean;
    /** Même total sur chaque page, et autant d'éléments distincts que ce total. */
    coherente: boolean;
}

/**
 * Une lecture complète, et son contrôle.
 *
 * Pages par OFFSET : si le catalogue change entre deux pages, tout ce qui suit
 * se décale. Un article supprimé (ou renommé vers la fin de l'alphabet) sur une
 * page déjà lue fait reculer le suivant sur cette page-là — jamais reçu, sans
 * que rien le signale ; un article créé en tête fait revenir le dernier d'une
 * page au début de la suivante — reçu deux fois, deux tuiles, et Entrée ne
 * trouvait plus de « résultat unique ». D'où le dédoublonnage par `cle`, puis
 * la comparaison au total annoncé : un écart, ou un total qui varie d'une page
 * à l'autre, trahit une lecture bousculée.
 */
async function lireUneFois<T>(
    url: string,
    filtres: Record<string, string | number | undefined>,
    parPage: number,
    pagesMax: number,
    cle: (element: T) => number,
): Promise<Passage<T>> {
    const lire = async (page: number) =>
        (await api.get<Paginated<T>>(url, { params: { ...filtres, per_page: parPage, page } })).data;

    const premiere = await lire(1);
    const derniere = Math.min(premiere.meta.last_page, pagesMax);

    // Dans l'ordre des pages, une page manquante laissant son trou : l'ordre
    // alphabétique de la grille reste celui du serveur.
    const pages: (Paginated<T> | null)[] = [premiere];
    for (let debut = 2; debut <= derniere; debut += PAGES_EN_PARALLELE) {
        const numeros: number[] = [];
        for (let page = debut; page <= Math.min(debut + PAGES_EN_PARALLELE - 1, derniere); page++) {
            numeros.push(page);
        }
        const lot = await Promise.allSettled(numeros.map(lire));
        pages.push(...lot.map((issue) => (issue.status === 'fulfilled' ? issue.value : null)));
    }

    const recues = pages.filter((page): page is Paginated<T> => page !== null);
    const vus = new Set<number>();
    const elements: T[] = [];
    for (const page of recues) {
        for (const element of page.data) {
            const id = cle(element);
            if (vus.has(id)) continue;
            vus.add(id);
            elements.push(element);
        }
    }

    const toutLu = recues.length === pages.length && premiere.meta.last_page <= pagesMax;
    const total = premiere.meta.total;
    const coherente = recues.every((page) => page.meta.total === total) && elements.length === total;

    return { lecture: { elements, complet: toutLu && coherente }, toutLu, coherente };
}
