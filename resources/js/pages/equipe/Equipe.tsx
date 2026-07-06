import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { EquipeData, EquipeInvitation, RoleOption } from '@/types';

function lienInvitation(token: string): string {
    return `${window.location.origin}/rejoindre/${token}`;
}

/** Petit bouton « copier le lien » avec retour visuel. */
function BoutonCopier({ token }: { token: string }) {
    const [copie, setCopie] = useState(false);
    const copier = async () => {
        await navigator.clipboard.writeText(lienInvitation(token));
        setCopie(true);
        setTimeout(() => setCopie(false), 1800);
    };
    return (
        <button
            onClick={copier}
            className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-50"
        >
            {copie ? '✓ Copié' : '📋 Copier le lien'}
        </button>
    );
}

export default function Equipe() {
    const queryClient = useQueryClient();
    const { user } = useAuth();
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('commercial');
    const [erreur, setErreur] = useState<string | null>(null);
    const [dernierLien, setDernierLien] = useState<EquipeInvitation | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['equipe'],
        queryFn: async () => (await api.get<{ data: EquipeData }>('/equipe')).data.data,
    });

    const rafraichir = () => queryClient.invalidateQueries({ queryKey: ['equipe'] });

    const inviter = useMutation({
        mutationFn: () => api.post('/equipe/invitations', { email, role }),
        onSuccess: (res) => {
            setErreur(null);
            setEmail('');
            setDernierLien(res.data.invitation);
            rafraichir();
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setErreur(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Invitation impossible.');
        },
    });

    const majUser = useMutation({
        mutationFn: (v: { id: number; role?: string; is_active?: boolean }) =>
            api.put(`/equipe/users/${v.id}`, { role: v.role, is_active: v.is_active }),
        onSuccess: rafraichir,
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            alert(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
        },
    });

    const supprimerUser = useMutation({
        mutationFn: (id: number) => api.delete(`/equipe/users/${id}`),
        onSuccess: rafraichir,
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            alert(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Suppression impossible.');
        },
    });

    const revoquer = useMutation({
        mutationFn: (id: number) => api.delete(`/equipe/invitations/${id}`),
        onSuccess: rafraichir,
    });

    const abonnement = useMutation({
        mutationFn: (v: { plan?: string; extra_seats?: number }) => api.put('/equipe/abonnement', v),
        onSuccess: rafraichir,
    });

    if (isLoading || !data) {
        return <div className="text-sm text-slate-400">Chargement…</div>;
    }

    const s = data.subscription;
    const pct = Math.min(100, Math.round((s.seats_used / Math.max(1, s.seat_limit)) * 100));
    const plein = s.seats_available === 0;

    return (
        <div className="max-w-4xl space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Équipe</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Invitez vos collaborateurs et gérez leurs droits d'accès.
                </p>
            </div>

            {/* Jauge de sièges */}
            <section className="rounded-xl bg-white p-6 shadow-sm">
                <div className="flex items-end justify-between">
                    <div>
                        <div className="text-sm font-medium text-slate-900">
                            Plan {s.plan_label}
                        </div>
                        <div className="mt-0.5 text-sm text-slate-500">
                            {s.seats_used} utilisateur(s) actif(s) sur {s.seat_limit} sièges
                            {s.pending_invitations > 0 && ` · ${s.pending_invitations} invitation(s) en attente`}
                        </div>
                    </div>
                    <div className="text-right text-sm text-slate-500">
                        {s.included_seats} inclus
                        {s.extra_seats > 0 && ` + ${s.extra_seats} extra`}
                    </div>
                </div>
                <div className="mt-3 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div
                        className={`h-full rounded-full transition-all ${plein ? 'bg-amber-500' : 'bg-emerald-600'}`}
                        style={{ width: `${pct}%` }}
                    />
                </div>
                {plein && (
                    <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Tous vos sièges sont occupés. Ajoutez des sièges supplémentaires
                        ({s.extra_seat_price} DH HT / mois chacun) ou passez à un plan supérieur pour inviter
                        davantage de collaborateurs.
                    </p>
                )}

                {/* Réservé au superadmin plateforme : ajuste plan + sièges extra (facturation). */}
                {user?.is_superadmin && (
                    <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md border border-dashed border-slate-300 p-3">
                        <div className="text-xs font-medium text-slate-500">Superadmin :</div>
                        <label className="text-xs text-slate-500">
                            Plan
                            <select
                                defaultValue={s.plan}
                                onChange={(e) => abonnement.mutate({ plan: e.target.value })}
                                className="ml-2 rounded border border-slate-300 px-2 py-1 text-sm"
                            >
                                {['free', 'essentiel', 'business', 'premium'].map((p) => (
                                    <option key={p} value={p}>{p}</option>
                                ))}
                            </select>
                        </label>
                        <label className="text-xs text-slate-500">
                            Sièges extra
                            <input
                                type="number"
                                min={0}
                                defaultValue={s.extra_seats}
                                onBlur={(e) => abonnement.mutate({ extra_seats: Number(e.target.value) })}
                                className="ml-2 w-20 rounded border border-slate-300 px-2 py-1 text-sm"
                            />
                        </label>
                    </div>
                )}
            </section>

            {/* Inviter */}
            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">Inviter un collaborateur</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Un lien d'invitation est généré : partagez-le à votre collègue, il choisira son mot de passe.
                </p>
                {erreur && (
                    <div className="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{erreur}</div>
                )}
                <div className="mt-4 flex flex-wrap items-end gap-3">
                    <div className="flex-1 min-w-[220px]">
                        <label className="mb-1 block text-xs font-medium text-slate-600">Email</label>
                        <input
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            placeholder="collaborateur@entreprise.ma"
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Rôle</label>
                        <select
                            value={role}
                            onChange={(e) => setRole(e.target.value)}
                            className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                        >
                            {data.roles.map((r: RoleOption) => (
                                <option key={r.value} value={r.value}>{r.label}</option>
                            ))}
                        </select>
                    </div>
                    <button
                        disabled={inviter.isPending || !email || plein}
                        onClick={() => inviter.mutate()}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                    >
                        Générer l'invitation
                    </button>
                </div>

                {dernierLien && (
                    <div className="mt-4 rounded-md border border-emerald-200 bg-emerald-50 p-3">
                        <div className="text-xs font-medium text-emerald-800">
                            Invitation créée pour {dernierLien.email}. Partagez ce lien :
                        </div>
                        <div className="mt-2 flex items-center gap-2">
                            <input
                                readOnly
                                value={lienInvitation(dernierLien.token)}
                                className="flex-1 rounded border border-emerald-300 bg-white px-2 py-1 text-xs text-slate-700"
                            />
                            <BoutonCopier token={dernierLien.token} />
                        </div>
                    </div>
                )}
            </section>

            {/* Membres */}
            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">Membres ({data.users.length})</h2>
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-100 text-left text-xs uppercase text-slate-400">
                                <th className="pb-2">Nom</th>
                                <th className="pb-2">Email</th>
                                <th className="pb-2">Rôle</th>
                                <th className="pb-2">Statut</th>
                                <th className="pb-2 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-50">
                            {data.users.map((u) => {
                                const moi = u.id === user?.id;
                                return (
                                    <tr key={u.id} className={u.is_active ? '' : 'opacity-50'}>
                                        <td className="py-3 font-medium text-slate-800">
                                            {u.name}
                                            {moi && <span className="ml-1 text-xs text-slate-400">(vous)</span>}
                                        </td>
                                        <td className="py-3 text-slate-600">{u.email}</td>
                                        <td className="py-3">
                                            <select
                                                value={u.role}
                                                disabled={u.is_superadmin}
                                                onChange={(e) => majUser.mutate({ id: u.id, role: e.target.value })}
                                                className="rounded border border-slate-200 px-2 py-1 text-xs"
                                            >
                                                {data.roles.map((r) => (
                                                    <option key={r.value} value={r.value}>{r.label}</option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="py-3">
                                            <button
                                                onClick={() => majUser.mutate({ id: u.id, is_active: !u.is_active })}
                                                disabled={moi}
                                                className={`rounded-full px-2.5 py-0.5 text-xs font-medium disabled:opacity-60 ${
                                                    u.is_active
                                                        ? 'bg-emerald-100 text-emerald-700'
                                                        : 'bg-slate-200 text-slate-600'
                                                }`}
                                            >
                                                {u.is_active ? 'Actif' : 'Désactivé'}
                                            </button>
                                        </td>
                                        <td className="py-3 text-right">
                                            <button
                                                onClick={() => {
                                                    if (confirm(`Supprimer ${u.name} ?`)) supprimerUser.mutate(u.id);
                                                }}
                                                disabled={moi}
                                                className="text-xs text-red-600 hover:underline disabled:opacity-40"
                                            >
                                                Supprimer
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </section>

            {/* Invitations en attente */}
            {data.invitations.length > 0 && (
                <section className="rounded-xl bg-white p-6 shadow-sm">
                    <h2 className="font-medium text-slate-900">
                        Invitations en attente ({data.invitations.length})
                    </h2>
                    <ul className="mt-4 divide-y divide-slate-50">
                        {data.invitations.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <div className="text-sm font-medium text-slate-800">{i.email}</div>
                                    <div className="text-xs text-slate-400">
                                        {i.role_label} · expire le {new Date(i.expires_at).toLocaleDateString('fr-MA')}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <BoutonCopier token={i.token} />
                                    <button
                                        onClick={() => revoquer.mutate(i.id)}
                                        className="text-xs text-red-600 hover:underline"
                                    >
                                        Révoquer
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
