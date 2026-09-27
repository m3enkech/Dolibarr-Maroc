import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { keepPreviousData, useQuery, type QueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { api } from '@/lib/api';
import { interpoler, useT } from '@/lib/langue';
import { useDebounce } from '@/lib/useDebounce';
import type { Paginated, Tiers } from '@/types';

/** Filtre de rôle compris par GET /tiers (`type`) — voir TiersController::index. */
export type TypeTiers = 'client' | 'fournisseur' | 'prospect';

/** Le strict nécessaire pour AFFICHER un tiers choisi, quand l'appelant le connaît déjà. */
export interface TiersChoisi {
    id: number;
    name: string;
    code?: string | null;
}

/**
 * Nombre de propositions. De quoi reconnaître un nom dans la liste, pas de quoi
 * la faire défiler : au-delà, taper deux lettres de plus va plus vite.
 */
const TAILLE_PAGE = 20;

/** Hauteur maximale de la liste (l'ancien `max-h-72`), en pixels. */
const HAUTEUR_LISTE = 288;
/** Ce que la liste ne peut pas occuper : l'écart au champ, le pied de liste, une marge. */
const RESERVE_POPUP = 48;
/** En deçà, une liste devient illisible : on la laisse dépasser plutôt. */
const HAUTEUR_LISTE_MIN = 120;

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

/**
 * Filtre local, calqué sur le filtre serveur (nom, code ou ICE contient le
 * texte, sans égard à la casse). Sert au repli hors connexion uniquement.
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

/** Nom, puis code : le nom se lit, le code départage deux homonymes. */
const libelleDe = (tiers: TiersChoisi): string => (tiers.code ? `${tiers.name} · ${tiers.code}` : tiers.name);

const estIntrouvable = (erreur: unknown): boolean => isAxiosError(erreur) && erreur.response?.status === 404;

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
 * à la fois. Le champ suit le motif « combobox » de l'ARIA : le focus reste
 * dans le champ, l'option active est désignée par `aria-activedescendant`,
 * flèches pour parcourir, Entrée pour choisir, Échap pour refermer.
 *
 * Le tiers choisi reste affiché même quand il n'est pas dans la page de
 * résultats courante : on le mémorise au moment du choix, et une valeur venue
 * d'ailleurs (pré-remplissage, modification) est relue par GET /tiers/{id}.
 */
export default function SelecteurTiers({
    value,
    onChange,
    type,
    id,
    required = false,
    disabled = false,
    placeholder,
    aucun,
    tiersConnu = null,
    repliLocal,
    className = '',
    compact = false,
    sombre = false,
    traduire = true,
    title,
    'aria-label': ariaLabel,
}: Props) {
    const tLangue = useT();
    const t = traduire ? tLangue : interpoler;

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
    // `null` = on n'édite pas : le champ montre le tiers choisi. Une chaîne =
    // ce que l'utilisateur est en train de taper.
    const [saisie, setSaisie] = useState<string | null>(null);
    const [actif, setActif] = useState(-1);
    const [memorise, setMemorise] = useState<TiersChoisi | null>(null);
    // Vers le haut quand la place manque en dessous : la caisse sur téléphone
    // coupe tout ce qui dépasse du bas de l'écran (racine en overflow-hidden).
    const [placement, setPlacement] = useState({ versLeHaut: false, hauteur: HAUTEUR_LISTE });

    const saisieNette = (saisie ?? '').trim();
    // On retarde le TERME envoyé au serveur, jamais le champ, qui doit rester
    // instantané sous les doigts.
    const terme = useDebounce(saisieNette, 250);

    const recherche = useQuery({
        ...optionsRechercheTiers(type, terme),
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
        (erreurReseau || (horsConnexion && recherche.isFetching));

    // Mémoïsé : l'effet de l'option active en dépend, un tableau neuf à chaque
    // rendu le relancerait sans fin et rendrait les flèches inopérantes.
    const repliFiltre = useMemo(
        () => (enRepli ? filtrerLocal(repliLocal ?? [], saisieNette) : []),
        [enRepli, repliLocal, saisieNette],
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
     * aurait pris le premier tiers alphabétique de la liste d'ouverture.
     * Le repli local filtre, lui, sur le texte tapé : il est toujours à jour.
     * En erreur, seule reste l'option « aucun », qui ne dépend de rien.
     */
    const listeFiable =
        enRepli ||
        erreur ||
        (recherche.data !== undefined && !recherche.isPlaceholderData && terme === saisieNette);

    // `null` en tête = l'option « aucun tiers », quand l'appelant en propose une.
    const options: (Tiers | null)[] = aucun !== undefined ? [null, ...resultats] : resultats;
    // Ce dont dépend la liste affichée, pour relancer l'effet de l'option active.
    const sourceListe = enRepli ? repliFiltre : erreur ? null : recherche.data;

    /* ---------------------------- tiers choisi ---------------------------- */

    const connuLocal = [memorise, tiersConnu].find((x) => x != null && x.id === value) ?? null;

    const fiche = useQuery({
        queryKey: ['selecteur-tiers-fiche', value],
        queryFn: async () => (await api.get<{ data: Tiers }>(`/tiers/${value}`)).data.data,
        enabled: value !== null && connuLocal === null,
        retry: false,
        staleTime: 60_000,
        // Même raison que pour la recherche : en pause, le champ resterait vide
        // sans rien dire ; en échec, il affiche au moins « Tiers n° 12 ».
        networkMode: 'always',
    });

    const selection: TiersChoisi | null =
        value === null ? null : (connuLocal ?? resultats.find((r) => r.id === value) ?? fiche.data ?? null);

    let texteSelection = '';
    if (value !== null && selection !== null) {
        texteSelection = libelleDe(selection);
    } else if (value !== null && fiche.isError) {
        // Un id qui n'existe pas (ou plus) doit se VOIR : l'afficher comme un
        // choix valide laisserait partir un document vers un refus serveur.
        texteSelection = estIntrouvable(fiche.error)
            ? t('Tiers introuvable (n° {id})', { id: value })
            : t('Tiers n° {id}', { id: value });
    }
    const chargeSelection = value !== null && selection === null && fiche.isFetching;

    /* ------------------------------ validation ---------------------------- */

    // Pas d'attribut `required` natif : le texte affiché n'est pas la valeur.
    // Un champ rempli de lettres tapées sans rien choisir passerait, et un
    // libellé encore en chargement (champ vide) bloquerait un choix valide.
    // La validité personnalisée porte, elle, sur la VALEUR.
    const messageRequis = t('Choisissez un tiers dans la liste.');
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
    useLayoutEffect(() => {
        if (!ouvert) return;

        const mesurer = () => {
            const cadre = champ.current?.getBoundingClientRect();
            if (!cadre) return;

            const dessous = window.innerHeight - cadre.bottom;
            const dessus = cadre.top;
            const versLeHaut = dessous < HAUTEUR_LISTE + RESERVE_POPUP && dessus > dessous;
            const place = (versLeHaut ? dessus : dessous) - RESERVE_POPUP;

            setPlacement({
                versLeHaut,
                hauteur: Math.max(HAUTEUR_LISTE_MIN, Math.min(HAUTEUR_LISTE, place)),
            });
        };

        mesurer();
        window.addEventListener('resize', mesurer);

        return () => window.removeEventListener('resize', mesurer);
    }, [ouvert]);

    // Option active, recalculée quand la liste change. Tant qu'elle ne répond
    // pas au texte tapé, AUCUNE (voir `listeFiable`). Sinon : la première si
    // l'on a tapé quelque chose (Entrée prend alors le meilleur candidat), ou
    // le tiers déjà choisi, pour que les flèches repartent de lui.
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

    const choisir = (option: Tiers | null) => {
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
                // Liste ouverte, Entrée CHOISIT : elle ne doit jamais soumettre
                // le formulaire autour pendant qu'on cherche un nom.
                if (!ouvert) return;
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
    // frappe (« ben ») alors que la valeur restait l'ancien tiers.
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
        message = {
            texte:
                saisieNette !== ''
                    ? t('Aucun tiers ne correspond à « {terme} ».', { terme: saisieNette })
                    : t('Aucun tiers à proposer.'),
        };
    } else if (total > resultats.length) {
        message = { texte: t('{total} tiers trouvés : précisez la recherche pour voir les autres.', { total }) };
    }

    // Hors connexion, dire d'où vient la liste : les tiers créés depuis le
    // dernier chargement de l'annuaire n'y sont pas.
    const avisRepli = enRepli
        ? t('Hors ligne : recherche parmi les {n} tiers gardés sur ce poste.', { n: repliLocal?.length ?? 0 })
        : null;

    const roleDe = (tiers: Tiers): string | null =>
        tiers.is_prospect ? t('Prospect') : tiers.is_client ? t('Client') : tiers.is_supplier ? t('Fournisseur') : null;

    /* -------------------------------- rendu ------------------------------- */

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
              champ: `w-full min-w-0 rounded-md border border-slate-300 bg-white ps-3 pe-9 text-sm text-slate-900 placeholder:text-slate-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500 ${
                  compact ? 'py-1.5' : 'py-2'
              }`,
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

    // Effacer ramène à « aucun tiers » : proposé quand ce n'est pas un choix
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
                placeholder={
                    chargeSelection
                        ? t('Chargement…')
                        : (placeholder ?? aucun ?? t('Rechercher un tiers (nom, code, ICE)…'))
                }
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
                className={`absolute start-0 z-30 w-full min-w-64 max-w-[calc(100vw-2rem)] ${
                    placement.versLeHaut ? 'bottom-full mb-1' : 'top-full mt-1'
                } ${s.popup}`}
            >
                <ul
                    id={idListe}
                    role="listbox"
                    aria-label={ariaLabel ?? t('Tiers proposés')}
                    style={{ maxHeight: placement.hauteur }}
                    // Grisée tant qu'elle répond à un autre texte que celui tapé.
                    className={`overflow-y-auto py-1 transition-opacity ${listeFiable ? '' : 'opacity-60'}`}
                >
                    {ouvert &&
                        options.map((option, index) => {
                            const choisi = (option?.id ?? null) === value;
                            const role = option !== null && type === undefined ? roleDe(option) : null;

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
                                        <>
                                            {/* `dir="auto"` : un nom arabe dans une page
                                                française (et l'inverse) garde son sens
                                                de lecture. */}
                                            <span dir="auto" className="min-w-0 flex-1 truncate font-medium">
                                                {option.name}
                                            </span>
                                            {!option.is_active && (
                                                <span className={`shrink-0 text-xs ${s.secondaire}`}>{t('Inactif')}</span>
                                            )}
                                            {role !== null && (
                                                <span className={`shrink-0 text-xs ${s.secondaire}`}>{role}</span>
                                            )}
                                            <span className={`shrink-0 font-mono text-xs ${s.secondaire}`}>
                                                {option.code}
                                            </span>
                                        </>
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
                    ? [avisRepli, message?.texte ?? t('{n} tiers proposés.', { n: resultats.length })]
                          .filter(Boolean)
                          .join(' ')
                    : ''}
            </div>
        </div>
    );
}
