import {
    useEffect,
    useId,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
import { keepPreviousData, useQuery, type QueryKey } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { interpoler, useT } from '@/lib/langue';
import { useDebounce } from '@/lib/useDebounce';
import type { Paginated } from '@/types';

/*
 * Moteur COMMUN des sélecteurs à recherche serveur (tiers, articles).
 *
 * Il est né dans SelecteurTiers, où chaque ligne a été relue et corrigée :
 * liste « fiable » (Entrée ne choisit jamais le résultat d'une frappe
 * précédente), fermeture sur la sortie du CONTENEUR (Tab via ✕), ouverture
 * vers le haut quand la place manque, clic sur le pied de liste qui ne ferme
 * pas, requêtes qui partent même hors ligne pour que l'échec se voie. Une
 * seconde copie pour les articles aurait laissé ces correctifs diverger à la
 * première retouche : le comportement vit donc ici, une seule fois, et chaque
 * sélecteur ne fournit que ce qui lui est propre — la requête, la ligne
 * d'option, les mots (« tiers », « article »).
 */

/** Le strict nécessaire pour AFFICHER un élément choisi, quand l'appelant le connaît déjà. */
export interface ElementConnu {
    id: number;
    name: string;
    code?: string | null;
}

/**
 * Nombre de propositions. De quoi reconnaître un nom dans la liste, pas de quoi
 * la faire défiler : au-delà, taper deux lettres de plus va plus vite.
 */
export const TAILLE_PAGE = 20;

/** Hauteur maximale de la liste (l'ancien `max-h-72`), en pixels. */
const HAUTEUR_LISTE = 288;
/** Ce que la liste ne peut pas occuper : l'écart au champ, le pied de liste, une marge. */
const RESERVE_POPUP = 48;
/** En deçà, une liste devient illisible : on la laisse dépasser plutôt. */
const HAUTEUR_LISTE_MIN = 120;

/**
 * La recherche telle que la fournit un sélecteur : clé, appel, et
 * `networkMode: 'always'` (voir optionsRechercheTiers pour le pourquoi). Le
 * moteur y ajoute ce qui ne dépend pas de l'entité : activation à
 * l'ouverture, résultats gardés pendant la frappe, pas de nouvel essai.
 */
export interface RequeteRecherche<T> {
    queryKey: QueryKey;
    queryFn: () => Promise<Paginated<T>>;
    networkMode: 'always';
}

/** Relecture d'UN élément par son identifiant (valeur venue d'ailleurs). */
export interface RequeteFiche<T> {
    queryKey: QueryKey;
    queryFn: () => Promise<T>;
}

export type Traduction = (modele: string, vars?: Record<string, string | number>) => string;

/**
 * t(), ou la simple substitution des `{variables}` sur un écran resté en
 * français par décision (facturation) : un sélecteur partagé n'y passe pas à
 * l'arabe au milieu d'une page qui ne l'est pas. Les sélecteurs l'emploient
 * aussi pour leurs propres mots, qui doivent suivre la même règle.
 */
export function useTraduction(traduire: boolean): Traduction {
    const t = useT();

    return traduire ? t : interpoler;
}

/** Les mots propres à l'entité, déjà passés par useTraduction. */
export interface TextesCombobox {
    /** Texte d'attente du champ quand l'appelant n'en donne pas. */
    placeholder: string;
    /** Message de validité quand le choix est obligatoire et absent. */
    requis: string;
    /** Identifiant qui n'existe pas (ou plus) : 404 à la relecture. */
    introuvable: (id: number) => string;
    /** Relecture impossible pour une autre raison (réseau, refus). */
    numero: (id: number) => string;
    aucunResultat: (terme: string) => string;
    aucunAProposer: string;
    tropDeResultats: (total: number) => string;
    /** Avis du repli hors connexion — requis seulement avec `repliLocal`. */
    repli?: (n: number) => string;
    /** Nom accessible de la liste, à défaut d'`aria-label`. */
    nomListe: string;
    /** Annonce vocale du nombre de propositions. */
    annonce: (n: number) => string;
}

/** Ce qu'une ligne d'option peut réutiliser de l'habillage (clair ou sombre). */
export interface StyleOption {
    secondaire: string;
}

const estIntrouvable = (erreur: unknown): boolean => isAxiosError(erreur) && erreur.response?.status === 404;

/** Nom, puis code : le nom se lit, le code départage deux homonymes. */
const libelleDe = (element: ElementConnu): string => (element.code ? `${element.name} · ${element.code}` : element.name);

export interface PropsCombobox<T extends ElementConnu> {
    /** Identifiant choisi, `null` s'il n'y en a pas. */
    value: number | null;
    /** L'élément complet est fourni quand il vient d'être pris dans la liste ; `null` quand on efface. */
    onChange: (id: number | null, element: T | null) => void;
    recherche: (terme: string) => RequeteRecherche<T>;
    /** Reçoit `null` quand rien n'est choisi : la requête est alors inactive. */
    fiche: (id: number | null) => RequeteFiche<T>;
    /** Contenu d'une option, à la suite de la coche. */
    rendreOption: (element: T, style: StyleOption) => ReactNode;
    textes: TextesCombobox;
    /**
     * Filtre du repli hors connexion. DOIT être stable (fonction de module) :
     * la liste filtrée est mémoïsée sur lui, et un tableau neuf à chaque rendu
     * relancerait sans fin l'effet de l'option active.
     */
    filtrerLocal?: (elements: T[], texte: string) => T[];
    /** Identifiant du champ, pour un `<label htmlFor>`. */
    id?: string;
    required?: boolean;
    disabled?: boolean;
    placeholder?: string;
    /**
     * Libellé d'une option « rien » (valeur `null`) proposée en tête de liste.
     * Elle ne dépend d'aucune requête : elle reste donc choisissable hors ligne.
     */
    aucun?: string;
    /** Libellé déjà connu de l'appelant (document en modification…) : épargne un aller-retour. */
    connu?: ElementConnu | null;
    /** Éléments gardés en mémoire par l'appelant, filtrés sur place quand le serveur ne répond plus. */
    repliLocal?: T[];
    /** Classes du conteneur : largeur, marges. */
    className?: string;
    /** Champ plus bas, aligné sur les formulaires de document (`py-1.5`). */
    compact?: boolean;
    /**
     * Espacement vertical du champ, quand ni l'ordinaire ni `compact` ne
     * s'alignent sur les champs voisins (lignes de document : plus haut en
     * carte, pour le doigt, plus bas en rangée).
     */
    classesHauteur?: string;
    /** Largeur minimale de la liste, qui peut déborder d'un champ étroit. */
    largeurListe?: string;
    /**
     * Entrée sur la liste FERMÉE l'ouvre, au lieu de laisser le formulaire
     * autour se soumettre (soumission implicite d'un champ texte). Pour un
     * champ qui remplace un <select> — lequel ne soumettait pas — et qui se
     * répète à chaque ligne d'un document : un second Entrée « pour valider la
     * ligne » enregistrait la facture à moitié saisie et quittait l'écran.
     */
    entreeOuvre?: boolean;
    /**
     * Le bouton ✕ est-il un arrêt de tabulation ? À `false`, il reste cliquable
     * (et le clic lui donne toujours le focus : la fermeture sur sortie du
     * conteneur tient), mais Tab passe du champ au suivant. Seulement quand la
     * liste offre elle-même de quoi effacer au clavier (option `aucun`).
     */
    effacerTabulable?: boolean;
    /** Habillage sombre de la caisse. */
    sombre?: boolean;
    traduire?: boolean;
    title?: string;
    /** Nom accessible, quand aucun `<label htmlFor>` ne désigne le champ. */
    'aria-label'?: string;
}

/**
 * Choix d'un élément par recherche CÔTÉ SERVEUR, sur le motif « combobox » de
 * l'ARIA : le focus reste dans le champ, l'option active est désignée par
 * `aria-activedescendant`, flèches pour parcourir, Entrée pour choisir, Échap
 * pour refermer.
 *
 * L'élément choisi reste affiché même quand il n'est pas dans la page de
 * résultats courante : on le mémorise au moment du choix, et une valeur venue
 * d'ailleurs (pré-remplissage, modification) est relue par la `fiche`.
 */
export default function ComboboxRecherche<T extends ElementConnu>({
    value,
    onChange,
    recherche: requeteRecherche,
    fiche: requeteFiche,
    rendreOption,
    textes,
    filtrerLocal,
    id,
    required = false,
    disabled = false,
    placeholder,
    aucun,
    connu = null,
    repliLocal,
    className = '',
    compact = false,
    classesHauteur,
    largeurListe = 'min-w-64',
    entreeOuvre = false,
    effacerTabulable = true,
    sombre = false,
    traduire = true,
    title,
    'aria-label': ariaLabel,
}: PropsCombobox<T>) {
    const t = useTraduction(traduire);

    const base = useId();
    const idListe = `${base}-liste`;
    const idOption = (index: number) => `${base}-option-${index}`;

    const champ = useRef<HTMLInputElement>(null);
    const conteneur = useRef<HTMLDivElement>(null);
    const vientDeFocaliser = useRef(false);
    // Entrée pressée avant l'arrivée des résultats du texte tapé (code collé,
    // douchette, frappe rapide) : le choix est différé, pas perdu — ni fait
    // sur une liste qui répond à autre chose.
    const choixEnAttente = useRef(false);

    const [ouvert, setOuvert] = useState(false);
    // `null` = on n'édite pas : le champ montre l'élément choisi. Une chaîne =
    // ce que l'utilisateur est en train de taper.
    const [saisie, setSaisie] = useState<string | null>(null);
    const [actif, setActif] = useState(-1);
    const [memorise, setMemorise] = useState<ElementConnu | null>(null);
    // Vers le haut quand la place manque en dessous : la caisse sur téléphone
    // coupe tout ce qui dépasse du bas de l'écran (racine en overflow-hidden).
    const [placement, setPlacement] = useState({ versLeHaut: false, hauteur: HAUTEUR_LISTE });

    const saisieNette = (saisie ?? '').trim();
    // On retarde le TERME envoyé au serveur, jamais le champ, qui doit rester
    // instantané sous les doigts.
    const terme = useDebounce(saisieNette, 250);

    const recherche = useQuery({
        ...requeteRecherche(terme),
        // Rien n'est demandé tant que la liste est fermée : un formulaire ouvert
        // pour être consulté ne coûte aucune requête.
        enabled: ouvert && !disabled,
        // Les résultats précédents restent affichés pendant la frappe : sans
        // cela la liste clignoterait à chaque lettre.
        placeholderData: keepPreviousData,
        // Hors ligne, un nouvel essai ne ferait que retarder le message ;
        // retaper relance de toute façon la recherche.
        retry: false,
    });

    /* ------------------------- repli hors connexion ------------------------ */

    // Échec SANS réponse du serveur : coupure réseau, et le service worker n'a
    // rien pour cette adresse. Un refus (403, 500…) n'est pas une coupure.
    const erreurReseau =
        recherche.isError && isAxiosError(recherche.error) && recherche.error.response === undefined;

    // Mémoire de la dernière issue connue. Sans elle, chaque lettre tapée hors
    // connexion ferait alterner le repli et « Recherche… » le temps que la
    // nouvelle requête échoue à son tour.
    const [horsConnexion, setHorsConnexion] = useState(false);
    useEffect(() => {
        if (recherche.isError) setHorsConnexion(erreurReseau);
        else if (recherche.isSuccess && !recherche.isPlaceholderData && !recherche.isFetching) setHorsConnexion(false);
    }, [recherche.isError, recherche.isSuccess, recherche.isPlaceholderData, recherche.isFetching, erreurReseau]);

    const enRepli =
        repliLocal !== undefined &&
        repliLocal.length > 0 &&
        filtrerLocal !== undefined &&
        (erreurReseau || (horsConnexion && recherche.isFetching));

    // Mémoïsé : l'effet de l'option active en dépend, un tableau neuf à chaque
    // rendu le relancerait sans fin et rendrait les flèches inopérantes.
    const repliFiltre = useMemo(
        () => (enRepli && filtrerLocal ? filtrerLocal(repliLocal ?? [], saisieNette) : []),
        [enRepli, repliLocal, saisieNette, filtrerLocal],
    );

    /* ---------------------------- résultats affichés ---------------------- */

    // Une erreur n'affiche AUCUN résultat serveur, pas même ceux gardés pour
    // le même terme : une liste sous un message « recherche impossible » se
    // lirait comme à jour.
    const erreur = !enRepli && recherche.isError;

    const resultats = enRepli ? repliFiltre.slice(0, TAILLE_PAGE) : erreur ? [] : (recherche.data?.data ?? []);
    const total = enRepli ? repliFiltre.length : erreur ? 0 : (recherche.data?.meta.total ?? 0);

    /*
     * La liste répond-elle à ce qui est TAPÉ ? Pas forcément : le terme suit la
     * frappe avec 250 ms de retard, et `keepPreviousData` laisse à l'écran la
     * réponse précédente le temps que la nouvelle arrive. Dans les deux cas la
     * liste répond à un AUTRE texte : elle est grisée, sans option active, et
     * Entrée n'y choisit rien — sans quoi « CL-2026-00220 » collé puis Entrée
     * aurait pris le premier élément alphabétique de la liste d'ouverture.
     * Le repli local filtre, lui, sur le texte tapé : il est toujours à jour.
     * En erreur, seule reste l'option « aucun », qui ne dépend de rien.
     */
    const listeFiable =
        enRepli ||
        erreur ||
        (recherche.data !== undefined && !recherche.isPlaceholderData && terme === saisieNette);

    // `null` en tête = l'option « aucun », quand l'appelant en propose une.
    const options: (T | null)[] = aucun !== undefined ? [null, ...resultats] : resultats;
    // Ce dont dépend la liste affichée, pour relancer l'effet de l'option active.
    const sourceListe = enRepli ? repliFiltre : erreur ? null : recherche.data;

    /* --------------------------- élément choisi --------------------------- */

    const connuLocal = [memorise, connu].find((x) => x != null && x.id === value) ?? null;

    const fiche = useQuery({
        ...requeteFiche(value),
        enabled: value !== null && connuLocal === null,
        retry: false,
        staleTime: 60_000,
        // Même raison que pour la recherche : en pause, le champ resterait vide
        // sans rien dire ; en échec, il affiche au moins « Tiers n° 12 ».
        networkMode: 'always',
    });

    const selection: ElementConnu | null =
        value === null ? null : (connuLocal ?? resultats.find((r) => r.id === value) ?? fiche.data ?? null);

    let texteSelection = '';
    if (value !== null && selection !== null) {
        texteSelection = libelleDe(selection);
    } else if (value !== null && fiche.isError) {
        // Un id qui n'existe pas (ou plus) doit se VOIR : l'afficher comme un
        // choix valide laisserait partir un document vers un refus serveur.
        texteSelection = estIntrouvable(fiche.error) ? textes.introuvable(value) : textes.numero(value);
    }
    const chargeSelection = value !== null && selection === null && fiche.isFetching;

    /* ------------------------------ validation ---------------------------- */

    // Pas d'attribut `required` natif : le texte affiché n'est pas la valeur.
    // Un champ rempli de lettres tapées sans rien choisir passerait, et un
    // libellé encore en chargement (champ vide) bloquerait un choix valide.
    // La validité personnalisée porte, elle, sur la VALEUR.
    const messageRequis = textes.requis;
    useEffect(() => {
        champ.current?.setCustomValidity(required && value === null ? messageRequis : '');
    }, [required, value, messageRequis]);

    /* ------------------------------ ouverture ----------------------------- */

    const fermer = () => {
        setOuvert(false);
        setSaisie(null);
        setActif(-1);
        choixEnAttente.current = false;
    };

    const ouvrir = () => {
        if (!disabled) setOuvert(true);
    };

    useEffect(() => {
        if (disabled) fermer();
    }, [disabled]);

    // Clic (ou toucher) hors du composant : on referme sans rien changer.
    useEffect(() => {
        if (!ouvert) return;

        const dehors = (e: PointerEvent) => {
            if (!conteneur.current?.contains(e.target as Node)) fermer();
        };
        document.addEventListener('pointerdown', dehors);

        return () => document.removeEventListener('pointerdown', dehors);
    }, [ouvert]);

    // Sens d'ouverture et hauteur de la liste, mesurés AVANT l'affichage (sans
    // quoi elle apparaîtrait une image en bas, puis sauterait en haut). La
    // fenêtre sert de borne : c'est elle que coupe la caisse, et sur une page
    // qui défile, ouvrir vers le haut près du bas reste l'usage des listes.
    //
    // La fenêtre VISIBLE, pas la fenêtre de mise en page : sur téléphone (iOS,
    // Chrome Android ≥ 108), le clavier virtuel ne réduit que la première.
    // `innerHeight` ne bouge pas, aucun `resize` ne part, et la liste s'ouvrait
    // vers le bas SOUS le clavier — deux options visibles sur une ligne de
    // facture. On remesure donc aussi quand le clavier s'ouvre (resize du
    // viewport visuel) et quand la page défile pour garder le champ en vue.
    useLayoutEffect(() => {
        if (!ouvert) return;

        const visible = window.visualViewport;

        const mesurer = () => {
            const cadre = champ.current?.getBoundingClientRect();
            if (!cadre) return;

            // Coordonnées de la fenêtre de mise en page, comme `cadre`.
            const haut = visible ? visible.offsetTop : 0;
            const bas = visible ? visible.offsetTop + visible.height : window.innerHeight;
            const dessous = bas - cadre.bottom;
            const dessus = cadre.top - haut;
            const versLeHaut = dessous < HAUTEUR_LISTE + RESERVE_POPUP && dessus > dessous;
            const place = (versLeHaut ? dessus : dessous) - RESERVE_POPUP;
            const hauteur = Math.max(HAUTEUR_LISTE_MIN, Math.min(HAUTEUR_LISTE, place));

            // Mesure appelée à chaque pas de défilement : l'état ne change (et
            // la liste ne se redessine) que si le placement change vraiment.
            setPlacement((avant) =>
                avant.versLeHaut === versLeHaut && avant.hauteur === hauteur ? avant : { versLeHaut, hauteur },
            );
        };

        mesurer();
        window.addEventListener('resize', mesurer);
        window.addEventListener('scroll', mesurer, { passive: true });
        visible?.addEventListener('resize', mesurer);
        visible?.addEventListener('scroll', mesurer);

        return () => {
            window.removeEventListener('resize', mesurer);
            window.removeEventListener('scroll', mesurer);
            visible?.removeEventListener('resize', mesurer);
            visible?.removeEventListener('scroll', mesurer);
        };
    }, [ouvert]);

    // Option active, recalculée quand la liste change. Tant qu'elle ne répond
    // pas au texte tapé, AUCUNE (voir `listeFiable`). Sinon : la première si
    // l'on a tapé quelque chose (Entrée prend alors le meilleur candidat), ou
    // l'élément déjà choisi, pour que les flèches repartent de lui.
    useEffect(() => {
        if (!ouvert) return;

        if (!listeFiable) {
            setActif(-1);
            return;
        }

        if (saisieNette !== '') {
            // Entrée attendait ces résultats-là : le meilleur candidat est pris.
            // S'il n'y en a aucun (ou en erreur), la liste reste ouverte sur
            // son message : rien n'est choisi à la place de l'utilisateur.
            if (choixEnAttente.current) {
                choixEnAttente.current = false;
                if (resultats.length > 0) {
                    choisir(resultats[0]);
                    return;
                }
            }
            setActif(resultats.length > 0 ? (aucun !== undefined ? 1 : 0) : -1);
        } else {
            choixEnAttente.current = false;
            setActif(options.findIndex((o) => (o?.id ?? null) === value));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ouvert, listeFiable, sourceListe, saisieNette]);

    useEffect(() => {
        if (ouvert && actif >= 0) {
            document.getElementById(idOption(actif))?.scrollIntoView({ block: 'nearest' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ouvert, actif]);

    /* ------------------------------- actions ------------------------------ */

    const choisir = (option: T | null) => {
        if (option !== null) setMemorise({ id: option.id, name: option.name, code: option.code });
        fermer();
        onChange(option?.id ?? null, option);
    };

    const effacer = () => {
        fermer();
        onChange(null, null);
        champ.current?.focus();
    };

    const auClavier = (e: KeyboardEvent<HTMLInputElement>) => {
        switch (e.key) {
            // Les flèches ne parcourent pas une liste périmée : l'arrivée des
            // bons résultats déplacerait l'option active sous les doigts.
            case 'ArrowDown':
                e.preventDefault();
                if (!ouvert) return ouvrir();
                if (listeFiable) setActif((i) => Math.min(i + 1, options.length - 1));
                return;
            case 'ArrowUp':
                e.preventDefault();
                if (!ouvert) return ouvrir();
                if (listeFiable) setActif((i) => Math.max(i - 1, 0));
                return;
            case 'Enter':
                // Liste fermée : le formulaire garde sa soumission implicite,
                // sauf pour un sélecteur qui remplace un <select> (voir
                // `entreeOuvre`) — Entrée y rouvre la liste, rien ne part.
                if (!ouvert) {
                    if (entreeOuvre) {
                        e.preventDefault();
                        ouvrir();
                    }
                    return;
                }
                // Liste ouverte, Entrée CHOISIT : elle ne doit jamais soumettre
                // le formulaire autour pendant qu'on cherche un nom.
                e.preventDefault();
                if (!listeFiable) {
                    // Résultats pas encore arrivés pour ce texte : le choix
                    // attend (voir l'effet de l'option active).
                    if (saisieNette !== '') choixEnAttente.current = true;
                    return;
                }
                if (actif >= 0 && actif < options.length) choisir(options[actif]);
                return;
        }
    };

    // Échap et la sortie du composant se gèrent au niveau du CONTENEUR : le
    // bouton ✕ est focusable. Posés sur le seul champ, un Tab qui passe par ✕
    // laissait la liste ouverte par-dessus la page et le champ affichant la
    // frappe (« ben ») alors que la valeur restait l'ancien choix.
    const echapConteneur = (e: KeyboardEvent<HTMLDivElement>) => {
        if (e.key !== 'Escape' || !ouvert) return;
        // Échap referme la liste, et seulement elle : sans l'arrêt de la
        // propagation, il fermerait aussi la fenêtre qui la contient.
        e.preventDefault();
        e.stopPropagation();
        fermer();
    };

    /* ------------------------------- messages ----------------------------- */

    let message: { texte: string; erreur?: boolean } | null = null;
    if (erreur) {
        message = { texte: t('Recherche impossible : vérifiez la connexion, puis retapez.'), erreur: true };
    } else if (!listeFiable) {
        // Le compte ou le « aucun résultat » d'une AUTRE recherche serait faux.
        message = { texte: t('Recherche…') };
    } else if (resultats.length === 0) {
        message = { texte: saisieNette !== '' ? textes.aucunResultat(saisieNette) : textes.aucunAProposer };
    } else if (total > resultats.length) {
        message = { texte: textes.tropDeResultats(total) };
    }

    // Hors connexion, dire d'où vient la liste : les éléments créés depuis le
    // dernier chargement de la mémoire locale n'y sont pas.
    const avisRepli = enRepli ? (textes.repli?.(repliLocal?.length ?? 0) ?? null) : null;

    /* -------------------------------- rendu ------------------------------- */

    const hauteur = classesHauteur ?? (compact ? 'py-1.5' : 'py-2');
    const s = sombre
        ? {
              champ: `h-9 w-full min-w-0 rounded-lg border py-1.5 ps-3 pe-9 text-xs font-semibold outline-none transition placeholder:font-normal placeholder:text-slate-400 focus:border-emerald-400/50 disabled:opacity-40 ${
                  value !== null
                      ? 'border-emerald-400/40 bg-emerald-400/10 text-emerald-300'
                      : 'border-white/10 bg-white/[0.05] text-slate-200'
              }`,
              icone: 'text-slate-400 hover:text-slate-200',
              popup: 'rounded-xl border border-white/10 bg-slate-900 shadow-2xl',
              option: 'text-slate-200',
              optionActive: 'bg-white/[0.08] text-white',
              secondaire: 'text-slate-500',
              coche: 'text-emerald-400',
              pied: 'border-white/[0.06]',
              piedTexte: 'text-slate-400',
              erreur: 'text-red-300',
          }
        : {
              champ: `w-full min-w-0 rounded-md border border-slate-300 bg-white ps-3 pe-9 text-sm text-slate-900 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 ${hauteur}`,
              icone: 'text-slate-400 hover:text-slate-600',
              popup: 'rounded-md border border-slate-200 bg-white shadow-lg',
              option: 'text-slate-700',
              optionActive: 'bg-emerald-50 text-emerald-900',
              secondaire: 'text-slate-400',
              coche: 'text-emerald-600',
              pied: 'border-slate-100',
              piedTexte: 'text-slate-500',
              erreur: 'text-red-600',
          };

    // Effacer ramène à « rien » : proposé quand ce n'est pas un choix
    // obligatoire, ou quand « aucun » est lui-même une option (client comptoir).
    const effacable = value !== null && !disabled && (!required || aucun !== undefined);

    return (
        <div
            ref={conteneur}
            className={`relative min-w-0 ${className}`}
            onKeyDown={echapConteneur}
            // `onBlur` de React remonte (focusout) : le focus qui passe du
            // champ au bouton ✕ reste dedans, celui qui sort ferme la liste.
            onBlur={(e) => {
                if (!conteneur.current?.contains(e.relatedTarget as Node | null)) fermer();
            }}
        >
            <input
                ref={champ}
                id={id}
                type="text"
                role="combobox"
                aria-expanded={ouvert}
                aria-controls={idListe}
                aria-autocomplete="list"
                aria-activedescendant={ouvert && actif >= 0 && actif < options.length ? idOption(actif) : undefined}
                aria-required={required || undefined}
                aria-label={ariaLabel}
                autoComplete="off"
                spellCheck={false}
                disabled={disabled}
                title={title}
                placeholder={chargeSelection ? t('Chargement…') : (placeholder ?? aucun ?? textes.placeholder)}
                value={saisie ?? texteSelection}
                onChange={(e) => {
                    let texte = e.target.value;
                    // Si la sélection du texte n'a pas tenu (certains
                    // navigateurs tactiles la perdent), la première lettre
                    // tapée s'ajoute au libellé affiché : on ne cherche alors
                    // que ce qui a été TAPÉ, pas « Atlas SARL · C-0012a ».
                    if (saisie === null && texteSelection !== '' && texte.startsWith(texteSelection)) {
                        texte = texte.slice(texteSelection.length);
                    }
                    // L'option active suit la liste (voir son effet) : la
                    // remettre à zéro ici laisserait Entrée sans effet après
                    // une espace ajoutée en fin de texte, qui ne change rien
                    // à la recherche. Un choix différé, lui, est abandonné :
                    // on tape autre chose que ce qu'on a validé.
                    choixEnAttente.current = false;
                    setSaisie(texte);
                    ouvrir();
                }}
                onClick={ouvrir}
                // Tout le libellé est sélectionné à l'entrée dans le champ : la
                // première frappe le remplace, on ne cherche pas à sa suite.
                onFocus={(e) => {
                    e.target.select();
                    vientDeFocaliser.current = true;
                }}
                // Le relâchement du clic qui a donné le focus replacerait le
                // curseur et perdrait la sélection : on l'annule, une fois.
                onMouseUp={(e) => {
                    if (vientDeFocaliser.current) e.preventDefault();
                    vientDeFocaliser.current = false;
                }}
                onBlur={() => {
                    vientDeFocaliser.current = false;
                }}
                onKeyDown={auClavier}
                className={s.champ}
            />

            {effacable ? (
                <button
                    type="button"
                    onClick={effacer}
                    // Hors tabulation quand l'option « aucun » efface déjà au
                    // clavier : sinon Tab puis Entrée « pour valider » retirait
                    // l'article en silence (voir `effacerTabulable`).
                    tabIndex={effacerTabulable ? undefined : -1}
                    aria-label={t('Effacer le choix')}
                    title={t('Effacer le choix')}
                    className={`absolute inset-y-0 end-0 flex w-9 items-center justify-center text-sm transition ${s.icone}`}
                >
                    <span aria-hidden="true">✕</span>
                </button>
            ) : (
                <span
                    aria-hidden="true"
                    className={`pointer-events-none absolute inset-y-0 end-0 flex w-9 items-center justify-center text-xs ${s.secondaire}`}
                >
                    ▾
                </span>
            )}

            {/* Toujours présente dans le DOM, masquée fermée : `aria-controls`
                doit désigner un élément qui existe. */}
            {/* Un clic n'importe où dans la liste — option, pied, bordure — ne
                doit pas retirer le focus du champ : sinon elle se refermerait
                avant d'avoir reçu le choix, ou effacerait la recherche tapée. */}
            <div
                hidden={!ouvert}
                onMouseDown={(e) => e.preventDefault()}
                className={`absolute start-0 z-30 w-full ${largeurListe} max-w-[calc(100vw-2rem)] ${
                    placement.versLeHaut ? 'bottom-full mb-1' : 'top-full mt-1'
                } ${s.popup}`}
            >
                <ul
                    id={idListe}
                    role="listbox"
                    aria-label={ariaLabel ?? textes.nomListe}
                    style={{ maxHeight: placement.hauteur }}
                    // Grisée tant qu'elle répond à un autre texte que celui tapé.
                    className={`overflow-y-auto py-1 transition-opacity ${listeFiable ? '' : 'opacity-60'}`}
                >
                    {ouvert &&
                        options.map((option, index) => {
                            const choisi = (option?.id ?? null) === value;

                            return (
                                <li
                                    key={option?.id ?? 'aucun'}
                                    id={idOption(index)}
                                    role="option"
                                    aria-selected={index === actif}
                                    // Le clic choisit ce qu'on voit sous le doigt,
                                    // même grisé ; le survol n'active rien dans une
                                    // liste périmée (Entrée y attend la bonne).
                                    onClick={() => choisir(option)}
                                    onMouseMove={() => listeFiable && index !== actif && setActif(index)}
                                    className={`flex min-h-10 cursor-pointer items-center gap-2 px-3 py-2 text-sm ${
                                        index === actif ? s.optionActive : s.option
                                    }`}
                                >
                                    <span aria-hidden="true" className={`w-4 shrink-0 text-center ${s.coche}`}>
                                        {choisi ? '✓' : ''}
                                    </span>
                                    {option === null ? (
                                        <span className="min-w-0 flex-1 truncate">{aucun}</span>
                                    ) : (
                                        rendreOption(option, { secondaire: s.secondaire })
                                    )}
                                </li>
                            );
                        })}
                </ul>

                {ouvert && (avisRepli !== null || message !== null) && (
                    <div className={`space-y-1 border-t px-3 py-2 text-xs ${s.pied}`}>
                        {avisRepli !== null && <p className={s.piedTexte}>{avisRepli}</p>}
                        {message !== null && (
                            <p className={message.erreur ? s.erreur : s.piedTexte}>{message.texte}</p>
                        )}
                    </div>
                )}
            </div>

            {/* Ce que voit l'œil (compte, « aucun résultat », panne), dit à
                l'oreille : les options ne prennent jamais le focus. Rien tant
                que la liste répond à un autre texte, ni pendant la requête
                (sauf en repli, dont la liste est déjà la bonne). */}
            <div role="status" aria-live="polite" className="sr-only">
                {ouvert && listeFiable && (enRepli || !recherche.isFetching)
                    ? [avisRepli, message?.texte ?? textes.annonce(resultats.length)].filter(Boolean).join(' ')
                    : ''}
            </div>
        </div>
    );
}
