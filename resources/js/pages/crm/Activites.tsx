import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Activite, ActiviteType, Paginated, Tiers } from '@/types';

const TYPES: Record<ActiviteType, { label: string; icon: string }> = {
    appel: { label: 'Appel', icon: '📞' },
    email: { label: 'Email', icon: '✉️' },
    reunion: { label: 'Réunion', icon: '🤝' },
    note: { label: 'Note', icon: '📝' },
    tache: { label: 'Tâche', icon: '⏰' },
};

export default function Activites() {
    const queryClient = useQueryClient();
    const [vue, setVue] = useState<'a_faire' | 'tout'>('a_faire');
    const [showForm, setShowForm] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [form, setForm] = useState({ type: 'appel', tiers_id: '', sujet: '', note: '', date_prevue: '' });

    const { data, isLoading } = useQuery({
        queryKey: ['crm-activites', { vue }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Activite>>('/crm/activites', {
                params: vue === 'a_faire' ? { a_faire: 1 } : {},
            });
            return data.data;
        },
    });

    const { data: tiers } = useQuery({
        queryKey: ['tiers-options-crm'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tiers>>('/tiers', { params: { per_page: 300 } });
            return data.data;
        },
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['crm-activites'] });
    const onError = (err: any) => {
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
    };

    const creer = useMutation({
        mutationFn: () =>
            api.post('/crm/activites', {
                type: form.type,
                tiers_id: parseInt(form.tiers_id, 10),
                sujet: form.sujet,
                note: form.note || null,
                date_prevue: form.date_prevue || null,
                // Sans échéance = interaction déjà réalisée ; avec échéance = tâche à faire.
                fait: form.date_prevue === '',
            }),
        onSuccess: () => {
            setError(null);
            setShowForm(false);
            setForm({ type: 'appel', tiers_id: '', sujet: '', note: '', date_prevue: '' });
            invalidate();
        },
        onError,
    });

    const basculer = useMutation({
        mutationFn: (id: number) => api.post(`/crm/activites/${id}/fait`),
        onSuccess: () => { setError(null); invalidate(); },
        onError,
    });

    const supprimer = useMutation({
        mutationFn: (id: number) => api.delete(`/crm/activites/${id}`),
        onSuccess: () => { setError(null); invalidate(); },
        onError,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        creer.mutate();
    };

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div className="flex rounded-lg border border-slate-200 bg-white p-1">
                    {([['a_faire', 'À faire'], ['tout', 'Tout l\'historique']] as const).map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setVue(key)}
                            className={`rounded-md px-4 py-1.5 text-sm font-medium transition ${
                                vue === key ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <button
                    onClick={() => setShowForm((v) => !v)}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + Activité
                </button>
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            {showForm && (
                <form onSubmit={submit} className="space-y-3 rounded-xl bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-end gap-3">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Type</label>
                            <select value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))} className={input}>
                                {Object.entries(TYPES).map(([value, { label, icon }]) => (
                                    <option key={value} value={value}>{icon} {label}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Tiers</label>
                            <select required value={form.tiers_id} onChange={(e) => setForm((f) => ({ ...f, tiers_id: e.target.value }))} className={input}>
                                <option value="">— Choisir —</option>
                                {tiers?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
                            </select>
                        </div>
                        <div className="flex-1" style={{ minWidth: 180 }}>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Sujet</label>
                            <input required value={form.sujet} onChange={(e) => setForm((f) => ({ ...f, sujet: e.target.value }))} className={`${input} w-full`} placeholder="Ex. Rappeler pour le devis" />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">
                                Échéance <span className="font-normal text-slate-400">(= à faire)</span>
                            </label>
                            <input type="date" value={form.date_prevue} onChange={(e) => setForm((f) => ({ ...f, date_prevue: e.target.value }))} className={input} />
                        </div>
                        <button type="submit" disabled={creer.isPending} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60">
                            {form.date_prevue ? 'Planifier' : 'Journaliser'}
                        </button>
                    </div>
                    <input value={form.note} onChange={(e) => setForm((f) => ({ ...f, note: e.target.value }))} className={`${input} w-full`} placeholder="Note (facultatif)" />
                </form>
            )}

            <div className="space-y-2">
                {isLoading && <div className="py-8 text-center text-slate-400">Chargement…</div>}
                {!isLoading && data?.length === 0 && (
                    <div className="rounded-xl bg-white py-10 text-center text-sm text-slate-400 shadow-sm">
                        {vue === 'a_faire' ? '✓ Rien à faire, vous êtes à jour !' : 'Aucune activité pour le moment.'}
                    </div>
                )}
                {data?.map((a) => (
                    <div
                        key={a.id}
                        className={`flex items-start gap-3 rounded-xl bg-white p-4 shadow-sm ${a.fait ? 'opacity-60' : ''}`}
                    >
                        <button
                            onClick={() => basculer.mutate(a.id)}
                            title={a.fait ? 'Marquer à faire' : 'Marquer fait'}
                            className={`mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-xs transition ${
                                a.fait ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 hover:border-emerald-500'
                            }`}
                        >
                            {a.fait ? '✓' : ''}
                        </button>
                        <div className="flex-1">
                            <div className="flex items-center gap-2">
                                <span className="text-sm">{TYPES[a.type].icon}</span>
                                <span className={`text-sm font-medium text-slate-900 ${a.fait ? 'line-through' : ''}`}>
                                    {a.sujet}
                                </span>
                                {a.en_retard && (
                                    <span className="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700">
                                        en retard
                                    </span>
                                )}
                            </div>
                            <div className="mt-0.5 text-xs text-slate-500">
                                {a.tiers}
                                {a.date_prevue && ` · échéance ${a.date_prevue}`}
                                {a.opportunite && ` · ${a.opportunite}`}
                            </div>
                            {a.note && <div className="mt-1 text-sm text-slate-600">{a.note}</div>}
                        </div>
                        <button
                            onClick={() => window.confirm('Supprimer cette activité ?') && supprimer.mutate(a.id)}
                            className="text-slate-300 transition hover:text-red-400"
                        >
                            ✕
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}
