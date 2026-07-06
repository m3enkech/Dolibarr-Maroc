import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Entrepot, Inventaire, Paginated, Produit } from '@/types';

const STATUT_BADGE: Record<string, string> = {
    brouillon: 'bg-slate-100 text-slate-600',
    valide: 'bg-emerald-100 text-emerald-700',
};

/* ------------------------------------------------------------------ */
/* Liste + création                                                    */
/* ------------------------------------------------------------------ */

export default function Inventaires({ entrepots }: { entrepots: Entrepot[] }) {
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [creating, setCreating] = useState(false);
    const [entrepotId, setEntrepotId] = useState('');
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['inventaires'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Inventaire>>('/stock/inventaires');
            return data.data;
        },
    });

    const creer = useMutation({
        mutationFn: async () => {
            const { data } = await api.post<{ data: Inventaire }>('/stock/inventaires', {
                entrepot_id: parseInt(entrepotId, 10),
                note: note || null,
            });
            return data.data;
        },
        onSuccess: (inventaire) => {
            setError(null);
            setCreating(false);
            setNote('');
            setEntrepotId('');
            queryClient.invalidateQueries({ queryKey: ['inventaires'] });
            setSelectedId(inventaire.id);
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Création impossible.');
        },
    });

    if (selectedId !== null) {
        return <InventaireDetail id={selectedId} onBack={() => setSelectedId(null)} />;
    }

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <p className="text-sm text-slate-500">
                    Un inventaire fige les quantités théoriques d'un entrepôt ; la validation génère les
                    ajustements de stock.
                </p>
                <button
                    onClick={() => setCreating((v) => !v)}
                    disabled={entrepots.length === 0}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                >
                    + Nouvel inventaire
                </button>
            </div>

            {creating && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        creer.mutate();
                    }}
                    className="flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 shadow-sm"
                >
                    {error && (
                        <div className="w-full rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
                    )}
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Entrepôt à inventorier</label>
                        <select required value={entrepotId} onChange={(e) => setEntrepotId(e.target.value)} className={input}>
                            <option value="">— Choisir —</option>
                            {entrepots.map((entrepot) => (
                                <option key={entrepot.id} value={entrepot.id}>
                                    {entrepot.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex-1">
                        <label className="mb-1 block text-xs font-medium text-slate-600">Note</label>
                        <input value={note} onChange={(e) => setNote(e.target.value)} className={`${input} w-full`} />
                    </div>
                    <button
                        type="submit"
                        disabled={creer.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        Démarrer le comptage
                    </button>
                </form>
            )}

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Entrepôt</th>
                            <th className="px-4 py-3">Statut</th>
                            <th className="px-4 py-3">Date</th>
                            <th className="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-slate-400">Chargement…</td>
                            </tr>
                        )}
                        {!isLoading && data?.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-10 text-center text-slate-400">
                                    Aucun inventaire. Démarrez-en un pour compter votre stock physique.
                                </td>
                            </tr>
                        )}
                        {data?.map((inventaire) => (
                            <tr key={inventaire.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{inventaire.code}</td>
                                <td className="px-4 py-3 text-slate-700">{inventaire.entrepot?.name ?? '—'}</td>
                                <td className="px-4 py-3">
                                    <span className={`rounded px-1.5 py-0.5 text-xs ${STATUT_BADGE[inventaire.statut]}`}>
                                        {inventaire.statut === 'valide' ? 'Validé' : 'Brouillon'}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-slate-500">
                                    {new Date(inventaire.created_at).toLocaleDateString('fr-FR')}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <button
                                        onClick={() => setSelectedId(inventaire.id)}
                                        className="text-sm font-medium text-emerald-600 hover:underline"
                                    >
                                        {inventaire.statut === 'valide' ? 'Consulter' : 'Compter →'}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Détail : saisie du comptage + validation                            */
/* ------------------------------------------------------------------ */

function InventaireDetail({ id, onBack }: { id: number; onBack: () => void }) {
    const [counts, setCounts] = useState<Record<number, string>>({});
    const [extra, setExtra] = useState<{ id: number; code: string; name: string; unit: string | null }[]>([]);
    const [addProduitId, setAddProduitId] = useState('');
    const [error, setError] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const { data: inventaire, isLoading } = useQuery({
        queryKey: ['inventaire', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Inventaire }>(`/stock/inventaires/${id}`);
            return data.data;
        },
    });

    const readonly = inventaire?.statut === 'valide';

    // Initialise les quantités comptées à partir des lignes chargées.
    useEffect(() => {
        if (inventaire?.lignes) {
            const initial: Record<number, string> = {};
            for (const ligne of inventaire.lignes) {
                initial[ligne.produit_id] = ligne.quantite_comptee !== null ? String(parseFloat(ligne.quantite_comptee)) : '';
            }
            setCounts(initial);
        }
    }, [inventaire]);

    const { data: produits } = useQuery({
        queryKey: ['produits-stock-options'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Produit>>('/produits', {
                params: { type: 'product', per_page: 200 },
            });
            return data.data;
        },
        enabled: !readonly,
    });

    const comptagesPayload = () => {
        const ids = new Set<number>([...(inventaire?.lignes?.map((l) => l.produit_id) ?? []), ...extra.map((e) => e.id)]);
        return [...ids].map((produitId) => ({
            produit_id: produitId,
            quantite_comptee: counts[produitId] === '' || counts[produitId] === undefined ? null : parseFloat(counts[produitId]),
        }));
    };

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['inventaire', id] });
        queryClient.invalidateQueries({ queryKey: ['inventaires'] });
        queryClient.invalidateQueries({ queryKey: ['stock-niveaux'] });
        queryClient.invalidateQueries({ queryKey: ['stock-mouvements'] });
    };

    const onError = (err: any) => {
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
    };

    const enregistrer = useMutation({
        mutationFn: () => api.put(`/stock/inventaires/${id}`, { comptages: comptagesPayload() }),
        onSuccess: () => {
            setError(null);
            setExtra([]);
            invalidate();
        },
        onError,
    });

    const valider = useMutation({
        mutationFn: async () => {
            await api.put(`/stock/inventaires/${id}`, { comptages: comptagesPayload() });
            return api.post(`/stock/inventaires/${id}/valider`);
        },
        onSuccess: () => {
            setError(null);
            setExtra([]);
            invalidate();
        },
        onError,
    });

    const supprimer = useMutation({
        mutationFn: () => api.delete(`/stock/inventaires/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['inventaires'] });
            onBack();
        },
        onError,
    });

    // Produits pas encore dans l'inventaire (pour ajouter un article trouvé).
    const produitsDisponibles = useMemo(() => {
        const presents = new Set<number>([
            ...(inventaire?.lignes?.map((l) => l.produit_id) ?? []),
            ...extra.map((e) => e.id),
        ]);
        return (produits ?? []).filter((p) => !presents.has(p.id));
    }, [produits, inventaire, extra]);

    if (isLoading || !inventaire) {
        return <div className="py-8 text-center text-slate-400">Chargement…</div>;
    }

    const rows = [
        ...(inventaire.lignes ?? []).map((l) => ({
            produit_id: l.produit_id,
            code: l.produit?.code ?? '—',
            name: l.produit?.name ?? '—',
            unit: l.produit?.unit ?? null,
            theorique: parseFloat(l.quantite_theorique),
        })),
        ...extra.map((e) => ({ produit_id: e.id, code: e.code, name: e.name, unit: e.unit, theorique: 0 })),
    ];

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <button onClick={onBack} className="text-sm text-emerald-600 hover:underline">
                        ← Retour aux inventaires
                    </button>
                    <h2 className="mt-1 flex items-center gap-2 text-lg font-semibold text-slate-900">
                        <span className="font-mono">{inventaire.code}</span>
                        <span className={`rounded px-1.5 py-0.5 text-xs ${STATUT_BADGE[inventaire.statut]}`}>
                            {readonly ? 'Validé' : 'Brouillon'}
                        </span>
                    </h2>
                    <p className="text-sm text-slate-500">{inventaire.entrepot?.name}</p>
                </div>
                {!readonly && (
                    <div className="flex gap-2">
                        <button
                            onClick={() => window.confirm('Supprimer cet inventaire ?') && supprimer.mutate()}
                            className="rounded-md border border-slate-300 px-3 py-2 text-sm text-red-600 transition hover:bg-red-50"
                        >
                            Supprimer
                        </button>
                        <button
                            onClick={() => enregistrer.mutate()}
                            disabled={enregistrer.isPending}
                            className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                        >
                            {enregistrer.isPending ? 'Enregistrement…' : 'Enregistrer le comptage'}
                        </button>
                        <button
                            onClick={() => window.confirm('Valider et ajuster le stock ?') && valider.mutate()}
                            disabled={valider.isPending}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                        >
                            {valider.isPending ? 'Validation…' : 'Valider l\'inventaire'}
                        </button>
                    </div>
                )}
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Produit</th>
                            <th className="px-4 py-3 text-right">Théorique</th>
                            <th className="px-4 py-3 text-right">Compté</th>
                            <th className="px-4 py-3 text-right">Écart</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-10 text-center text-slate-400">
                                    Aucun stock recensé dans cet entrepôt.
                                    {!readonly && ' Ajoutez les produits comptés ci-dessous.'}
                                </td>
                            </tr>
                        )}
                        {rows.map((row) => {
                            const val = counts[row.produit_id] ?? '';
                            const ecart = val === '' ? null : Math.round((parseFloat(val) - row.theorique) * 1000) / 1000;
                            return (
                                <tr key={row.produit_id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{row.code}</td>
                                    <td className="px-4 py-3 font-medium text-slate-900">
                                        {row.name}
                                        {row.unit && <span className="ml-1 text-xs text-slate-400">/ {row.unit}</span>}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-500">{row.theorique}</td>
                                    <td className="px-4 py-3 text-right">
                                        {readonly ? (
                                            <span className="tabular-nums text-slate-900">{val === '' ? '—' : parseFloat(val)}</span>
                                        ) : (
                                            <input
                                                type="number"
                                                step="0.001"
                                                min="0"
                                                value={val}
                                                onChange={(e) =>
                                                    setCounts((c) => ({ ...c, [row.produit_id]: e.target.value }))
                                                }
                                                className={`${input} w-28 text-right`}
                                                placeholder="—"
                                            />
                                        )}
                                    </td>
                                    <td
                                        className={`px-4 py-3 text-right font-semibold tabular-nums ${
                                            ecart === null || ecart === 0
                                                ? 'text-slate-400'
                                                : ecart > 0
                                                  ? 'text-emerald-700'
                                                  : 'text-red-600'
                                        }`}
                                    >
                                        {ecart === null ? '—' : `${ecart > 0 ? '+' : ''}${ecart}`}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {!readonly && produitsDisponibles.length > 0 && (
                <div className="flex items-end gap-3 rounded-xl bg-white p-4 shadow-sm">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">
                            Ajouter un produit trouvé
                        </label>
                        <select value={addProduitId} onChange={(e) => setAddProduitId(e.target.value)} className={input}>
                            <option value="">— Choisir —</option>
                            {produitsDisponibles.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name} ({p.code})
                                </option>
                            ))}
                        </select>
                    </div>
                    <button
                        onClick={() => {
                            const p = produitsDisponibles.find((x) => String(x.id) === addProduitId);
                            if (p) {
                                setExtra((e) => [...e, { id: p.id, code: p.code, name: p.name, unit: p.unit }]);
                                setAddProduitId('');
                            }
                        }}
                        disabled={!addProduitId}
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-40"
                    >
                        Ajouter
                    </button>
                </div>
            )}
        </div>
    );
}
