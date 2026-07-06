import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { RelanceLigne } from '@/types';

const NIVEAUX: Record<number, { label: string; classes: string }> = {
    1: { label: 'Rappel', classes: 'bg-blue-100 text-blue-700' },
    2: { label: 'Relance ferme', classes: 'bg-orange-100 text-orange-700' },
    3: { label: 'Mise en demeure', classes: 'bg-red-100 text-red-700' },
};

function retardClasses(jours: number): string {
    if (jours > 90) return 'text-red-600 font-semibold';
    if (jours > 30) return 'text-orange-600';
    return 'text-slate-700';
}

function RelanceRow({ ligne, onDone }: { ligne: RelanceLigne; onDone: () => void }) {
    const suggested = Math.min(3, (ligne.dernier_niveau ?? 0) + 1);
    const [niveau, setNiveau] = useState(suggested);

    const marquer = useMutation({
        mutationFn: () =>
            api.post('/relances', { document_vente_id: ligne.document_vente_id, niveau, canal: 'courrier' }),
        onSuccess: onDone,
    });

    const telechargerLettre = async () => {
        const res = await api.get(`/relances/${ligne.document_vente_id}/lettre?niveau=${niveau}`, {
            responseType: 'blob',
        });
        const url = URL.createObjectURL(res.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `relance-${ligne.code}.pdf`;
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <tr className="hover:bg-slate-50">
            <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{ligne.code}</td>
            <td className="px-4 py-2.5 font-medium text-slate-900">{ligne.tiers ?? '—'}</td>
            <td className="px-4 py-2.5 text-slate-600">{ligne.date_echeance}</td>
            <td className={`px-4 py-2.5 text-right tabular-nums ${retardClasses(ligne.jours_retard)}`}>
                {ligne.jours_retard} j
            </td>
            <td className="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                {formatMAD(ligne.reste_a_payer)}
            </td>
            <td className="px-4 py-2.5">
                {ligne.dernier_niveau ? (
                    <span className={`rounded px-1.5 py-0.5 text-xs ${NIVEAUX[ligne.dernier_niveau].classes}`}>
                        {NIVEAUX[ligne.dernier_niveau].label} ({ligne.nb_relances})
                    </span>
                ) : (
                    <span className="text-xs text-slate-400">jamais relancé</span>
                )}
            </td>
            <td className="px-4 py-2.5">
                <div className="flex items-center justify-end gap-2">
                    <select
                        value={niveau}
                        onChange={(e) => setNiveau(parseInt(e.target.value, 10))}
                        className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs focus:border-emerald-500 focus:outline-none"
                    >
                        {Object.entries(NIVEAUX).map(([value, { label }]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                    <button
                        onClick={telechargerLettre}
                        className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        ⬇ Lettre
                    </button>
                    <button
                        onClick={() => marquer.mutate()}
                        disabled={marquer.isPending}
                        className="rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        ✓ Relancé
                    </button>
                </div>
            </td>
        </tr>
    );
}

export default function Relances() {
    const queryClient = useQueryClient();
    const [au, setAu] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['relances-a-relancer', { au }],
        queryFn: async () => {
            const { data } = await api.get<{ data: RelanceLigne[] }>('/relances/a-relancer', {
                params: { au: au || undefined },
            });
            return data.data;
        },
    });

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['relances-a-relancer'] });

    const totalDu = (data ?? []).reduce((sum, l) => sum + parseFloat(l.reste_a_payer), 0);

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Relances</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Factures échues non soldées — relancez et générez la lettre en un clic
                    </p>
                </div>
                {data && data.length > 0 && (
                    <div className="text-right">
                        <div className="text-xs uppercase tracking-wide text-slate-400">Total à recouvrer</div>
                        <div className="text-xl font-bold tabular-nums text-red-600">{formatMAD(totalDu)}</div>
                    </div>
                )}
            </div>

            <div className="flex items-center gap-2">
                <label className="text-sm text-slate-600">Arrêté au</label>
                <input
                    type="date"
                    value={au}
                    onChange={(e) => setAu(e.target.value)}
                    className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                />
                {au && (
                    <button onClick={() => setAu('')} className="text-sm text-emerald-600 hover:underline">
                        Aujourd'hui
                    </button>
                )}
            </div>

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Facture</th>
                            <th className="px-4 py-3">Client</th>
                            <th className="px-4 py-3">Échéance</th>
                            <th className="px-4 py-3 text-right">Retard</th>
                            <th className="px-4 py-3 text-right">Reste dû</th>
                            <th className="px-4 py-3">Dernière relance</th>
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
                                    ✓ Aucune facture échue impayée. Tout est à jour !
                                </td>
                            </tr>
                        )}
                        {data?.map((ligne) => (
                            <RelanceRow key={ligne.document_vente_id} ligne={ligne} onDone={refresh} />
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
