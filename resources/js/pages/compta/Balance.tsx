import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { BalanceRow } from '@/types';

interface BalanceResponse {
    data: BalanceRow[];
    totaux: { debit: string; credit: string };
    classes: Record<string, string>;
}

export default function Balance() {
    const [du, setDu] = useState('');
    const [au, setAu] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['compta-balance', { du, au }],
        queryFn: async () => {
            const { data } = await api.get<BalanceResponse>('/compta/balance', {
                params: { du: du || undefined, au: au || undefined },
            });
            return data;
        },
    });

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-3">
                <label className="text-sm text-slate-600">Du</label>
                <input type="date" value={du} onChange={(e) => setDu(e.target.value)} className={input} />
                <label className="text-sm text-slate-600">au</label>
                <input type="date" value={au} onChange={(e) => setAu(e.target.value)} className={input} />
                {(du || au) && (
                    <button
                        onClick={() => {
                            setDu('');
                            setAu('');
                        }}
                        className="text-sm text-emerald-600 hover:underline"
                    >
                        Réinitialiser
                    </button>
                )}
            </div>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Compte</th>
                            <th className="px-4 py-3">Intitulé</th>
                            <th className="px-4 py-3 text-right">Total débit</th>
                            <th className="px-4 py-3 text-right">Total crédit</th>
                            <th className="px-4 py-3 text-right">Solde débiteur</th>
                            <th className="px-4 py-3 text-right">Solde créditeur</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                                    Aucun compte mouvementé sur la période.
                                </td>
                            </tr>
                        )}
                        {data?.data.map((row) => (
                            <tr key={row.compte_id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-700">{row.code}</td>
                                <td className="px-4 py-2.5 text-slate-900">{row.label}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-slate-600">{formatMAD(row.total_debit)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-slate-600">{formatMAD(row.total_credit)}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium text-slate-900">
                                    {parseFloat(row.solde_debiteur) > 0 ? formatMAD(row.solde_debiteur) : '—'}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums font-medium text-slate-900">
                                    {parseFloat(row.solde_crediteur) > 0 ? formatMAD(row.solde_crediteur) : '—'}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    {data && data.data.length > 0 && (
                        <tfoot className="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                            <tr>
                                <td colSpan={2} className="px-4 py-3 text-slate-900">Totaux</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatMAD(data.totaux.debit)}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatMAD(data.totaux.credit)}</td>
                                <td colSpan={2} className="px-4 py-3 text-right">
                                    {data.totaux.debit === data.totaux.credit ? (
                                        <span className="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">
                                            Balance équilibrée
                                        </span>
                                    ) : (
                                        <span className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">
                                            Déséquilibre !
                                        </span>
                                    )}
                                </td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
}
