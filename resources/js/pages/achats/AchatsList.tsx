import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import {
    ACHAT_TYPE_LABELS,
    ACHAT_TYPE_LABELS_PLURAL,
    achatStatutClasses,
    achatStatutLabel,
} from '@/pages/achats/common';
import type { AchatType, DocumentAchat, Paginated } from '@/types';

const TABS: AchatType[] = ['commande', 'reception', 'facture'];

export default function AchatsList() {
    const [searchParams, setSearchParams] = useSearchParams();
    const type = (TABS.includes(searchParams.get('type') as AchatType)
        ? searchParams.get('type')
        : 'commande') as AchatType;
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);

    const { data, isLoading } = useQuery({
        queryKey: ['achats', { type, search, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentAchat>>('/achats/documents', {
                params: { type, search: search || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    const switchTab = (tab: AchatType) => {
        setSearchParams({ type: tab });
        setPage(1);
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">Achats</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Commandes fournisseurs, réceptions et factures
                    </p>
                </div>
                <Link
                    to={`/achats/nouveau?type=${type}`}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + {ACHAT_TYPE_LABELS[type]}
                </Link>
            </div>

            <div className="flex items-center gap-3">
                <div className="flex rounded-lg border border-slate-200 bg-white p-1">
                    {TABS.map((tab) => (
                        <button
                            key={tab}
                            onClick={() => switchTab(tab)}
                            className={`rounded-md px-4 py-1.5 text-sm font-medium transition ${
                                type === tab ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            {ACHAT_TYPE_LABELS_PLURAL[tab]}
                        </button>
                    ))}
                </div>
                <input
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    placeholder="Code, réf. fournisseur ou nom…"
                    className="w-64 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                />
            </div>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Date</th>
                            <th className="px-4 py-3">Fournisseur</th>
                            {type === 'reception' && <th className="px-4 py-3">Entrepôt</th>}
                            {type === 'facture' && <th className="px-4 py-3">Réf. fournisseur</th>}
                            <th className="px-4 py-3 text-right">Total TTC</th>
                            <th className="px-4 py-3">Statut</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
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
                                        to={`/achats/${doc.id}`}
                                        className="font-mono text-xs font-medium text-emerald-700 hover:underline"
                                    >
                                        {doc.code}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 text-slate-600">{doc.date_document}</td>
                                <td className="px-4 py-3 font-medium text-slate-900">{doc.tiers?.name}</td>
                                {type === 'reception' && (
                                    <td className="px-4 py-3 text-slate-600">{doc.entrepot?.name ?? '—'}</td>
                                )}
                                {type === 'facture' && (
                                    <td className="px-4 py-3 text-slate-600">{doc.ref_fournisseur ?? '—'}</td>
                                )}
                                <td className="px-4 py-3 text-right tabular-nums text-slate-900">
                                    {formatMAD(doc.total_ttc)}
                                </td>
                                <td className="px-4 py-3">
                                    <span className={`rounded px-1.5 py-0.5 text-xs ${achatStatutClasses(doc.statut)}`}>
                                        {achatStatutLabel(doc)}
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
                            <button disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40">
                                Précédent
                            </button>
                            <button disabled={page >= data.meta.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-md border border-slate-300 px-3 py-1 disabled:opacity-40">
                                Suivant
                            </button>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
