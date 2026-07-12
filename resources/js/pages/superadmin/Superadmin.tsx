import { Fragment, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Navigate } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatMAD } from '@/lib/format';
import type { EquipeUser, SuperadminData } from '@/types';

function StatCard({ label, value, tone = 'slate' }: { label: string; value: string; tone?: string }) {
    return (
        <div className="rounded-xl bg-white p-4 shadow-sm">
            <div className="text-xs text-slate-500">{label}</div>
            <div className={`mt-1 text-2xl font-semibold text-${tone}-700`}>{value}</div>
        </div>
    );
}

/** Ligne dépliable listant les utilisateurs d'une entreprise. */
function UsersRow({ tenantId, colSpan }: { tenantId: number; colSpan: number }) {
    const { data, isLoading } = useQuery({
        queryKey: ['superadmin', 'tenant', tenantId],
        queryFn: async () =>
            (await api.get<{ data: { users: EquipeUser[] } }>(`/superadmin/tenants/${tenantId}`)).data.data.users,
    });

    return (
        <tr className="bg-slate-50/60">
            <td colSpan={colSpan} className="px-4 py-3">
                {isLoading ? (
                    <span className="text-xs text-slate-400">Chargement…</span>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {data?.map((u) => (
                            <span
                                key={u.id}
                                className={`rounded-full px-2.5 py-1 text-xs ${
                                    u.is_active ? 'bg-white text-slate-700' : 'bg-slate-200 text-slate-500 line-through'
                                } border border-slate-200`}
                            >
                                {u.name} · {u.role_label} · {u.email}
                                {u.is_superadmin && ' ⭐'}
                            </span>
                        ))}
                    </div>
                )}
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

    const rafraichir = () => queryClient.invalidateQueries({ queryKey: ['superadmin', 'tenants'] });

    const majPlan = useMutation({
        mutationFn: (v: { id: number; plan?: string; extra_seats?: number }) =>
            api.put(`/superadmin/tenants/${v.id}`, { plan: v.plan, extra_seats: v.extra_seats }),
        onSuccess: rafraichir,
    });

    const suspendre = useMutation({
        mutationFn: (v: { id: number; suspend: boolean }) =>
            api.post(`/superadmin/tenants/${v.id}/${v.suspend ? 'suspend' : 'reactivate'}`),
        onSuccess: rafraichir,
        onError: (err: any) => {
            const messages = err?.response?.data?.errors ?? err?.response?.data?.message;
            alert(typeof messages === 'string' ? messages : 'Action impossible.');
        },
    });

    // Garde-fou : réservé au superadmin plateforme.
    if (user && !user.is_superadmin) {
        return <Navigate to="/dashboard" replace />;
    }
    if (isLoading || !data) {
        return <div className="text-sm text-slate-400">Chargement…</div>;
    }

    const s = data.stats;

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Administration plateforme</h1>
                <p className="mt-1 text-sm text-slate-500">Toutes les entreprises clientes de la plateforme.</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <StatCard label="Entreprises" value={`${s.tenants_total}`} tone="emerald" />
                <StatCard label="Actives" value={`${s.tenants_active}`} />
                <StatCard label="Suspendues" value={`${s.tenants_suspended}`} tone={s.tenants_suspended ? 'red' : 'slate'} />
                <StatCard label="Utilisateurs" value={`${s.users_active}/${s.users_total}`} />
                <StatCard label="MRR estimé" value={formatMAD(s.mrr_estimated)} tone="emerald" />
            </div>

            <section className="rounded-xl bg-white shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-100 text-left text-xs uppercase text-slate-400">
                                <th className="px-4 py-3">Entreprise</th>
                                <th className="px-4 py-3">Plan</th>
                                <th className="px-4 py-3">Sièges extra</th>
                                <th className="px-4 py-3">Sièges</th>
                                <th className="px-4 py-3">Abo. estimé</th>
                                <th className="px-4 py-3">Statut</th>
                                <th className="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {data.tenants.map((t) => (
                                <Fragment key={t.id}>
                                    <tr className={t.suspended ? 'bg-red-50/40' : ''}>
                                        <td className="px-4 py-3">
                                            <button
                                                onClick={() => setExpanded(expanded === t.id ? null : t.id)}
                                                className="font-medium text-slate-800 hover:text-emerald-600"
                                            >
                                                {expanded === t.id ? '▾' : '▸'} {t.name}
                                            </button>
                                            <div className="text-xs text-slate-400">
                                                {t.users_count} utilisateur(s) · créée le{' '}
                                                {new Date(t.created_at).toLocaleDateString('fr-MA')}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <select
                                                value={t.plan}
                                                onChange={(e) => majPlan.mutate({ id: t.id, plan: e.target.value })}
                                                className="rounded border border-slate-200 px-2 py-1 text-xs"
                                            >
                                                {data.plans.map((p) => (
                                                    <option key={p.value} value={p.value}>{p.label}</option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="px-4 py-3">
                                            <input
                                                type="number"
                                                min={0}
                                                defaultValue={t.extra_seats}
                                                onBlur={(e) => {
                                                    const v = Number(e.target.value);
                                                    if (v !== t.extra_seats) majPlan.mutate({ id: t.id, extra_seats: v });
                                                }}
                                                className="w-16 rounded border border-slate-200 px-2 py-1 text-xs"
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">
                                            {t.seats_used} / {t.seat_limit}
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">{formatMAD(t.estimated_monthly)}</td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                    t.suspended ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'
                                                }`}
                                            >
                                                {t.suspended ? 'Suspendue' : 'Active'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <button
                                                onClick={() => suspendre.mutate({ id: t.id, suspend: !t.suspended })}
                                                className={`text-xs hover:underline ${
                                                    t.suspended ? 'text-emerald-600' : 'text-red-600'
                                                }`}
                                            >
                                                {t.suspended ? 'Réactiver' : 'Suspendre'}
                                            </button>
                                        </td>
                                    </tr>
                                    {expanded === t.id && <UsersRow tenantId={t.id} colSpan={7} />}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
