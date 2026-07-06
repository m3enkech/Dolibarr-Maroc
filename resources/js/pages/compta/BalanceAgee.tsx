import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { BalanceAgeeResponse } from '@/types';

type Type = 'clients' | 'fournisseurs';

const BUCKETS: { key: 't0_30' | 't31_60' | 't61_90' | 't90_plus'; label: string; head: string }[] = [
    { key: 't0_30', label: '0 – 30 j', head: 'text-slate-500' },
    { key: 't31_60', label: '31 – 60 j', head: 'text-amber-600' },
    { key: 't61_90', label: '61 – 90 j', head: 'text-orange-600' },
    { key: 't90_plus', label: '+ 90 j', head: 'text-red-600' },
];

export default function BalanceAgee() {
    const [type, setType] = useState<Type>('clients');
    const [au, setAu] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['compta-balance-agee', { type, au }],
        queryFn: async () => {
            const { data } = await api.get<BalanceAgeeResponse>('/compta/balance-agee', {
                params: { type, au: au || undefined },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';
    const tiersLabel = type === 'clients' ? 'Client' : 'Fournisseur';
    const soldeLabel = type === 'clients' ? 'créances' : 'dettes';

    const cell = (value: string, bucket: (typeof BUCKETS)[number]['key']) => {
        const n = parseFloat(value);
        if (Math.abs(n) < 0.005) return <span className="text-slate-300">—</span>;
        const emphase = bucket === 't90_plus' ? 'font-semibold text-red-600' : bucket === 't61_90' ? 'text-orange-600' : '';
        return <span className={`tabular-nums ${emphase}`}>{formatMAD(value)}</span>;
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex rounded-lg border border-slate-200 bg-white p-1">
                    {(['clients', 'fournisseurs'] as Type[]).map((t) => (
                        <button
                            key={t}
                            onClick={() => setType(t)}
                            className={`rounded-md px-4 py-1.5 text-sm font-medium capitalize transition ${
                                type === t ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            {t === 'clients' ? 'Clients (créances)' : 'Fournisseurs (dettes)'}
                        </button>
                    ))}
                </div>
                <div className="flex items-center gap-2">
                    <label className="text-sm text-slate-600">Arrêté au</label>
                    <input type="date" value={au} onChange={(e) => setAu(e.target.value)} className={input} />
                    {au && (
                        <button onClick={() => setAu('')} className="text-sm text-emerald-600 hover:underline">
                            Aujourd'hui
                        </button>
                    )}
                </div>
            </div>

            <p className="text-xs text-slate-500">
                Soldes ouverts (non lettrés) des {soldeLabel}, par ancienneté depuis la date de pièce
                {data && ` — arrêté au ${new Date(data.date_reference).toLocaleDateString('fr-FR')}`}.
            </p>

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">{tiersLabel}</th>
                            <th className="px-4 py-3 text-right">Solde ouvert</th>
                            {BUCKETS.map((b) => (
                                <th key={b.key} className={`px-4 py-3 text-right ${b.head}`}>
                                    {b.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-slate-400">
                                    ✓ Aucun {soldeLabel === 'créances' ? 'client débiteur' : 'fournisseur créditeur'} :
                                    tout est soldé.
                                </td>
                            </tr>
                        )}
                        {data?.data.map((row) => (
                            <tr key={row.tiers_id ?? 'sans'} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{row.code ?? '—'}</td>
                                <td className="px-4 py-2.5 font-medium text-slate-900">{row.name}</td>
                                <td className="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                                    {formatMAD(row.total)}
                                </td>
                                {BUCKETS.map((b) => (
                                    <td key={b.key} className="px-4 py-2.5 text-right">
                                        {cell(row[b.key], b.key)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                    {data && data.data.length > 0 && (
                        <tfoot className="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                            <tr>
                                <td colSpan={2} className="px-4 py-3 text-slate-900">Total {soldeLabel}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatMAD(data.totaux.total)}</td>
                                {BUCKETS.map((b) => (
                                    <td key={b.key} className="px-4 py-3 text-right tabular-nums">
                                        {formatMAD(data.totaux[b.key])}
                                    </td>
                                ))}
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
}
