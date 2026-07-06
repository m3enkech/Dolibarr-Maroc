import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD, formatTva } from '@/lib/format';
import type { Paginated, Produit } from '@/types';

export default function ProduitsList() {
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');
    const [page, setPage] = useState(1);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['produits', { search, type, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Produit>>('/produits', {
                params: { search: search || undefined, type: type || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => api.delete(`/produits/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['produits'] });
            queryClient.invalidateQueries({ queryKey: ['produits-count'] });
        },
    });

    const handleDelete = (produit: Produit) => {
        if (window.confirm(`Supprimer « ${produit.name} » (${produit.code}) ?`)) {
            deleteMutation.mutate(produit.id);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Catalogue</h1>
                    <p className="mt-1 text-sm text-slate-500">Produits et services</p>
                </div>
                <div className="flex gap-2">
                    <Link
                        to="/catalogue/categories"
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    >
                        Catégories comptables
                    </Link>
                    <Link
                        to="/catalogue/nouveau"
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                    >
                        + Nouveau produit
                    </Link>
                </div>
            </div>

            <div className="flex gap-3">
                <input
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Rechercher par nom, code ou code-barres…"
                    className="w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
                <select
                    value={type}
                    onChange={(e) => {
                        setType(e.target.value);
                        setPage(1);
                    }}
                    className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                >
                    <option value="">Tous</option>
                    <option value="product">Produits</option>
                    <option value="service">Services</option>
                    <option value="kit">Kits</option>
                </select>
            </div>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Nom</th>
                            <th className="px-4 py-3">Type</th>
                            <th className="px-4 py-3 text-right">Prix HT</th>
                            <th className="px-4 py-3 text-right">TVA</th>
                            <th className="px-4 py-3 text-right">Prix TTC</th>
                            <th className="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-slate-400">
                                    Chargement…
                                </td>
                            </tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-8 text-center text-slate-400">
                                    Aucun produit. Créez le premier !
                                </td>
                            </tr>
                        )}
                        {data?.data.map((produit) => (
                            <tr key={produit.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{produit.code}</td>
                                <td className="px-4 py-3 font-medium text-slate-900">
                                    {produit.name}
                                    {produit.unit && (
                                        <span className="ml-1 text-xs text-slate-400">/ {produit.unit}</span>
                                    )}
                                    {!produit.is_active && (
                                        <span className="ml-2 rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-600">
                                            inactif
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {produit.type === 'product' ? (
                                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700">
                                            Produit
                                        </span>
                                    ) : produit.type === 'kit' ? (
                                        <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700">
                                            Kit
                                        </span>
                                    ) : (
                                        <span className="rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-700">
                                            Service
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-slate-700">
                                    {formatMAD(produit.sell_price)}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-slate-500">
                                    {formatTva(produit.tva_rate)}
                                </td>
                                <td className="px-4 py-3 text-right font-medium tabular-nums text-slate-900">
                                    {formatMAD(produit.sell_price_ttc)}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <Link
                                        to={`/catalogue/${produit.id}`}
                                        className="mr-3 text-emerald-600 hover:underline"
                                    >
                                        Modifier
                                    </Link>
                                    <button
                                        onClick={() => handleDelete(produit)}
                                        className="text-red-500 hover:underline"
                                    >
                                        Supprimer
                                    </button>
                                </td>
                            </tr>
                        ))}
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
