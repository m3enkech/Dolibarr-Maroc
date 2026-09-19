import { useT } from '@/lib/langue';

export type AnneeDisponible = { annee: number; total: number };

/** Au-delà, la rangée de pastilles devient un mur qu'on balaie au lieu de lire. */
const PASTILLES_VISIBLES = 3;

/**
 * Le choix d'un exercice, qui tient encore en 2050.
 *
 * Une pastille par année marchait avec cinq exercices ; à vingt-cinq elle
 * remplit l'écran et il faut la faire défiler pour atteindre l'année en cours —
 * exactement le défaut qu'on venait de corriger sur la pagination.
 *
 * On garde donc les trois années les plus RÉCENTES en accès direct — c'est là
 * qu'on travaille — et on renvoie le reste dans une liste déroulante. L'année
 * choisie reste toujours VISIBLE en pastille, même prise dans la liste : sans
 * cela on filtre sur 2031 sans plus voir nulle part que c'est le cas.
 */
export default function SelecteurAnnee({
    annees,
    valeur,
    onChange,
}: {
    annees: AnneeDisponible[];
    valeur: number | null;
    onChange: (annee: number | null) => void;
}) {
    const t = useT();

    if (annees.length <= 1) return null;

    const recentes = annees.slice(0, PASTILLES_VISIBLES).map((a) => a.annee);

    // L'année active s'invite parmi les pastilles si elle n'y était pas.
    const visibles = valeur !== null && ! recentes.includes(valeur)
        ? [...recentes, valeur].sort((a, b) => b - a)
        : recentes;

    const reste = annees.filter((a) => ! visibles.includes(a.annee));

    const pastille = (actif: boolean) =>
        `rounded-full px-3 py-1 text-sm transition ${
            actif ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
        }`;

    return (
        <div className="flex flex-wrap items-center gap-2">
            <button
                onClick={() => onChange(null)}
                className={`rounded-full px-3 py-1 text-sm transition ${
                    valeur === null ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                }`}
            >
                {t('Toutes')}
            </button>

            {visibles.map((annee) => {
                const total = annees.find((a) => a.annee === annee)?.total ?? 0;

                return (
                    <button
                        key={annee}
                        onClick={() => onChange(valeur === annee ? null : annee)}
                        className={pastille(valeur === annee)}
                    >
                        {annee}
                        <span className={valeur === annee ? 'ms-1.5 text-emerald-100' : 'ms-1.5 text-slate-400'}>
                            {total}
                        </span>
                    </button>
                );
            })}

            {reste.length > 0 && (
                <select
                    value=""
                    onChange={(e) => e.target.value && onChange(Number(e.target.value))}
                    className="rounded-full border-0 bg-white px-3 py-1 text-sm text-slate-600 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    aria-label={t('Autre exercice')}
                >
                    <option value="">{t('Autre exercice…')}</option>
                    {reste.map((a) => (
                        <option key={a.annee} value={a.annee}>
                            {a.annee} ({a.total})
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}
