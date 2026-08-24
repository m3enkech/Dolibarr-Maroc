import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';

export type Langue = 'fr' | 'ar';

export const LANGUES: { code: Langue; libelle: string }[] = [
    { code: 'fr', libelle: 'Français' },
    { code: 'ar', libelle: 'العربية' },
];

/** Préférence d'affichage, propre à l'appareil : partagée par l'ERP et le portail. */
const CLE = 'langue';

/**
 * Dictionnaire arabe. La clé EST la chaîne française telle qu'elle est écrite
 * dans le code : une traduction manquante affiche donc du français, jamais une
 * clé technique du genre « dashboard.title ».
 */
type Dictionnaire = Record<string, string>;

interface LangueState {
    langue: Langue;
    dir: 'ltr' | 'rtl';
    changer: (langue: Langue) => void;
    t: (fr: string, vars?: Record<string, string | number>) => string;
}

const LangueContext = createContext<LangueState | null>(null);

function langueInitiale(): Langue {
    const stockee = localStorage.getItem(CLE);
    if (stockee === 'ar' || stockee === 'fr') {
        return stockee;
    }

    return navigator.language?.toLowerCase().startsWith('ar') ? 'ar' : 'fr';
}

/** Langue courante hors composant React — pour les en-têtes HTTP. */
export function langueCourante(): Langue {
    return langueInitiale();
}

function interpoler(modele: string, vars?: Record<string, string | number>): string {
    if (vars === undefined) {
        return modele;
    }

    return modele.replace(/\{(\w+)\}/g, (_, cle: string) =>
        vars[cle] !== undefined ? String(vars[cle]) : `{${cle}}`,
    );
}

export function LangueProvider({ children }: { children: ReactNode }) {
    const [langue, setLangue] = useState<Langue>(langueInitiale);
    const [dico, setDico] = useState<Dictionnaire>({});

    // Le dictionnaire n'est chargé qu'à la demande : un utilisateur francophone
    // ne télécharge pas des milliers de chaînes qu'il ne lira jamais.
    useEffect(() => {
        if (langue !== 'ar') {
            setDico({});

            return;
        }

        let vivant = true;
        import('@/lang/ar.json').then((module) => {
            if (vivant) {
                setDico(module.default as Dictionnaire);
            }
        });

        return () => {
            vivant = false;
        };
    }, [langue]);

    const dir = langue === 'ar' ? 'rtl' : 'ltr';

    // `dir` sur <html> : c'est lui qui retourne toute la mise en page, à
    // condition que les classes utilisées soient logiques (ms/me, ps/pe,
    // text-start/end) et non directionnelles (ml, pl, text-left).
    useEffect(() => {
        document.documentElement.lang = langue;
        document.documentElement.dir = dir;
    }, [langue, dir]);

    const valeur: LangueState = {
        langue,
        dir,
        changer: (choix) => {
            localStorage.setItem(CLE, choix);
            setLangue(choix);
        },
        t: (fr, vars) => interpoler(dico[fr] ?? fr, vars),
    };

    return <LangueContext.Provider value={valeur}>{children}</LangueContext.Provider>;
}

export function useLangue(): LangueState {
    const contexte = useContext(LangueContext);

    if (contexte === null) {
        throw new Error('useLangue doit être utilisé dans un LangueProvider.');
    }

    return contexte;
}

/** Raccourci pour le cas courant : seulement traduire. */
export function useT() {
    return useLangue().t;
}
