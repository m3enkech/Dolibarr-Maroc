import {
    useEffect,
    useId,
    useLayoutEffect,
    useRef,
    useState,
    type FocusEvent,
    type KeyboardEvent,
    type MouseEvent,
    type ReactNode,
} from 'react';
import { flushSync } from 'react-dom';
import { Link, type To } from 'react-router-dom';

/**
 * Un élément de menu : une ACTION (rappel), un LIEN (navigation — clic du
 * milieu et « ouvrir dans un onglet » restent possibles) ou un séparateur.
 * `desactive` : visible mais inactif, et sauté par les flèches. `danger` :
 * en rouge — ce qui détruit ne doit pas ressembler à ce qui crée.
 */
export type ElementMenu =
    | { genre: 'action'; cle: string; libelle: ReactNode; onChoisir: () => void; desactive?: boolean; danger?: boolean }
    | { genre: 'lien'; cle: string; libelle: ReactNode; vers: To; desactive?: boolean; danger?: boolean }
    | { genre: 'separateur'; cle: string };

type Choix = Exclude<ElementMenu, { genre: 'separateur' }>;

/** Écart entre le bouton et le menu, et marge gardée au bord de la zone visible. */
const MARGE = 8;
/** En deçà, un menu devient illisible : on le laisse défiler plutôt que de l'écraser. */
const HAUTEUR_MIN = 120;

/** Le rectangle réellement visible : la fenêtre, rognée par chaque ancêtre qui coupe ce qui déborde. */
type Bornes = { haut: number; bas: number; gauche: number; droite: number };

/**
 * Les ancêtres qui ROGNENT leur contenu. Au bureau, la fiche tiers défile dans
 * sa propre colonne (`overflow-y-auto`) : la fenêtre seule ne suffit pas à dire
 * où le menu sera coupé — un menu ouvert vers le bas au pied de la colonne
 * aurait disparu sous elle, la fenêtre ayant encore de la place.
 */
function ancetresQuiRognent(element: HTMLElement): HTMLElement[] {
    const rognent: HTMLElement[] = [];

    for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
        const style = getComputedStyle(parent);
        if (style.overflowX !== 'visible' || style.overflowY !== 'visible') rognent.push(parent);
    }

    return rognent;
}

function bornesVisibles(rognent: HTMLElement[]): Bornes {
    // La fenêtre VISIBLE : sur téléphone, le clavier virtuel ne réduit
    // qu'elle — même raison que dans ComboboxRecherche.
    const vue = window.visualViewport;
    const bornes: Bornes = vue
        ? { haut: vue.offsetTop, bas: vue.offsetTop + vue.height, gauche: vue.offsetLeft, droite: vue.offsetLeft + vue.width }
        : { haut: 0, bas: window.innerHeight, gauche: 0, droite: window.innerWidth };

    for (const parent of rognent) {
        const cadre = parent.getBoundingClientRect();
        bornes.haut = Math.max(bornes.haut, cadre.top);
        bornes.bas = Math.min(bornes.bas, cadre.bottom);
        bornes.gauche = Math.max(bornes.gauche, cadre.left);
        bornes.droite = Math.min(bornes.droite, cadre.right);
    }

    return bornes;
}

/**
 * Retire les séparateurs en tête, en queue et en double : l'appelant compose
 * sa liste par morceaux conditionnels (« Convertir » n'existe que pour un
 * prospect), et ne devrait pas avoir à recompter qui est le premier.
 */
function nettoyer(elements: ElementMenu[]): ElementMenu[] {
    const nets: ElementMenu[] = [];

    for (const element of elements) {
        if (element.genre === 'separateur' && (nets.length === 0 || nets[nets.length - 1].genre === 'separateur')) continue;
        nets.push(element);
    }

    while (nets.length > 0 && nets[nets.length - 1].genre === 'separateur') nets.pop();

    return nets;
}

/**
 * Bouton qui ouvre une liste d'actions — « Nouvelle transaction ▾ », « Plus ▾ ».
 *
 * Écrit à la main, comme ComboboxRecherche : pas de bibliothèque de
 * composants dans le projet, et une seule dépendance pour un menu ne se
 * justifie pas. Ce qu'il doit, et qu'un <details> ou un simple bouton ne font
 * pas, suit le motif « menu button » de l'ARIA :
 *
 * - CLAVIER. Entrée, Espace ou ↓ ouvrent sur le premier élément, ↑ sur le
 *   dernier ; ↑ ↓ Début Fin parcourent, en boucle ; une lettre saute au
 *   prochain élément qui commence par elle ; Échap ferme et rend le focus au
 *   bouton ; Tab ferme et laisse le focus filer à la suite, comme depuis le
 *   bouton. Sans quoi le menu ne s'utilise qu'à la souris.
 * - FERMETURE au clic ou au toucher dehors, et quand le focus part ailleurs.
 * - PLACEMENT. Calé sur le bord de FIN du bouton (`end-0`, donc à gauche en
 *   arabe sans rien inverser à la main) ; ouvert VERS LE HAUT quand la place
 *   manque dessous et qu'il y en a plus dessus — mesuré avant affichage, sinon
 *   il apparaîtrait en bas une image puis sauterait ; et rabattu sur le bord
 *   de DÉBUT s'il sortait de l'écran par l'autre côté (bouton en début de
 *   ligne sur téléphone).
 * - CIBLES TACTILES de 40 px, la hauteur que la feuille de style impose déjà
 *   aux boutons sous `pointer: coarse`.
 *
 * Un menu SANS ÉLÉMENT n'est pas rendu : un bouton qui n'ouvre rien n'est
 * qu'une promesse vide. Un menu dont tous les éléments sont GRISÉS — le temps
 * d'une écriture, typiquement — garde au contraire son bouton, inactif
 * (`aria-disabled`, pas `disabled`, qui lui ôterait le focus) : le retirer du
 * DOM faisait tomber sur <body> le focus qu'on venait de lui rendre, Tab
 * repartait du haut de la page, et le bouton clignotait dans sa rangée.
 */
export default function MenuDeroulant({
    libelle,
    etiquette,
    elements,
    classeBouton = 'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50',
}: {
    /** Contenu visible du bouton. */
    libelle: ReactNode;
    /** Nom accessible, quand le contenu visible est abrégé (« + » sur téléphone). */
    etiquette?: string;
    elements: ElementMenu[];
    classeBouton?: string;
}) {
    const idBouton = useId();
    const idMenu = useId();
    const conteneur = useRef<HTMLDivElement>(null);
    const bouton = useRef<HTMLButtonElement>(null);
    const menu = useRef<HTMLDivElement>(null);

    const [ouvert, setOuvert] = useState(false);
    // Élément à focaliser à l'ouverture : le premier (Entrée, ↓, clic) ou le dernier (↑).
    const [cible, setCible] = useState<'premier' | 'dernier'>('premier');
    const [placement, setPlacement] = useState<{ versLeHaut: boolean; calage: 'fin' | 'debut'; hauteurMax?: number }>({
        versLeHaut: false,
        calage: 'fin',
    });

    const nets = nettoyer(elements);
    const choix = nets.filter((e): e is Choix => e.genre !== 'separateur');
    const inactif = choix.every((e) => e.desactive);

    const ouvrir = (depuis: 'premier' | 'dernier') => {
        setCible(depuis);
        setOuvert(true);
    };

    const fermer = (rendreFocus: boolean) => {
        setOuvert(false);
        if (rendreFocus) bouton.current?.focus();
    };

    /** Les éléments que le clavier atteint, dans l'ordre affiché. */
    const atteignables = () =>
        [...(menu.current?.querySelectorAll<HTMLElement>('[role="menuitem"]:not([aria-disabled="true"])') ?? [])];

    // Clic ou toucher hors du composant : on referme sans reprendre le focus,
    // qui doit rester là où l'on vient de cliquer.
    useEffect(() => {
        if (!ouvert) return;

        const dehors = (e: PointerEvent) => {
            if (!conteneur.current?.contains(e.target as Node)) setOuvert(false);
        };
        document.addEventListener('pointerdown', dehors);

        return () => document.removeEventListener('pointerdown', dehors);
    }, [ouvert]);

    // Sens d'ouverture, calage et hauteur, mesurés AVANT l'affichage, puis à
    // chaque défilement (capturé : la colonne de la fiche défile seule, sans
    // que la fenêtre le sache) et redimensionnement, clavier virtuel compris.
    useLayoutEffect(() => {
        if (!ouvert || !conteneur.current) return;

        const rognent = ancetresQuiRognent(conteneur.current);
        const rtl = getComputedStyle(conteneur.current).direction === 'rtl';
        const vue = window.visualViewport;

        const mesurer = () => {
            const cadre = bouton.current?.getBoundingClientRect();
            const liste = menu.current;
            if (!cadre || !liste) return;

            const bornes = bornesVisibles(rognent);
            // `scrollHeight` : la hauteur NATURELLE, même quand une mesure
            // précédente a déjà bridé la liste.
            const hauteur = liste.scrollHeight;
            const dessous = bornes.bas - cadre.bottom - MARGE;
            const dessus = cadre.top - bornes.haut - MARGE;
            const versLeHaut = dessous < hauteur && dessus > dessous;
            const place = versLeHaut ? dessus : dessous;
            const hauteurMax = place < hauteur ? Math.max(HAUTEUR_MIN, Math.floor(place)) : undefined;

            // Calé sur la fin, le menu s'étend vers le DÉBUT : à gauche en
            // français, à droite en arabe. S'il en sort, on le cale sur le début.
            const largeur = liste.offsetWidth;
            const sortirait = rtl ? cadre.left + largeur > bornes.droite : cadre.right - largeur < bornes.gauche;
            const calage = sortirait ? 'debut' : 'fin';

            // Appelée à chaque pas de défilement : on ne redessine que si
            // quelque chose change vraiment.
            setPlacement((avant) =>
                avant.versLeHaut === versLeHaut && avant.calage === calage && avant.hauteurMax === hauteurMax
                    ? avant
                    : { versLeHaut, calage, hauteurMax },
            );
        };

        mesurer();
        window.addEventListener('resize', mesurer);
        window.addEventListener('scroll', mesurer, { capture: true, passive: true });
        vue?.addEventListener('resize', mesurer);
        vue?.addEventListener('scroll', mesurer);

        return () => {
            window.removeEventListener('resize', mesurer);
            window.removeEventListener('scroll', mesurer, { capture: true });
            vue?.removeEventListener('resize', mesurer);
            vue?.removeEventListener('scroll', mesurer);
        };
    }, [ouvert]);

    // Focus posé à l'ouverture, SANS défilement : le placement vient d'être
    // choisi pour que le menu tienne dans la zone visible ; laisser le
    // navigateur « amener l'élément en vue » ferait sauter la colonne.
    useEffect(() => {
        if (!ouvert) return;

        const liste = atteignables();
        (cible === 'dernier' ? liste[liste.length - 1] : liste[0])?.focus({ preventScroll: true });
    }, [ouvert, cible]);

    if (choix.length === 0) return null;

    const auClavierBouton = (e: KeyboardEvent<HTMLButtonElement>) => {
        // Entrée et Espace passent par le clic natif du bouton : les traiter
        // ici aussi ouvrirait puis refermerait aussitôt.
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!inactif) ouvrir(e.key === 'ArrowDown' ? 'premier' : 'dernier');
        } else if (e.key === 'Escape' && ouvert) {
            // Le focus peut être resté sur le bouton (Safari ne le donne pas
            // au clic) : Échap doit fermer d'ici aussi.
            e.preventDefault();
            e.stopPropagation();
            fermer(false);
        }
    };

    const auClavierMenu = (e: KeyboardEvent<HTMLDivElement>) => {
        const liste = atteignables();
        const position = liste.indexOf(document.activeElement as HTMLElement);
        const aller = (index: number) => liste[(index + liste.length) % liste.length]?.focus();

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                aller(position + 1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                aller(position < 0 ? liste.length - 1 : position - 1);
                break;
            case 'Home':
            case 'PageUp':
                e.preventDefault();
                aller(0);
                break;
            case 'End':
            case 'PageDown':
                e.preventDefault();
                aller(liste.length - 1);
                break;
            case 'Escape':
                // Arrêté ICI : une surcouche ouverte sous le menu écoute Échap
                // sur le document, et se fermerait avec lui.
                e.preventDefault();
                e.stopPropagation();
                fermer(true);
                break;
            case 'Tab':
                // Le focus revient d'abord au bouton, puis Tab suit son cours
                // (pas de preventDefault) : il file à l'élément suivant — ou
                // précédent, avec Maj — exactement comme depuis le bouton.
                // Laissé sur l'élément du menu, qui disparaît, le navigateur
                // pourrait repartir du haut de la page.
                bouton.current?.focus({ preventScroll: true });
                setOuvert(false);
                break;
            case ' ':
                // Un lien ne réagit pas à Espace, contrairement à un bouton.
                if (document.activeElement instanceof HTMLAnchorElement) {
                    e.preventDefault();
                    document.activeElement.click();
                }
                break;
            default:
                // Saut à l'initiale : « S » mène à « Supprimer » sans quatre ↓.
                if (e.key.length === 1 && e.key.trim() !== '' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                    const lettre = e.key.toLocaleLowerCase();
                    const suivants = [...liste.slice(position + 1), ...liste.slice(0, position + 1)];
                    suivants.find((el) => (el.textContent ?? '').trim().toLocaleLowerCase().startsWith(lettre))?.focus();
                }
        }
    };

    // Le focus sort du composant (Tab, clic sur un autre champ) : on ferme.
    // `relatedTarget` nul — fenêtre quittée, clic sur du vide — ne ferme pas :
    // le clic dehors s'en charge, et changer d'application ne doit pas
    // défaire un menu qu'on était en train de lire.
    const surSortie = (e: FocusEvent<HTMLDivElement>) => {
        if (e.relatedTarget instanceof Node && !conteneur.current?.contains(e.relatedTarget)) setOuvert(false);
    };

    const choisir = (element: Choix) => {
        if (element.genre === 'lien') {
            // La navigation suit son cours ; avec Ctrl ou le clic du milieu,
            // on reste sur la page, et le focus revient au bouton.
            fermer(true);

            return;
        }

        // Fermé et rendu AVANT l'action : une confirmation (« Supprimer ? »)
        // bloque la page, et le menu serait resté ouvert derrière elle. Le
        // focus revient au bouton, où la boîte de dialogue le rendra.
        flushSync(() => setOuvert(false));
        bouton.current?.focus();
        element.onChoisir();
    };

    // `outline-hidden` et non `outline-none` : le contour reste transparent à
    // l'écran, mais REDEVIENT visible en contraste élevé (couleurs forcées),
    // où le fond coloré du focus est effacé — sans lui, rien n'y disait
    // quelle action Entrée allait lancer.
    const classesElement = (element: Choix) =>
        `flex min-h-10 w-full items-center whitespace-nowrap px-3 py-2 text-start text-sm focus:outline-hidden ${
            element.desactive
                ? 'cursor-not-allowed text-slate-400'
                : element.danger
                  ? 'text-red-600 hover:bg-red-50 focus:bg-red-50 focus:text-red-700'
                  : 'text-slate-700 hover:bg-slate-50 focus:bg-emerald-50 focus:text-emerald-800'
        }`;

    return (
        <div ref={conteneur} className="relative inline-flex" onBlur={surSortie}>
            <button
                ref={bouton}
                id={idBouton}
                type="button"
                aria-haspopup="menu"
                aria-expanded={ouvert}
                aria-controls={ouvert ? idMenu : undefined}
                aria-label={etiquette}
                // Tout grisé : le bouton reste là, focalisable, mais n'ouvre rien.
                aria-disabled={inactif || undefined}
                onClick={() => (ouvert ? fermer(false) : !inactif && ouvrir('premier'))}
                onKeyDown={auClavierBouton}
                className={`inline-flex items-center gap-1.5 ${classeBouton} aria-disabled:cursor-not-allowed aria-disabled:opacity-60`}
            >
                {libelle}
                <svg
                    aria-hidden
                    viewBox="0 0 20 20"
                    fill="currentColor"
                    className={`size-4 shrink-0 opacity-70 transition-transform ${ouvert ? 'rotate-180' : ''}`}
                >
                    <path
                        fillRule="evenodd"
                        d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z"
                        clipRule="evenodd"
                    />
                </svg>
            </button>

            {/* `onMouseDown` empêché, comme dans ComboboxRecherche : un clic sur
                ce qui ne prend pas le focus (séparateur, marge, bordure)
                l'envoyait sur <body>, hors du menu resté ouvert — Échap et les
                flèches n'y répondaient plus. Le focus reste sur l'élément
                survolé, et le clic sur un élément part quand même. */}
            {ouvert && (
                <div
                    ref={menu}
                    id={idMenu}
                    role="menu"
                    aria-labelledby={idBouton}
                    aria-orientation="vertical"
                    onKeyDown={auClavierMenu}
                    onMouseDown={(e) => e.preventDefault()}
                    style={placement.hauteurMax !== undefined ? { maxHeight: placement.hauteurMax } : undefined}
                    className={`absolute z-30 min-w-48 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg ${
                        placement.versLeHaut ? 'bottom-full mb-1' : 'top-full mt-1'
                    } ${placement.calage === 'fin' ? 'end-0' : 'start-0'}`}
                >
                    {nets.map((element) => {
                        if (element.genre === 'separateur') {
                            return <div key={element.cle} role="separator" className="my-1 border-t border-slate-100" />;
                        }

                        // Le survol déplace le focus : clavier et souris montrent
                        // alors UN seul élément actif, jamais deux.
                        const survol = element.desactive
                            ? undefined
                            : (e: MouseEvent<HTMLElement>) => {
                                  if (document.activeElement !== e.currentTarget) e.currentTarget.focus({ preventScroll: true });
                              };

                        if (element.desactive) {
                            return (
                                <div key={element.cle} role="menuitem" aria-disabled="true" tabIndex={-1} className={classesElement(element)}>
                                    {element.libelle}
                                </div>
                            );
                        }

                        return element.genre === 'lien' ? (
                            <Link
                                key={element.cle}
                                to={element.vers}
                                role="menuitem"
                                tabIndex={-1}
                                onClick={() => choisir(element)}
                                onMouseMove={survol}
                                className={classesElement(element)}
                            >
                                {element.libelle}
                            </Link>
                        ) : (
                            <button
                                key={element.cle}
                                type="button"
                                role="menuitem"
                                tabIndex={-1}
                                onClick={() => choisir(element)}
                                onMouseMove={survol}
                                className={classesElement(element)}
                            >
                                {element.libelle}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
