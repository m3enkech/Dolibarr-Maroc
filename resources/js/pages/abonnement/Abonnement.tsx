import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { AbonnementData } from '@/types';

const STATUT: Record<string, { label: string; cls: string }> = {
    essai: { label: 'Essai', cls: 'bg-indigo-100 text-indigo-700' },
    actif: { label: 'Actif', cls: 'bg-emerald-100 text-emerald-700' },
    en_retard: { label: 'En retard', cls: 'bg-red-100 text-red-700' },
    suspendu: { label: 'Suspendu', cls: 'bg-slate-200 text-slate-600' },
    annule: { label: 'Annulé', cls: 'bg-slate-200 text-slate-500' },
};

const METHOD_LABEL: Record<string, string> = {
    virement: 'Virement', cmi: 'CMI (carte)', cheque: 'Chèque', especes: 'Espèces', autre: 'Autre',
};

const fr = (d: string | null) => (d ? new Date(d).toLocaleDateString('fr-MA') : '—');

export default function Abonnement() {
    const { data, isLoading } = useQuery({
        queryKey: ['abonnement'],
        queryFn: async () => (await api.get<{ data: AbonnementData }>('/abonnement')).data.data,
    });

    const telechargerPdf = async (paymentId: number) => {
        const response = await api.get(`/abonnement/factures/${paymentId}/pdf`, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `facture-abonnement-${paymentId}.pdf`;
        link.click();
        URL.revokeObjectURL(url);
    };

    if (isLoading || !data) return <div className="text-sm text-slate-400">Chargement…</div>;

    const s = data.subscription;
    const st = STATUT[s.status] ?? STATUT.actif;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Mon abonnement</h1>
                <p className="mt-1 text-sm text-slate-500">Votre formule, son statut et vos factures d’abonnement.</p>
            </div>

            <section className="rounded-xl bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div className="text-xs uppercase tracking-wide text-slate-400">Formule</div>
                        <div className="mt-1 text-lg font-semibold text-slate-900">{s.plan_label}</div>
                        <div className="mt-0.5 text-sm text-slate-500">
                            {formatMAD(s.amount)} <span className="text-slate-400">/ {s.billing_cycle === 'annuel' ? 'an' : 'mois'}</span>
                        </div>
                    </div>
                    <span className={`rounded-full px-3 py-1 text-xs font-medium ${st.cls}`}>{st.label}</span>
                </div>
                <div className="mt-4 grid gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-2">
                    <div>
                        <div className="text-xs text-slate-400">Prochaine échéance</div>
                        <div className="mt-0.5 text-slate-700">{fr(s.current_period_end)}</div>
                    </div>
                    {s.trial_ends_at && (
                        <div>
                            <div className="text-xs text-slate-400">Fin de l’essai</div>
                            <div className="mt-0.5 text-slate-700">{fr(s.trial_ends_at)}</div>
                        </div>
                    )}
                </div>
            </section>

            <section className="rounded-xl bg-white shadow-sm">
                <div className="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">
                    Factures d’abonnement
                </div>
                {!data.factures.length ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">Aucun paiement enregistré pour le moment.</div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 text-left text-xs uppercase text-slate-400">
                                    <th className="px-5 py-3">Date</th>
                                    <th className="px-5 py-3">Période</th>
                                    <th className="px-5 py-3">Mode</th>
                                    <th className="px-5 py-3">Référence</th>
                                    <th className="px-5 py-3 text-right">Montant</th>
                                    <th className="px-5 py-3 text-right">Facture</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {data.factures.map((f) => (
                                    <tr key={f.id}>
                                        <td className="px-5 py-3 text-slate-600">{fr(f.paid_at)}</td>
                                        <td className="px-5 py-3 text-slate-500">{fr(f.period_start)} → {fr(f.period_end)}</td>
                                        <td className="px-5 py-3 text-slate-600">{METHOD_LABEL[f.method] ?? f.method}</td>
                                        <td className="px-5 py-3 text-slate-400">{f.reference ?? '—'}</td>
                                        <td className="px-5 py-3 text-right font-medium text-slate-700">{formatMAD(f.amount)}</td>
                                        <td className="px-5 py-3 text-right">
                                            {f.has_invoice ? (
                                                <button
                                                    onClick={() => telechargerPdf(f.id)}
                                                    className="text-xs font-medium text-emerald-600 hover:underline"
                                                >
                                                    Télécharger PDF
                                                </button>
                                            ) : (
                                                <span className="text-xs text-slate-300">—</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </div>
    );
}
