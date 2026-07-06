import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { Effet, EffetStatut, EffetType, Paginated } from '@/types';

const STATUTS: Record<EffetStatut, { label: string; classes: string }> = {
    portefeuille: { label: 'En portefeuille', classes: 'bg-sky-100 text-sky-700' },
    encaisse: { label: 'Encaissé', classes: 'bg-emerald-100 text-emerald-700' },
    paye: { label: 'Payé', classes: 'bg-emerald-100 text-emerald-700' },
    impaye: { label: 'Impayé', classes: 'bg-red-100 text-red-700' },
};

export default function Effets() {
    const queryClient = useQueryClient();
    const [type, setType] = useState<EffetType>('recevoir');
    const [error, setError] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['effets', { type }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Effet>>('/effets', { params: { type } });
            return data.data;
        },
        placeholderData: keepPreviousData,
    });

    const action = useMutation({
        mutationFn: ({ id, verb }: { id: number; verb: string }) => api.post(`/effets/${id}/${verb}`),
        onSuccess: () => {
            setError(null);
            queryClient.invalidateQueries({ queryKey: ['effets'] });
            queryClient.invalidateQueries({ queryKey: ['compta-balance-agee'] });
            queryClient.invalidateQueries({ queryKey: ['relances-a-relancer'] });
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
        },
    });

    const isRecevoir = type === 'recevoir';
    const totalPortefeuille = (data ?? [])
        .filter((e) => e.statut === 'portefeuille')
        .reduce((sum, e) => sum + parseFloat(e.montant), 0);

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Effets & traites (LCN)</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Portefeuille d'effets à recevoir et à payer — tirez un effet depuis une facture, puis
                        réglez-le à l'échéance
                    </p>
                </div>
                {totalPortefeuille > 0 && (
                    <div className="text-right">
                        <div className="text-xs uppercase tracking-wide text-slate-400">
                            En portefeuille
                        </div>
                        <div className="text-xl font-bold tabular-nums text-slate-900">
                            {formatMAD(totalPortefeuille)}
                        </div>
                    </div>
                )}
            </div>

            <div className="flex rounded-lg border border-slate-200 bg-white p-1" style={{ width: 'fit-content' }}>
                {(['recevoir', 'payer'] as EffetType[]).map((t) => (
                    <button
                        key={t}
                        onClick={() => setType(t)}
                        className={`rounded-md px-4 py-1.5 text-sm font-medium transition ${
                            type === t ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        {t === 'recevoir' ? 'À recevoir (clients)' : 'À payer (fournisseurs)'}
                    </button>
                ))}
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Effet</th>
                            <th className="px-4 py-3">{isRecevoir ? 'Client' : 'Fournisseur'}</th>
                            <th className="px-4 py-3">Facture</th>
                            <th className="px-4 py-3">Échéance</th>
                            <th className="px-4 py-3 text-right">Montant</th>
                            <th className="px-4 py-3">Statut</th>
                            <th className="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
                        )}
                        {!isLoading && data?.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center text-slate-400">
                                    Aucun effet {isRecevoir ? 'à recevoir' : 'à payer'}. Tirez-en un depuis une
                                    facture ({isRecevoir ? 'Ventes' : 'Achats'}).
                                </td>
                            </tr>
                        )}
                        {data?.map((effet) => (
                            <tr key={effet.id} className="hover:bg-slate-50">
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{effet.code}</td>
                                <td className="px-4 py-2.5 font-medium text-slate-900">{effet.tiers ?? '—'}</td>
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-500">{effet.facture ?? '—'}</td>
                                <td className="px-4 py-2.5 text-slate-600">
                                    {effet.date_echeance}
                                    {effet.en_retard && (
                                        <span className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-700">
                                            échu
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                                    {formatMAD(effet.montant)}
                                </td>
                                <td className="px-4 py-2.5">
                                    <span className={`rounded px-1.5 py-0.5 text-xs ${STATUTS[effet.statut].classes}`}>
                                        {STATUTS[effet.statut].label}
                                    </span>
                                </td>
                                <td className="px-4 py-2.5 text-right">
                                    {effet.statut === 'portefeuille' ? (
                                        <div className="flex items-center justify-end gap-2">
                                            <button
                                                onClick={() =>
                                                    action.mutate({
                                                        id: effet.id,
                                                        verb: isRecevoir ? 'encaisser' : 'payer',
                                                    })
                                                }
                                                disabled={action.isPending}
                                                className="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                                            >
                                                {isRecevoir ? '✓ Encaisser' : '✓ Payer'}
                                            </button>
                                            {isRecevoir && (
                                                <button
                                                    onClick={() =>
                                                        window.confirm(`Marquer l'effet ${effet.code} impayé ?`) &&
                                                        action.mutate({ id: effet.id, verb: 'impaye' })
                                                    }
                                                    className="rounded-md border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 transition hover:bg-red-50"
                                                >
                                                    Impayé
                                                </button>
                                            )}
                                        </div>
                                    ) : (
                                        <span className="text-xs text-slate-400">réglé</span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
