import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { useDebounce } from '@/lib/useDebounce';
import { useT } from '@/lib/langue';
import type { Paginated, StockNiveau } from '@/types';

const CHAMP =
    'w-full min-w-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

export default function ProduitsTable({
    entrepotId,
    produitOuvert,
    onOuvrir,
}: {
    entrepotId: string;
    produitOuvert: number | null;
    onOuvrir: (produitId: number) => void;
}) {
    const t = useT();
    const [saisie, setSaisie] = useState('');
    const [page, setPage] = useState(1);

    // La saisie reste instantanée sous les doigts ; seule la valeur retardée
    // entre dans la clé de requête.
    const recherche = useDebounce(saisie);

    const { data, isLoading } = useQuery({
        queryKey: ['pilotage', 'produits', { recherche, entrepotId, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<StockNiveau>>('/stock/niveaux', {
                params: { search: recherche || undefined, entrepot_id: entrepotId || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
        staleTime: 15_000,
        refetchInterval: 60_000,
        refetchIntervalInBackground: false,
        refetchOnWindowFocus: true,
    });

    /*
     * Pas de préchargement au survol.
     *
     * Il avait été posé pour combler la latence du clic. Mesuré dans le
     * navigateur, il échouait à chaque fois : en balayant les lignes, la souris
     * déclenche une rafale de requêtes que le navigateur annule aussitôt. Le
     * détail n'était donc jamais en cache quand on cliquait, et chaque
     * annulation laissait une erreur dans la console.
     *
     * Une optimisation qui ne se déclenche jamais et fait du bruit n'a pas sa
     * place. Le détail se charge au clic — une requête, quand elle sert.
     */

    return (
        <section className="min-w-0">
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <div className="min-w-0 flex-1 sm:max-w-xs">
                    <input
                        type="search"
                        value={saisie}
                        onChange={(e) => {
                            setSaisie(e.target.value);
                            setPage(1);
                        }}
                        placeholder={t('Chercher un produit (nom ou code)…')}
                        aria-label={t('Chercher un produit')}
                        className={CHAMP}
                    />
                </div>
                {data && (
                    <span className="text-sm text-slate-500">
                        {t('{n} article(s)', { n: data.meta.total })}
                    </span>
                )}
            </div>

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table className="w-full text-start text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3 text-start">{t('Code')}</th>
                            <th className="px-4 py-3 text-start">{t('Produit')}</th>
                            <th className="px-4 py-3 text-end">{t('Stock')}</th>
                            <th className="px-4 py-3 text-end">{t('Attendu')}</th>
                            <th className="px-4 py-3 text-end">{t('Valeur')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-slate-400">
                                    {t('Chargement…')}
                                </td>
                            </tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-slate-400">
                                    {recherche
                                        ? t('Aucun article ne correspond à cette recherche.')
                                        : t('Aucun produit physique au catalogue.')}
                                </td>
                            </tr>
                        )}
                        {data?.data.map((niveau) => {
                            const stock = parseFloat(niveau.quantite);
                            const attendu = parseFloat(niveau.en_commande);
                            const ouvert = produitOuvert === niveau.produit_id;

                            return (
                                <tr
                                    key={niveau.produit_id}
                                    onClick={() => onOuvrir(niveau.produit_id)}
                                    className={`cursor-pointer transition ${
                                        ouvert ? 'bg-emerald-50' : 'hover:bg-slate-50'
                                    }`}
                                >
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{niveau.code}</td>
                                    <td className="px-4 py-3">
                                        <span className="font-medium text-slate-900">{niveau.name}</span>
                                        {niveau.unit && (
                                            <span className="ms-1 text-xs text-slate-400">/ {niveau.unit}</span>
                                        )}
                                        {niveau.sous_seuil && (
                                            <span className="ms-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700">
                                                {t('sous seuil')}
                                            </span>
                                        )}
                                    </td>
                                    <td
                                        className={`px-4 py-3 text-end font-semibold tabular-nums ${
                                            stock < 0
                                                ? 'text-red-600'
                                                : stock === 0
                                                  ? 'text-slate-400'
                                                  : 'text-slate-900'
                                        }`}
                                    >
                                        {stock}
                                        {stock <= 0 && (
                                            <span className="ms-2 rounded bg-red-100 px-1.5 py-0.5 text-xs font-normal text-red-700">
                                                {t('rupture')}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-end tabular-nums text-slate-500">
                                        {attendu > 0 ? (
                                            <span className="rounded bg-sky-50 px-1.5 py-0.5 text-xs text-sky-700">
                                                +{attendu}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-end tabular-nums text-slate-600">
                                        {formatMAD(niveau.valeur_achat)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                {data && data.meta.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                        <span className="text-slate-500">
                            {t('Page {p} / {n}', { p: data.meta.current_page, n: data.meta.last_page })}
                        </span>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                disabled={page <= 1}
                                onClick={() => setPage((p) => p - 1)}
                                className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40"
                            >
                                {t('Précédent')}
                            </button>
                            <button
                                type="button"
                                disabled={page >= data.meta.last_page}
                                onClick={() => setPage((p) => p + 1)}
                                className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40"
                            >
                                {t('Suivant')}
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
