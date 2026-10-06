import { useEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

/** Ce que Tab peut atteindre dans le panneau — de quoi boucler le focus dessus. */
const FOCALISABLES =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Un panneau posé PAR-DESSUS l'écran, avec son voile.
 *
 * Même patron que le détail par dépôt de l'écran de suivi — voile qui ferme au
 * geste, feuille remontée du bas sur téléphone — mais au bureau aussi : la
 * fiche tiers occupe déjà la colonne de droite à côté de la liste, une
 * troisième colonne n'y laisserait plus rien de lisible.
 *
 * Ce qu'une surcouche doit au clavier, et qu'un simple <aside> ne fait pas :
 * Échap ferme ; Tab tourne DANS le panneau au lieu de filer vers la page
 * masquée ; et à la fermeture le focus revient sur ce qui l'a ouverte — sans
 * quoi il retombe sur <body> et l'utilisateur repart du haut de la page.
 *
 * Rendue dans <body> par un portail : la fiche vit dans une colonne qui défile,
 * et un ancêtre à opacité ou à transformation suffirait à piéger un `fixed`.
 */
export default function Surcouche({
    onFermer,
    etiquettePar,
    children,
}: {
    onFermer: () => void;
    /** Identifiant du titre du panneau, lu par les lecteurs d'écran. */
    etiquettePar: string;
    children: ReactNode;
}) {
    const panneau = useRef<HTMLDivElement>(null);

    // La dernière version du rappel, sans réabonner les écouteurs à chaque
    // rendu du parent — ce qui reprendrait le focus en pleine saisie.
    const fermer = useRef(onFermer);
    fermer.current = onFermer;

    useEffect(() => {
        const ouvreur = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        panneau.current?.focus();

        // La page sous le voile ne défile plus : la molette y ferait bouger un
        // écran qu'on ne voit qu'à travers.
        const debordement = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const auClavier = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                fermer.current();

                return;
            }

            if (e.key !== 'Tab' || !panneau.current) return;

            const elements = [...panneau.current.querySelectorAll<HTMLElement>(FOCALISABLES)];
            if (elements.length === 0) {
                e.preventDefault();

                return;
            }

            const premier = elements[0];
            const dernier = elements[elements.length - 1];
            const dedans = panneau.current.contains(document.activeElement);

            if (e.shiftKey && (document.activeElement === premier || document.activeElement === panneau.current || !dedans)) {
                e.preventDefault();
                dernier.focus();
            } else if (!e.shiftKey && (document.activeElement === dernier || !dedans)) {
                e.preventDefault();
                premier.focus();
            }
        };

        document.addEventListener('keydown', auClavier);

        return () => {
            document.removeEventListener('keydown', auClavier);
            document.body.style.overflow = debordement;
            // L'ouvreur a pu disparaître entre-temps (page de liste changée) :
            // on ne rend le focus qu'à un élément encore présent.
            if (ouvreur && document.contains(ouvreur)) ouvreur.focus();
        };
    }, []);

    return createPortal(
        <>
            {/* Voile : ferme au geste, et empêche de cliquer au travers. */}
            <div onClick={() => fermer.current()} className="fixed inset-0 z-40 bg-slate-900/40" aria-hidden />

            <div
                ref={panneau}
                role="dialog"
                aria-modal="true"
                aria-labelledby={etiquettePar}
                tabIndex={-1}
                className="fixed inset-x-0 bottom-0 z-50 max-h-[85vh] overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl focus:outline-none sm:inset-y-0 sm:start-auto sm:end-0 sm:max-h-none sm:w-[28rem] sm:max-w-[90vw] sm:rounded-none sm:rounded-s-2xl"
            >
                {children}
            </div>
        </>,
        document.body,
    );
}
