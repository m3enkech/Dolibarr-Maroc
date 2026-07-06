import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Entrepot, StockAlerte } from '@/types';

export default function Alertes({ entrepots }: { entrepots: Entrepot[] }) {
    const [entrepotId, setEntrepotId] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['stock-alertes', { entrepotId }],
        queryFn: async () => {
            const { data } = await api.get<{ data: StockAlerte[] }>('/stock/alertes', {
                params: { entrepot_id: entrepotId || undefined },
            });
            return data.data;
        },
    });

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <select value={entrepotId} onChange={(e) => setEntrepotId(e.target.value)} className={input}>
                    <option value="">Tous les entrepôts</option>
                    {entrepots.map((entrepot) => (
                        <option key={entrepot.id} value={entrepot.id}>
                            {entrepot.name}
                        </option>
                    ))}
                </select>
                {data && data.length > 0 && (
                    <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700">
                        {data.length} produit{data.length > 1 ? 's' : ''} à réapprovisionner
                    </span>
                )}
            </div>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Produit</th>
                            <th className="px-4 py-3 text-right">Stock actuel</th>
                            <th className="px-4 py-3 text-right">Seuil</th>
                            <th className="px-4 py-3 text-right">En commande</th>
                            <th className="px-4 py-3 text-right">À commander</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">Chargement…</td>
                            </tr>
                        )}
                        {!isLoading && data?.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-10 text-center text-slate-400">
                                    ✓ Aucun produit sous son seuil. Définissez un seuil d'alerte sur vos
                                    produits pour activer le suivi.
                                </td>
                            </tr>
                        )}
                        {data?.map((alerte) => {
                            const suggestion = parseFloat(alerte.suggestion);
                            return (
                                <tr key={alerte.produit_id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{alerte.code}</td>
                                    <td className="px-4 py-3 font-medium text-slate-900">
                                        {alerte.name}
                                        {alerte.unit && <span className="ml-1 text-xs text-slate-400">/ {alerte.unit}</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right font-semibold tabular-nums text-amber-700">
                                        {parseFloat(alerte.quantite)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-500">
                                        {parseFloat(alerte.stock_min)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-500">
                                        {parseFloat(alerte.en_commande) > 0 ? (
                                            <span className="rounded bg-sky-50 px-1.5 py-0.5 text-sky-700">
                                                +{parseFloat(alerte.en_commande)}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                        {suggestion > 0 ? (
                                            <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700">
                                                {suggestion}
                                            </span>
                                        ) : (
                                            <span className="text-slate-400">couvert</span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <p className="text-xs text-slate-500">
                « À commander » = quantité cible − stock actuel − quantité déjà en commande. Les produits sans
                seuil d'alerte ne sont pas suivis.
            </p>
        </div>
    );
}
