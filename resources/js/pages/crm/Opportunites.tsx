import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { Opportunite, OpportuniteEtape, Paginated, PipelineBoard, Tiers } from '@/types';

const ETAPES: { key: OpportuniteEtape; label: string; accent: string }[] = [
    { key: 'nouveau', label: 'Nouveau', accent: 'border-t-slate-400' },
    { key: 'qualifie', label: 'Qualifié', accent: 'border-t-sky-400' },
    { key: 'proposition', label: 'Proposition', accent: 'border-t-violet-400' },
    { key: 'negociation', label: 'Négociation', accent: 'border-t-amber-400' },
];

const ETAPE_INDEX = ETAPES.reduce((acc, e, i) => ({ ...acc, [e.key]: i }), {} as Record<string, number>);

export default function Opportunites() {
    const queryClient = useQueryClient();
    const [showForm, setShowForm] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [dragId, setDragId] = useState<number | null>(null);
    const [form, setForm] = useState({ tiers_id: '', titre: '', montant_estime: '', probabilite: '50' });

    const { data: board, isLoading } = useQuery({
        queryKey: ['crm-pipeline'],
        queryFn: async () => {
            const { data } = await api.get<PipelineBoard>('/crm/opportunites');
            return data;
        },
    });

    const { data: tiers } = useQuery({
        queryKey: ['tiers-options-crm'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tiers>>('/tiers', { params: { per_page: 300 } });
            return data.data;
        },
    });

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['crm-pipeline'] });
    const onError = (err: any) => {
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
    };

    const creer = useMutation({
        mutationFn: () =>
            api.post('/crm/opportunites', {
                tiers_id: parseInt(form.tiers_id, 10),
                titre: form.titre,
                montant_estime: parseFloat(form.montant_estime || '0'),
                probabilite: parseInt(form.probabilite || '50', 10),
            }),
        onSuccess: () => {
            setError(null);
            setShowForm(false);
            setForm({ tiers_id: '', titre: '', montant_estime: '', probabilite: '50' });
            invalidate();
        },
        onError,
    });

    const deplacer = useMutation({
        mutationFn: ({ id, etape }: { id: number; etape: OpportuniteEtape }) =>
            api.post(`/crm/opportunites/${id}/deplacer`, { etape }),
        onSuccess: () => { setError(null); invalidate(); },
        onError,
    });

    const cloturer = useMutation({
        mutationFn: ({ id, statut }: { id: number; statut: 'gagnee' | 'perdue' }) =>
            api.post(`/crm/opportunites/${id}/cloturer`, { statut }),
        onSuccess: () => { setError(null); invalidate(); },
        onError,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        creer.mutate();
    };

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    const colTotal = (opps: Opportunite[]) => opps.reduce((s, o) => s + parseFloat(o.montant_estime), 0);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-slate-500">
                    Faites glisser les opportunités d'une étape à l'autre, du prospect à l'affaire gagnée
                </p>
                <div className="flex items-center gap-4">
                    {board && (
                        <div className="hidden gap-4 sm:flex">
                            <Stat label="Pipeline" value={formatMAD(board.stats.total_pipeline)} />
                            <Stat label="Prévision pondérée" value={formatMAD(board.stats.forecast_pondere)} accent />
                        </div>
                    )}
                    <button
                        onClick={() => setShowForm((v) => !v)}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                    >
                        + Opportunité
                    </button>
                </div>
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            {showForm && (
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 shadow-sm">
                    <div className="flex-1" style={{ minWidth: 200 }}>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Intitulé</label>
                        <input required value={form.titre} onChange={(e) => setForm((f) => ({ ...f, titre: e.target.value }))} className={`${input} w-full`} placeholder="Ex. Refonte site web" />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Client / prospect</label>
                        <select required value={form.tiers_id} onChange={(e) => setForm((f) => ({ ...f, tiers_id: e.target.value }))} className={input}>
                            <option value="">— Choisir —</option>
                            {tiers?.map((t) => (
                                <option key={t.id} value={t.id}>{t.name}</option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Montant estimé</label>
                        <input type="number" step="0.01" min="0" value={form.montant_estime} onChange={(e) => setForm((f) => ({ ...f, montant_estime: e.target.value }))} className={`${input} w-32`} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Probabilité %</label>
                        <input type="number" min="0" max="100" value={form.probabilite} onChange={(e) => setForm((f) => ({ ...f, probabilite: e.target.value }))} className={`${input} w-24`} />
                    </div>
                    <button type="submit" disabled={creer.isPending} className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60">
                        Créer
                    </button>
                </form>
            )}

            {isLoading && <div className="py-10 text-center text-slate-400">Chargement…</div>}

            {board && (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {ETAPES.map((etape) => {
                        const opps = board.colonnes[etape.key] ?? [];
                        return (
                            <div
                                key={etape.key}
                                onDragOver={(e) => e.preventDefault()}
                                onDrop={() => {
                                    if (dragId !== null) deplacer.mutate({ id: dragId, etape: etape.key });
                                    setDragId(null);
                                }}
                                className={`flex flex-col rounded-xl border-t-4 bg-slate-50 ${etape.accent}`}
                            >
                                <div className="flex items-center justify-between px-3 py-2.5">
                                    <span className="text-sm font-semibold text-slate-700">{etape.label}</span>
                                    <span className="text-xs text-slate-400">
                                        {opps.length} · {formatMAD(colTotal(opps))}
                                    </span>
                                </div>
                                <div className="flex-1 space-y-2 px-2 pb-3" style={{ minHeight: 120 }}>
                                    {opps.map((opp) => (
                                        <Carte
                                            key={opp.id}
                                            opp={opp}
                                            onDragStart={() => setDragId(opp.id)}
                                            canLeft={ETAPE_INDEX[opp.etape] > 0}
                                            canRight={ETAPE_INDEX[opp.etape] < ETAPES.length - 1}
                                            onMove={(dir) =>
                                                deplacer.mutate({
                                                    id: opp.id,
                                                    etape: ETAPES[ETAPE_INDEX[opp.etape] + dir].key,
                                                })
                                            }
                                            onCloturer={(statut) => cloturer.mutate({ id: opp.id, statut })}
                                        />
                                    ))}
                                    {opps.length === 0 && (
                                        <div className="rounded-lg border border-dashed border-slate-200 py-6 text-center text-xs text-slate-400">
                                            Glissez ici
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function Stat({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
    return (
        <div className="text-right">
            <div className="text-[10px] uppercase tracking-wide text-slate-400">{label}</div>
            <div className={`text-lg font-bold tabular-nums ${accent ? 'text-emerald-600' : 'text-slate-900'}`}>{value}</div>
        </div>
    );
}

function Carte({
    opp,
    onDragStart,
    canLeft,
    canRight,
    onMove,
    onCloturer,
}: {
    opp: Opportunite;
    onDragStart: () => void;
    canLeft: boolean;
    canRight: boolean;
    onMove: (dir: number) => void;
    onCloturer: (statut: 'gagnee' | 'perdue') => void;
}) {
    return (
        <div
            draggable
            onDragStart={onDragStart}
            className="cursor-grab rounded-lg border border-slate-200 bg-white p-3 shadow-sm transition hover:shadow-md active:cursor-grabbing"
        >
            <div className="flex items-start justify-between gap-2">
                <span className="text-sm font-medium text-slate-900">{opp.titre}</span>
                <span className="shrink-0 rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">
                    {opp.probabilite}%
                </span>
            </div>
            <div className="mt-1 text-xs text-slate-500">{opp.tiers}</div>
            <div className="mt-2 font-semibold tabular-nums text-slate-800">{formatMAD(opp.montant_estime)}</div>

            <div className="mt-2 flex items-center justify-between border-t border-slate-100 pt-2">
                <div className="flex gap-1">
                    <button
                        disabled={!canLeft}
                        onClick={() => onMove(-1)}
                        className="rounded px-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30"
                        title="Étape précédente"
                    >
                        ‹
                    </button>
                    <button
                        disabled={!canRight}
                        onClick={() => onMove(1)}
                        className="rounded px-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 disabled:opacity-30"
                        title="Étape suivante"
                    >
                        ›
                    </button>
                </div>
                <div className="flex gap-1">
                    <button
                        onClick={() => onCloturer('gagnee')}
                        className="rounded px-1.5 py-0.5 text-[11px] font-medium text-emerald-600 transition hover:bg-emerald-50"
                        title="Marquer gagnée"
                    >
                        ✓ Gagné
                    </button>
                    <button
                        onClick={() => onCloturer('perdue')}
                        className="rounded px-1.5 py-0.5 text-[11px] font-medium text-red-500 transition hover:bg-red-50"
                        title="Marquer perdue"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </div>
    );
}
