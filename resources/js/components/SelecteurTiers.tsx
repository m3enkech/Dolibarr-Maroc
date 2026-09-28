import type { QueryClient } from '@tanstack/react-query';
import ComboboxRecherche, {
    TAILLE_PAGE,
    useTraduction,
    type ElementConnu,
    type StyleOption,
    type TextesCombobox,
} from '@/components/ComboboxRecherche';
import { api } from '@/lib/api';
import type { Paginated, Tiers } from '@/types';

/** Filtre de rôle compris par GET /tiers (`type`) — voir TiersController::index. */
export type TypeTiers = 'client' | 'fournisseur' | 'prospect';

/** Le strict nécessaire pour AFFICHER un tiers choisi, quand l'appelant le connaît déjà. */
export type TiersChoisi = ElementConnu;

/**
 * La requête de recherche, exportée pour pouvoir la PRÉCHARGER (voir la caisse).
 *
 * L'ordre des paramètres n'est pas indifférent : le service worker de la caisse
 * met les réponses en cache par URL, et une même recherche doit toujours
 * produire la même adresse pour être retrouvée hors ligne. `search` vide est
 * omis (axios saute les `undefined`) : la liste d'ouverture reste ainsi une
 * seule et même URL, quelle que soit la façon dont on y arrive.
 *
 * `networkMode: 'always'` : par défaut, React Query MET EN PAUSE toute requête
 * dès que le navigateur se dit hors ligne. La requête n'atteignait alors jamais
 * le service worker (qui a peut-être la réponse en cache), aucune erreur ne
 * survenait, et la liste d'ouverture restait affichée en pleine opacité comme
 * si elle répondait à ce qu'on venait de taper. Toujours partir : soit le
 * service worker répond, soit la requête échoue et l'échec se VOIT.
 */
export function optionsRechercheTiers(type: TypeTiers | undefined, terme: string) {
    return {
        queryKey: ['selecteur-tiers', type ?? 'tous', terme],
        queryFn: async () =>
            (
                await api.get<Paginated<Tiers>>('/tiers', {
                    params: { search: terme === '' ? undefined : terme, type, per_page: TAILLE_PAGE },
                })
            ).data,
        networkMode: 'always' as const,
    };
}

/** Relecture d'un tiers venu d'ailleurs (pré-remplissage, modification). */
function optionsFicheTiers(id: number | null) {
    return {
        queryKey: ['selecteur-tiers-fiche', id],
        queryFn: async () => (await api.get<{ data: Tiers }>(`/tiers/${id}`)).data.data,
    };
}

/**
 * Filtre local, calqué sur le filtre serveur (nom, code ou ICE contient le
 * texte, sans égard à la casse). Sert au repli hors connexion uniquement.
 * Fonction de MODULE : le moteur mémoïse la liste filtrée sur elle.
 */
function filtrerLocal(tiers: Tiers[], texte: string): Tiers[] {
    const cherche = texte.toLocaleLowerCase();
    if (cherche === '') return tiers;

    return tiers.filter((x) =>
        [x.name, x.code, x.ice].some((champ) => champ != null && champ.toLocaleLowerCase().includes(cherche)),
    );
}

/** Charge d'avance la liste d'ouverture (les vingt premiers tiers du type donné). */
export function prechargerTiers(client: QueryClient, type?: TypeTiers): Promise<void> {
    return client.prefetchQuery(optionsRechercheTiers(type, ''));
}

interface Props {
    /** Identifiant du tiers choisi, `null` s'il n'y en a pas. */
    value: number | null;
    /** Le tiers complet est fourni quand il vient d'être pris dans la liste ; `null` quand on efface. */
    onChange: (id: number | null, tiers: Tiers | null) => void;
    /** Restreint la recherche à un rôle. Sans filtre, chaque ligne affiche le rôle du tiers. */
    type?: TypeTiers;
    /** Identifiant du champ, pour un `<label htmlFor>`. */
    id?: string;
    required?: boolean;
    disabled?: boolean;
    placeholder?: string;
    /**
     * Libellé d'une option « pas de tiers » (valeur `null`) proposée en tête de
     * liste — « Client comptoir » en caisse. Elle ne dépend d'aucune requête :
     * elle reste donc choisissable hors ligne.
     */
    aucun?: string;
    /** Libellé déjà connu de l'appelant (document en modification…) : épargne un aller-retour. */
    tiersConnu?: TiersChoisi | null;
    /**
     * Tiers gardés en mémoire par l'appelant, filtrés sur place quand le
     * serveur ne répond plus (coupure réseau). La caisse y met son annuaire
     * client : sans lui, hors ligne, seuls les vingt premiers clients et les
     * recherches déjà faites resteraient choisissables. En ligne, la recherche
     * serveur reste la seule voie : elle voit les tiers créés depuis.
     */
    repliLocal?: Tiers[];
    /** Classes du conteneur : largeur, marges. */
    className?: string;
    /** Champ plus bas, aligné sur les formulaires de document (`py-1.5`). */
    compact?: boolean;
    /** Habillage sombre de la caisse. */
    sombre?: boolean;
    /**
     * `false` sur les écrans restés en français par décision (facturation,
     * comptabilité) : le sélecteur n'y passe pas à l'arabe au milieu d'une page
     * qui ne l'est pas.
     */
    traduire?: boolean;
    title?: string;
    /** Nom accessible, quand aucun `<label htmlFor>` ne désigne le champ. */
    'aria-label'?: string;
}

/**
 * Choix d'un tiers par recherche CÔTÉ SERVEUR.
 *
 * Les formulaires chargeaient jusqu'ici « tous » les tiers dans un <select> —
 * en réalité les 200 ou 300 premiers par ordre alphabétique. Au-delà (Mediadesk
 * compte 368 clients), un client n'était tout simplement PAS choisissable sur
 * un devis. Rien ne le signalait : la liste avait l'air complète.
 *
 * On interroge donc GET /tiers à la frappe (nom, code ou ICE), vingt résultats
 * à la fois. Le comportement du champ (clavier, ARIA, liste fiable, repli hors
 * ligne) vit dans ComboboxRecherche, partagé avec le choix d'un article ; ce
 * composant n'apporte que la requête, la ligne d'option et ses mots.
 */
export default function SelecteurTiers({ type, tiersConnu = null, traduire = true, ...reste }: Props) {
    const t = useTraduction(traduire);

    const textes: TextesCombobox = {
        placeholder: t('Rechercher un tiers (nom, code, ICE)…'),
        requis: t('Choisissez un tiers dans la liste.'),
        introuvable: (id) => t('Tiers introuvable (n° {id})', { id }),
        numero: (id) => t('Tiers n° {id}', { id }),
        aucunResultat: (terme) => t('Aucun tiers ne correspond à « {terme} ».', { terme }),
        aucunAProposer: t('Aucun tiers à proposer.'),
        tropDeResultats: (total) => t('{total} tiers trouvés : précisez la recherche pour voir les autres.', { total }),
        // Hors connexion, dire d'où vient la liste : les tiers créés depuis le
        // dernier chargement de l'annuaire n'y sont pas.
        repli: (n) => t('Hors ligne : recherche parmi les {n} tiers gardés sur ce poste.', { n }),
        nomListe: t('Tiers proposés'),
        annonce: (n) => t('{n} tiers proposés.', { n }),
    };

    const roleDe = (tiers: Tiers): string | null =>
        tiers.is_prospect ? t('Prospect') : tiers.is_client ? t('Client') : tiers.is_supplier ? t('Fournisseur') : null;

    const rendreOption = (option: Tiers, s: StyleOption) => {
        const role = type === undefined ? roleDe(option) : null;

        return (
            <>
                {/* `dir="auto"` : un nom arabe dans une page française (et
                    l'inverse) garde son sens de lecture. */}
                <span dir="auto" className="min-w-0 flex-1 truncate font-medium">
                    {option.name}
                </span>
                {!option.is_active && <span className={`shrink-0 text-xs ${s.secondaire}`}>{t('Inactif')}</span>}
                {role !== null && <span className={`shrink-0 text-xs ${s.secondaire}`}>{role}</span>}
                <span className={`shrink-0 font-mono text-xs ${s.secondaire}`}>{option.code}</span>
            </>
        );
    };

    return (
        <ComboboxRecherche<Tiers>
            {...reste}
            recherche={(terme) => optionsRechercheTiers(type, terme)}
            fiche={optionsFicheTiers}
            filtrerLocal={filtrerLocal}
            rendreOption={rendreOption}
            textes={textes}
            connu={tiersConnu}
            traduire={traduire}
        />
    );
}
