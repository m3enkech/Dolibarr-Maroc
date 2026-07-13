import { Fragment, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Navigate } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatMAD } from '@/lib/format';
import type { EquipeUser, SuperadminData, SuperadminPayment, SuperadminTenant } from '@/types';

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

function StatCard({ label, value, tone = 'slate' }: { label: string; value: string; tone?: string }) {
    return (
        <div className="rounded-xl bg-white p-4 shadow-sm">
            <div className="text-xs text-slate-500">{label}</div>
            <div className={`mt-1 text-2xl font-semibold text-${tone}-700`}>{value}</div>
        </div>
    );
}

/** Détail dépliable : utilisateurs, sièges extra, paiement + historique. */
function TenantDetail({ tenant, methods, colSpan }: { tenant: SuperadminTenant; methods: string[]; colSpan: number }) {
    const queryClient = useQueryClient();
    const [amount, setAmount] = useState(String(tenant.subscription_amount));
    const [method, setMethod] = useState(methods[0] ?? 'virement');
    const [reference, setReference] = useState('');
    const [msg, setMsg] = useState<string | null>(null);

    const { data } = useQuery({
        queryKey: ['superadmin', 'tenant', tenant.id],
        queryFn: async () =>
            (await api.get<{ data: { users: EquipeUser[]; payments: SuperadminPayment[] } }>(
                `/superadmin/tenants/${tenant.id}`,
            )).data.data,
    });

    const refresh = () => {
        queryClient.invalidateQueries({ queryKey: ['superadmin', 'tenants'] });
        queryClient.invalidateQueries({ queryKey: ['superadmin', 'tenant', tenant.id] });
    };

    const majSeats = useMutation({
        mutationFn: (extra: number) => api.put(`/superadmin/tenants/${tenant.id}`, { extra_seats: extra }),
        onSuccess: refresh,
    });

    const payer = useMutation({
        mutationFn: () =>
            api.post(`/superadmin/tenants/${tenant.id}/paiements`, {
                amount: Number(amount), method, reference: reference || null,
            }),
        onSuccess: () => {
            setMsg('Paiement enregistré ✓');
            setReference('');
            setTimeout(() => setMsg(null), 2500);
            refresh();
        },
        onError: () => setMsg('Échec de l’enregistrement'),
    });

    return (
        <tr className="bg-slate-50/60">
            <td colSpan={colSpan} className="px-4 py-4">
                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Enregistrer un paiement */}
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Enregistrer un paiement
                        </div>
                        <div className="mt-2 space-y-2">
                            <div className="flex gap-2">
                                <input
                                    type="number"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    className="w-28 rounded border border-slate-300 px-2 py-1 text-sm"
                                    placeholder="Montant"
                                />
                                <select
                                    value={method}
                                    onChange={(e) => setMethod(e.target.value)}
                                    className="flex-1 rounded border border-slate-300 px-2 py-1 text-sm"
                                >
                                    {methods.map((m) => (
                                        <option key={m} value={m}>{METHOD_LABEL[m] ?? m}</option>
                                    ))}
                                </select>
                            </div>
                            <input
                                value={reference}
                                onChange={(e) => setReference(e.target.value)}
                                className="w-full rounded border border-slate-300 px-2 py-1 text-sm"
                                placeholder="Référence (n° virement, chèque…)"
                            />
                            <button
                                onClick={() => payer.mutate()}
                                disabled={payer.isPending || !amount}
                                className="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                            >
                                Encaisser ({tenant.billing_cycle})
                            </button>
                            {msg && <div className="text-xs text-emerald-600">{msg}</div>}
                        </div>
                        <div className="mt-3 text-xs text-slate-500">
                            Sièges extra :{' '}
                            <input
                                type="number"
                                min={0}
                                defaultValue={tenant.extra_seats}
                                onBlur={(e) => {
                                    const v = Number(e.target.value);
                                    if (v !== tenant.extra_seats) majSeats.mutate(v);
                                }}
                                className="w-16 rounded border border-slate-200 px-2 py-0.5 text-xs"
                            />
                        </div>
                    </div>

                    {/* Historique des paiements */}
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Historique des paiements
                        </div>
                        {!data?.payments.length ? (
                            <div className="mt-2 text-xs text-slate-400">Aucun paiement enregistré.</div>
                        ) : (
                            <ul className="mt-2 space-y-1 text-xs">
                                {data.payments.map((p) => (
                                    <li key={p.id} className="flex justify-between border-b border-slate-100 pb-1">
                                        <span>
                                            {new Date(p.paid_at).toLocaleDateString('fr-MA')} · {METHOD_LABEL[p.method] ?? p.method}
                                            {p.reference && <span className="text-slate-400"> · {p.reference}</span>}
                                        </span>
                                        <span className="font-medium text-slate-700">{formatMAD(p.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {/* Utilisateurs */}
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Utilisateurs ({data?.users.length ?? tenant.users_count})
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {data?.users.map((u) => (
                                <span
                                    key={u.id}
                                    className={`rounded-full border border-slate-200 px-2 py-0.5 text-xs ${
                                        u.is_active ? 'bg-white text-slate-600' : 'bg-slate-200 text-slate-400 line-through'
                                    }`}
                                >
                                    {u.name} · {u.role_label}{u.is_superadmin && ' ⭐'}
                                </span>
                            ))}
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    );
}

export default function Superadmin() {
    const { user } = useAuth();
    const queryClient = useQueryClient();
    const [expanded, setExpanded] = useState<number | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['superadmin', 'tenants'],
        queryFn: async () => (await api.get<{ data: SuperadminData }>('/superadmin/tenants')).data.data,
    });

    const refresh = () => queryClient.invalidateQueries({ queryKey: ['superadmin', 'tenants'] });

    const maj = useMutation({
        mutationFn: (v: { id: number; plan?: string; billing_cycle?: string }) =>
            api.put(`/superadmin/tenants/${v.id}`, { plan: v.plan, billing_cycle: v.billing_cycle }),
        onSuccess: refresh,
    });

    const suspendre = useMutation({
        mutationFn: (v: { id: number; suspend: boolean }) =>
            api.post(`/superadmin/tenants/${v.id}/${v.suspend ? 'suspend' : 'reactivate'}`),
        onSuccess: refresh,
        onError: (err: any) => alert(err?.response?.data?.message ?? 'Action impossible.'),
    });

    if (user && !user.is_superadmin) return <Navigate to="/dashboard" replace />;
    if (isLoading || !data) return <div className="text-sm text-slate-400">Chargement…</div>;

    const s = data.stats;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Administration plateforme</h1>
                <p className="mt-1 text-sm text-slate-500">Entreprises, abonnements et suivi des paiements.</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard label="Entreprises" value={`${s.tenants_total}`} tone="emerald" />
                <StatCard label="MRR estimé" value={formatMAD(s.mrr_estimated)} tone="emerald" />
                <StatCard label="Encaissé ce mois" value={formatMAD(s.encaisse_mois)} tone="emerald" />
                <StatCard label="En essai" value={`${s.en_essai}`} tone="indigo" />
                <StatCard label="En retard" value={`${s.en_retard}`} tone={s.en_retard ? 'red' : 'slate'} />
                <StatCard label="Suspendues" value={`${s.tenants_suspended}`} tone={s.tenants_suspended ? 'red' : 'slate'} />
            </div>

            <section className="rounded-xl bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-100 text-left text-xs uppercase text-slate-400">
                                <th className="px-4 py-3">Entreprise</th>
                                <th className="px-4 py-3">Plan</th>
                                <th className="px-4 py-3">Cycle</th>
                                <th className="px-4 py-3">Abonnement</th>
                                <th className="px-4 py-3">Échéance</th>
                                <th className="px-4 py-3">Montant</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {data.tenants.map((t) => {
                                const st = STATUT[t.effective_status] ?? STATUT.actif;
                                return (
                                    <Fragment key={t.id}>
                                        <tr className={t.effective_status === 'en_retard' ? 'bg-red-50/40' : ''}>
                                            <td className="px-4 py-3">
                                                <button
                                                    onClick={() => setExpanded(expanded === t.id ? null : t.id)}
                                                    className="font-medium text-slate-800 hover:text-emerald-600"
                                                >
                                                    {expanded === t.id ? '▾' : '▸'} {t.name}
                                                </button>
                                                <div className="text-xs text-slate-400">{t.users_count} utilisateur(s)</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <select
                                                    value={t.plan}
                                                    onChange={(e) => maj.mutate({ id: t.id, plan: e.target.value })}
                                                    className="rounded border border-slate-200 px-2 py-1 text-xs"
                                                >
                                                    {data.plans.map((p) => (
                                                        <option key={p.value} value={p.value}>{p.label}</option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="px-4 py-3">
                                                <select
                                                    value={t.billing_cycle}
                                                    onChange={(e) => maj.mutate({ id: t.id, billing_cycle: e.target.value })}
                                                    className="rounded border border-slate-200 px-2 py-1 text-xs"
                                                >
                                                    <option value="mensuel">Mensuel</option>
                                                    <option value="annuel">Annuel</option>
                                                </select>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${st.cls}`}>
                                                    {st.label}
                                                </span>
                                            </td>
                                            <td className={`px-4 py-3 ${t.effective_status === 'en_retard' ? 'font-medium text-red-600' : 'text-slate-600'}`}>
                                                {t.next_due ? new Date(t.next_due).toLocaleDateString('fr-MA') : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {formatMAD(t.subscription_amount)}
                                                <span className="text-xs text-slate-400">/{t.billing_cycle === 'annuel' ? 'an' : 'mois'}</span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <button
                                                    onClick={() => suspendre.mutate({ id: t.id, suspend: !t.suspended })}
                                                    className={`text-xs hover:underline ${t.suspended ? 'text-emerald-600' : 'text-red-600'}`}
                                                >
                                                    {t.suspended ? 'Réactiver' : 'Suspendre'}
                                                </button>
                                            </td>
                                        </tr>
                                        {expanded === t.id && (
                                            <TenantDetail tenant={t} methods={data.methods} colSpan={7} />
                                        )}
                                    </Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
