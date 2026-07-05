import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Entrepot, MouvementStock, Paginated } from '@/types';

const TYPE_BADGES: Record<MouvementStock['type'], { label: string; classes: string }> = {
    entree: { label: 'Entrée', classes: 'bg-emerald-100 text-emerald-700' },
    sortie: { label: 'Sortie', classes: 'bg-amber-100 text-amber-700' },
    ajustement: { label: 'Ajustement', classes: 'bg-violet-100 text-violet-700' },
    vente: { label: 'Vente', classes: 'bg-sky-100 text-sky-700' },
    achat: { label: 'Achat', classes: 'bg-teal-100 text-teal-700' },
};

export default function StockMouvements({ entrepots }: { entrepots: Entrepot[] }) {
    const [entrepotId, setEntrepotId] = useState('');
    const [page, setPage] = useState(1);

    const { data, isLoading } = useQuery({
        queryKey: ['stock-mouvements', { entrepotId, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<MouvementStock>>('/stock/mouvements', {
                params: { entrepot_id: entrepotId || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    return (
        <div className="space-y-4">
            <select
                value={entrepotId}
                onChange={(e) => {
                    setEntrepotId(e.target.value);
                    setPage(1);
                }}
                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
            >
                <option value="">Tous les entrepôts</option>
                {entrepots.map((entrepot) => (
                    <option key={entrepot.id} value={entrepot.id}>
                        {entrepot.name}
                    </option>
                ))}
            </select>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Date</th>
                            <th className="px-4 py-3">Type</th>
                            <th className="px-4 py-3">Produit</th>
                            <th className="px-4 py-3">Entrepôt</th>
                            <th className="px-4 py-3 text-right">Quantité</th>
                            <th className="px-4 py-3 text-right">Stock après</th>
                            <th className="px-4 py-3">Référence / Note</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-slate-400">Chargement…</td>
                            </tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-slate-400">
                                    Aucun mouvement de stock.
                                </td>
                            </tr>
                        )}
                        {data?.data.map((mouvement) => {
                            const badge = TYPE_BADGES[mouvement.type];
                            const quantiteNum = parseFloat(mouvement.quantite);
                            return (
                                <tr key={mouvement.id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 text-slate-600">
                                        {new Date(mouvement.created_at).toLocaleString('fr-FR', {
                                            dateStyle: 'short',
                                            timeStyle: 'short',
                                        })}
                                    </td>
                                    <td className="px-4 py-3">
                                        <span className={`rounded px-1.5 py-0.5 text-xs ${badge.classes}`}>
                                            {badge.label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 font-medium text-slate-900">
                                        {mouvement.produit?.name ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 text-slate-600">{mouvement.entrepot?.name ?? '—'}</td>
                                    <td
                                        className={`px-4 py-3 text-right font-medium tabular-nums ${
                                            quantiteNum < 0 ? 'text-red-600' : 'text-emerald-700'
                                        }`}
                                    >
                                        {quantiteNum > 0 ? '+' : ''}
                                        {quantiteNum}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-900">
                                        {parseFloat(mouvement.quantite_apres)}
                                    </td>
                                    <td className="px-4 py-3 text-slate-500">
                                        {mouvement.reference ?? mouvement.note ?? '—'}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                {data && data.meta.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                        <span className="text-slate-500">
                            Page {data.meta.current_page} / {data.meta.last_page} — {data.meta.total} mouvements
                        </span>
                        <div className="space-x-2">
                            <button
                                disabled={page <= 1}
                                onClick={() => setPage((p) => p - 1)}
                                className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40"
                            >
                                Précédent
                            </button>
                            <button
                                disabled={page >= data.meta.last_page}
                                onClick={() => setPage((p) => p + 1)}
                                className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40"
                            >
                                Suivant
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
