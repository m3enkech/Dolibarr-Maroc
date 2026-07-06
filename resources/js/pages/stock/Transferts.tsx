import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Entrepot, Paginated, Produit } from '@/types';

interface TransfertResult {
    reference: string;
    sortie: { entrepot: { name: string } | null };
    entree: { entrepot: { name: string } | null };
}

export default function Transferts({ entrepots }: { entrepots: Entrepot[] }) {
    const [produitId, setProduitId] = useState('');
    const [sourceId, setSourceId] = useState('');
    const [destId, setDestId] = useState('');
    const [quantite, setQuantite] = useState('');
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [dernier, setDernier] = useState<TransfertResult | null>(null);

    const queryClient = useQueryClient();

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
        mutationFn: async () => {
            const { data } = await api.post<TransfertResult>('/stock/transferts', {
                produit_id: parseInt(produitId, 10),
                entrepot_source_id: parseInt(sourceId, 10),
                entrepot_dest_id: parseInt(destId, 10),
                quantite: parseFloat(quantite),
                note: note || null,
            });
            return data;
        },
        onSuccess: (data) => {
            setError(null);
            setDernier(data);
            setQuantite('');
            setNote('');
            queryClient.invalidateQueries({ queryKey: ['stock-niveaux'] });
            queryClient.invalidateQueries({ queryKey: ['stock-mouvements'] });
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setError(
                messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Transfert impossible.',
            );
        },
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        mutation.mutate();
    };

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
    const label = 'mb-1 block text-xs font-medium text-slate-600';

    return (
        <div className="max-w-2xl space-y-4">
            {dernier && (
                <div className="rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    Transfert <span className="font-mono font-semibold">{dernier.reference}</span> enregistré :{' '}
                    {dernier.sortie.entrepot?.name} → {dernier.entree.entrepot?.name}.
                </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-5 shadow-sm">
                {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

                <div>
                    <label className={label}>Produit</label>
                    <select required value={produitId} onChange={(e) => setProduitId(e.target.value)} className={`${input} w-full`}>
                        <option value="">— Choisir un produit —</option>
                        {produits?.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name} ({p.code})
                            </option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label className={label}>Entrepôt source</label>
                        <select required value={sourceId} onChange={(e) => setSourceId(e.target.value)} className={`${input} w-full`}>
                            <option value="">— Depuis —</option>
                            {entrepots.map((entrepot) => (
                                <option key={entrepot.id} value={entrepot.id}>
                                    {entrepot.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className={label}>Entrepôt destination</label>
                        <select required value={destId} onChange={(e) => setDestId(e.target.value)} className={`${input} w-full`}>
                            <option value="">— Vers —</option>
                            {entrepots
                                .filter((entrepot) => String(entrepot.id) !== sourceId)
                                .map((entrepot) => (
                                    <option key={entrepot.id} value={entrepot.id}>
                                        {entrepot.name}
                                    </option>
                                ))}
                        </select>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label className={label}>Quantité à transférer</label>
                        <input
                            type="number"
                            step="0.001"
                            min="0"
                            required
                            value={quantite}
                            onChange={(e) => setQuantite(e.target.value)}
                            className={`${input} w-full`}
                        />
                    </div>
                    <div>
                        <label className={label}>Note (facultatif)</label>
                        <input value={note} onChange={(e) => setNote(e.target.value)} className={`${input} w-full`} />
                    </div>
                </div>

                <div className="flex items-center justify-between">
                    <p className="text-xs text-slate-500">
                        Le stock sort de la source et entre en destination. La source doit être suffisamment
                        approvisionnée.
                    </p>
                    <button
                        type="submit"
                        disabled={mutation.isPending || entrepots.length < 2}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {mutation.isPending ? 'Transfert…' : 'Transférer'}
                    </button>
                </div>

                {entrepots.length < 2 && (
                    <p className="text-xs text-amber-600">
                        Créez au moins deux entrepôts pour effectuer un transfert.
                    </p>
                )}
            </form>
        </div>
    );
}
