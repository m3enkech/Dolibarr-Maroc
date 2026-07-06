import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { statutClasses, statutLabel, TYPE_LABELS, TYPE_LABELS_PLURAL } from '@/pages/ventes/common';
import type { DocumentType, DocumentVente, Paginated } from '@/types';

const TABS: DocumentType[] = ['devis', 'commande', 'bon_livraison', 'facture', 'avoir'];

export default function VentesList() {
    const [searchParams, setSearchParams] = useSearchParams();
    const type = (TABS.includes(searchParams.get('type') as DocumentType)
        ? searchParams.get('type')
        : 'devis') as DocumentType;
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const { data, isLoading } = useQuery({
        queryKey: ['ventes', { type, search, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentVente>>('/ventes/documents', {
                params: { type, search: search || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    const switchTab = (tab: DocumentType) => {
        setSearchParams({ type: tab });
        setPage(1);
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Ventes</h1>
                    <p className="mt-1 text-sm text-slate-500">Devis, commandes, factures et avoirs</p>
                </div>
                <Link
                    to={`/ventes/nouveau?type=${type}`}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + {TYPE_LABELS[type]}
                </Link>
            </div>

            <div className="flex items-center gap-3">
                <div className="flex rounded-lg border border-slate-200 bg-white p-1">
                    {TABS.map((tab) => (
                        <button
                            key={tab}
                            onClick={() => switchTab(tab)}
                            className={`rounded-md px-4 py-1.5 text-sm font-medium transition ${
                                type === tab
                                    ? 'bg-emerald-600 text-white'
                                    : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            {TYPE_LABELS_PLURAL[tab]}
                        </button>
                    ))}
                </div>
                <input
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Rechercher par code ou client…"
                    className="w-64 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
            </div>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Date</th>
                            <th className="px-4 py-3">Client</th>
                            <th className="px-4 py-3 text-right">Total TTC</th>
                            {type === 'facture' && <th className="px-4 py-3 text-right">Reste à payer</th>}
                            <th className="px-4 py-3">Statut</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                                    Chargement…
                                </td>
                            </tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                                    Aucun document. Créez le premier !
                                </td>
                            </tr>
                        )}
                        {data?.data.map((doc) => (
                            <tr key={doc.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3">
                                    <Link
                                        to={`/ventes/${doc.id}`}
                                        className="font-mono text-xs font-medium text-emerald-700 hover:underline"
                                    >
                                        {doc.code}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 text-slate-600">{doc.date_document}</td>
                                <td className="px-4 py-3 font-medium text-slate-900">{doc.tiers?.name}</td>
                                <td className="px-4 py-3 text-right tabular-nums text-slate-900">
                                    {formatMAD(doc.total_ttc)}
                                </td>
                                {type === 'facture' && (
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                        {doc.statut === 'brouillon' ? '—' : formatMAD(doc.reste_a_payer ?? doc.total_ttc)}
                                    </td>
                                )}
                                <td className="px-4 py-3">
                                    <span className={`rounded px-1.5 py-0.5 text-xs ${statutClasses(doc.statut)}`}>
                                        {statutLabel(doc)}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                {data && data.meta.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                        <span className="text-slate-500">
                            Page {data.meta.current_page} / {data.meta.last_page} — {data.meta.total} documents
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
