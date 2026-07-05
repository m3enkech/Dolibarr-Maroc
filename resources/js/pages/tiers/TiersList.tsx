import { useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import type { Paginated, Tiers } from '@/types';

export default function TiersList() {
    const [search, setSearch] = useState('');
    const [type, setType] = useState('');
    const [page, setPage] = useState(1);
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['tiers', { search, type, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tiers>>('/tiers', {
                params: { search: search || undefined, type: type || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    const deleteMutation = useMutation({
        mutationFn: (id: number) => api.delete(`/tiers/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['tiers'] });
            queryClient.invalidateQueries({ queryKey: ['tiers-count'] });
        },
    });

    const handleDelete = (tiers: Tiers) => {
        if (window.confirm(`Supprimer « ${tiers.name} » (${tiers.code}) ?`)) {
            deleteMutation.mutate(tiers.id);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Tiers</h1>
                    <p className="mt-1 text-sm text-slate-500">Clients et fournisseurs</p>
                </div>
                <Link
                    to="/tiers/nouveau"
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + Nouveau tiers
                </Link>
            </div>

            <div className="flex gap-3">
                <input
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Rechercher par nom, code ou ICE…"
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
                    <option value="client">Clients</option>
                    <option value="fournisseur">Fournisseurs</option>
                </select>
            </div>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Nom</th>
                            <th className="px-4 py-3">Type</th>
                            <th className="px-4 py-3">ICE</th>
                            <th className="px-4 py-3">Ville</th>
                            <th className="px-4 py-3">Téléphone</th>
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
                                    Aucun tiers. Créez le premier !
                                </td>
                            </tr>
                        )}
                        {data?.data.map((tiers) => (
                            <tr key={tiers.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{tiers.code}</td>
                                <td className="px-4 py-3 font-medium text-slate-900">
                                    {tiers.name}
                                    {!tiers.is_active && (
                                        <span className="ml-2 rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-600">
                                            inactif
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3">
                                    {tiers.is_client && (
                                        <span className="mr-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700">
                                            Client
                                        </span>
                                    )}
                                    {tiers.is_supplier && (
                                        <span className="rounded bg-sky-100 px-1.5 py-0.5 text-xs text-sky-700">
                                            Fournisseur
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 font-mono text-xs text-slate-600">
                                    {tiers.ice ?? '—'}
                                </td>
                                <td className="px-4 py-3 text-slate-600">{tiers.city ?? '—'}</td>
                                <td className="px-4 py-3 text-slate-600">{tiers.phone ?? '—'}</td>
                                <td className="px-4 py-3 text-right">
                                    <Link
                                        to={`/tiers/${tiers.id}`}
                                        className="mr-3 text-emerald-600 hover:underline"
                                    >
                                        Modifier
                                    </Link>
                                    <button
                                        onClick={() => handleDelete(tiers)}
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
                            Page {data.meta.current_page} / {data.meta.last_page} — {data.meta.total} tiers
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
