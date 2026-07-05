import { useState, type FormEvent } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { Entrepot, MouvementStock, Paginated, Produit, StockNiveau } from '@/types';

const TYPES_MOUVEMENT: Record<string, string> = {
    entree: 'Entrée',
    sortie: 'Sortie',
    ajustement: 'Ajustement (quantité cible)',
};

export default function StockNiveaux({ entrepots }: { entrepots: Entrepot[] }) {
    const [search, setSearch] = useState('');
    const [entrepotId, setEntrepotId] = useState('');
    const [page, setPage] = useState(1);
    const [showForm, setShowForm] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [produitId, setProduitId] = useState('');
    const [formEntrepotId, setFormEntrepotId] = useState('');
    const [type, setType] = useState('entree');
    const [quantite, setQuantite] = useState('');
    const [note, setNote] = useState('');

    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['stock-niveaux', { search, entrepotId, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<StockNiveau>>('/stock/niveaux', {
                params: { search: search || undefined, entrepot_id: entrepotId || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    const { data: produits } = useQuery({
        queryKey: ['produits-stock-options'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Produit>>('/produits', {
                params: { type: 'product', per_page: 200 },
            });
            return data.data;
        },
    });

    const mutation = useMutation({
        mutationFn: (payload: Record<string, unknown>) =>
            api.post<{ data: MouvementStock }>('/stock/mouvements', payload),
        onSuccess: () => {
            setError(null);
            setQuantite('');
            setNote('');
            queryClient.invalidateQueries({ queryKey: ['stock-niveaux'] });
            queryClient.invalidateQueries({ queryKey: ['stock-mouvements'] });
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setError(
                messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Mouvement impossible.',
            );
        },
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        mutation.mutate({
            produit_id: parseInt(produitId, 10),
            entrepot_id: parseInt(formEntrepotId, 10),
            type,
            quantite: parseFloat(quantite),
            note: note || null,
        });
    };

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <input
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Rechercher un produit…"
                    className={`${input} w-64`}
                />
                <select
                    value={entrepotId}
                    onChange={(e) => {
                        setEntrepotId(e.target.value);
                        setPage(1);
                    }}
                    className={input}
                >
                    <option value="">Tous les entrepôts</option>
                    {entrepots.map((entrepot) => (
                        <option key={entrepot.id} value={entrepot.id}>
                            {entrepot.name}
                        </option>
                    ))}
                </select>
                <button
                    onClick={() => setShowForm((v) => !v)}
                    className="ml-auto rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    ± Mouvement
                </button>
            </div>

            {showForm && (
                <form onSubmit={handleSubmit} className="rounded-xl bg-white p-5 shadow-sm">
                    {error && (
                        <div className="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
                    )}
                    <div className="flex flex-wrap items-end gap-3">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Produit</label>
                            <select required value={produitId} onChange={(e) => setProduitId(e.target.value)} className={`${input} w-56`}>
                                <option value="">— Choisir —</option>
                                {produits?.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Entrepôt</label>
                            <select required value={formEntrepotId} onChange={(e) => setFormEntrepotId(e.target.value)} className={input}>
                                <option value="">— Choisir —</option>
                                {entrepots.map((entrepot) => (
                                    <option key={entrepot.id} value={entrepot.id}>
                                        {entrepot.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Type</label>
                            <select value={type} onChange={(e) => setType(e.target.value)} className={input}>
                                {Object.entries(TYPES_MOUVEMENT).map(([value, labelText]) => (
                                    <option key={value} value={value}>
                                        {labelText}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">
                                {type === 'ajustement' ? 'Quantité constatée' : 'Quantité'}
                            </label>
                            <input
                                type="number"
                                step="0.001"
                                min="0"
                                required
                                value={quantite}
                                onChange={(e) => setQuantite(e.target.value)}
                                className={`${input} w-32`}
                            />
                        </div>
                        <div className="flex-1">
                            <label className="mb-1 block text-xs font-medium text-slate-600">Note</label>
                            <input value={note} onChange={(e) => setNote(e.target.value)} className={`${input} w-full`} />
                        </div>
                        <button
                            type="submit"
                            disabled={mutation.isPending}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                        >
                            Enregistrer
                        </button>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Produit</th>
                            <th className="px-4 py-3 text-right">Quantité</th>
                            <th className="px-4 py-3 text-right">Valeur d'achat</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={4} className="px-4 py-8 text-center text-slate-400">Chargement…</td>
                            </tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={4} className="px-4 py-8 text-center text-slate-400">
                                    Aucun produit physique au catalogue.
                                </td>
                            </tr>
                        )}
                        {data?.data.map((niveau) => {
                            const quantiteNum = parseFloat(niveau.quantite);
                            return (
                                <tr key={niveau.produit_id} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{niveau.code}</td>
                                    <td className="px-4 py-3 font-medium text-slate-900">
                                        {niveau.name}
                                        {niveau.unit && <span className="ml-1 text-xs text-slate-400">/ {niveau.unit}</span>}
                                    </td>
                                    <td
                                        className={`px-4 py-3 text-right font-semibold tabular-nums ${
                                            quantiteNum < 0
                                                ? 'text-red-600'
                                                : quantiteNum === 0
                                                  ? 'text-slate-400'
                                                  : 'text-slate-900'
                                        }`}
                                    >
                                        {quantiteNum}
                                        {quantiteNum < 0 && (
                                            <span className="ml-2 rounded bg-red-100 px-1.5 py-0.5 text-xs font-normal text-red-700">
                                                rupture
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                        {niveau.valeur_achat !== null ? formatMAD(niveau.valeur_achat) : '—'}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                {data && data.meta.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                        <span className="text-slate-500">
                            Page {data.meta.current_page} / {data.meta.last_page} — {data.meta.total} produits
                        </span>
                        <div className="space-x-2">
                            <button
                                disabled={page <= 1}
                                onClick={() => setPage((p) => p - 1)}
                                className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40"
                            >
                                Précédent
                            </button>
                            <button
                                disabled={page >= data.meta.last_page}
                                onClick={() => setPage((p) => p + 1)}
                                className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40"
                            >
                                Suivant
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
