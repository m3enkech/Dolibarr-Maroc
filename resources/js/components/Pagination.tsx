import { useT } from '@/lib/langue';

type Meta = { current_page: number; last_page: number; total: number; per_page?: number };

/**
 * Pagination qui permet d'ATTEINDRE une page, pas seulement d'avancer d'une.
 *
 * Le défaut d'origine : « Précédent / Suivant » sur 1 314 factures, soit
 * quatre-vingt-huit clics pour remonter à 2022. Une liste dont le fond est
 * inatteignable est une liste dont le fond n'existe pas.
 *
 * La fenêtre de numéros reste courte et de LARGEUR CONSTANTE : des boutons qui
 * se déplacent sous le curseur au fil des pages font cliquer à côté.
 *
 * `compacte` : pour une colonne étroite (liste des tiers à côté de la fiche).
 * Trois numéros et sans « première / dernière » — neuf boutons sur 300 px
 * repliaient la barre sur trois lignes.
 */
export default function Pagination({
    meta,
    onPage,
    onPerPage,
    compacte = false,
}: {
    meta: Meta;
    onPage: (page: number) => void;
    onPerPage?: (taille: number) => void;
    compacte?: boolean;
}) {
    const t = useT();

    if (meta.last_page <= 1 && !onPerPage) return null;

    const courante = meta.current_page;
    const derniere = meta.last_page;
    const largeur = compacte ? 3 : 5;

    // Cinq numéros au plus (trois en compact), recentrés sur la page courante
    // et recollés aux bords quand on s'en approche — d'où le double clamp.
    const debut = Math.max(1, Math.min(courante - Math.floor(largeur / 2), derniere - largeur + 1));
    const pages: number[] = [];
    for (let p = debut; p <= Math.min(derniere, debut + largeur - 1); p++) pages.push(p);

    const bouton = 'min-w-[2.25rem] rounded-md border border-slate-300 px-2 py-1 text-sm transition hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white';

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3 text-sm">
            <div className="flex items-center gap-3">
                <span className="text-slate-500">
                    {meta.total.toLocaleString('fr-MA')} {t('résultats')}
                </span>
                {onPerPage && (
                    <select
                        value={meta.per_page ?? 15}
                        onChange={(e) => onPerPage(Number(e.target.value))}
                        className="rounded-md border border-slate-300 bg-white px-2 py-1 text-sm"
                        aria-label={t('Résultats par page')}
                    >
                        {[15, 25, 50, 100].map((n) => (
                            <option key={n} value={n}>
                                {n} {t('par page')}
                            </option>
                        ))}
                    </select>
                )}
            </div>

            {derniere > 1 && (
                <div className="flex flex-wrap items-center gap-1">
                    {!compacte && (
                        <button className={bouton} disabled={courante <= 1} onClick={() => onPage(1)} aria-label={t('Première page')}>
                            «
                        </button>
                    )}
                    <button className={bouton} disabled={courante <= 1} onClick={() => onPage(courante - 1)} aria-label={t('Page précédente')}>
                        ‹
                    </button>

                    {pages.map((p) => (
                        <button
                            key={p}
                            onClick={() => onPage(p)}
                            aria-current={p === courante ? 'page' : undefined}
                            className={`min-w-[2.25rem] rounded-md px-2 py-1 text-sm transition ${
                                p === courante
                                    ? 'bg-emerald-600 font-medium text-white'
                                    : 'border border-slate-300 hover:bg-slate-50'
                            }`}
                        >
                            {p}
                        </button>
                    ))}

                    <button className={bouton} disabled={courante >= derniere} onClick={() => onPage(courante + 1)} aria-label={t('Page suivante')}>
                        ›
                    </button>
                    {!compacte && (
                        <button className={bouton} disabled={courante >= derniere} onClick={() => onPage(derniere)} aria-label={t('Dernière page')}>
                            »
                        </button>
                    )}

                    <span className="ms-2 text-slate-400">
                        {t('sur')} {derniere}
                    </span>
                </div>
            )}
        </div>
    );
}
