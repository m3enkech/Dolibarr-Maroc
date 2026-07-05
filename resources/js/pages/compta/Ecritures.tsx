import { Fragment, useState, type FormEvent } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { Compte, Ecriture, Paginated } from '@/types';

const JOURNAUX: Record<string, { label: string; classes: string }> = {
    VT: { label: 'Ventes', classes: 'bg-emerald-100 text-emerald-700' },
    AC: { label: 'Achats', classes: 'bg-teal-100 text-teal-700' },
    BQ: { label: 'Trésorerie', classes: 'bg-sky-100 text-sky-700' },
    OD: { label: 'Divers', classes: 'bg-violet-100 text-violet-700' },
};

// Un journal inconnu ne doit jamais faire tomber la page.
const journalInfo = (code: string) =>
    JOURNAUX[code] ?? { label: code, classes: 'bg-slate-200 text-slate-700' };

interface LigneOD {
    compte_id: string;
    libelle: string;
    debit: string;
    credit: string;
}

const emptyLigne: LigneOD = { compte_id: '', libelle: '', debit: '', credit: '' };

export default function Ecritures({ comptes }: { comptes: Compte[] }) {
    const [journal, setJournal] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [expanded, setExpanded] = useState<number | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [libelle, setLibelle] = useState('');
    const [dateEcriture, setDateEcriture] = useState(() => new Date().toISOString().slice(0, 10));
    const [lignes, setLignes] = useState<LigneOD[]>([{ ...emptyLigne }, { ...emptyLigne }]);

    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['compta-ecritures', { journal, search, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Ecriture>>('/compta/ecritures', {
                params: { journal: journal || undefined, search: search || undefined, page },
            });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    const totalDebit = lignes.reduce((s, l) => s + parseFloat(l.debit || '0'), 0);
    const totalCredit = lignes.reduce((s, l) => s + parseFloat(l.credit || '0'), 0);
    const equilibre = Math.abs(totalDebit - totalCredit) < 0.005 && totalDebit > 0;

    const mutation = useMutation({
        mutationFn: () =>
            api.post('/compta/ecritures', {
                libelle,
                date_ecriture: dateEcriture,
                lignes: lignes
                    .filter((l) => l.compte_id)
                    .map((l) => ({
                        compte_id: parseInt(l.compte_id, 10),
                        libelle: l.libelle || null,
                        debit: parseFloat(l.debit || '0'),
                        credit: parseFloat(l.credit || '0'),
                    })),
            }),
        onSuccess: () => {
            setError(null);
            setLibelle('');
            setLignes([{ ...emptyLigne }, { ...emptyLigne }]);
            setShowForm(false);
            queryClient.invalidateQueries({ queryKey: ['compta-ecritures'] });
            queryClient.invalidateQueries({ queryKey: ['compta-balance'] });
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setError(
                messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Écriture impossible.',
            );
        },
    });

    const setLigne = (index: number, patch: Partial<LigneOD>) =>
        setLignes((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        mutation.mutate();
    };

    const input =
        'rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <select
                    value={journal}
                    onChange={(e) => {
                        setJournal(e.target.value);
                        setPage(1);
                    }}
                    className={input}
                >
                    <option value="">Tous les journaux</option>
                    {Object.entries(JOURNAUX).map(([code, j]) => (
                        <option key={code} value={code}>
                            {code} — {j.label}
                        </option>
                    ))}
                </select>
                <input
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    placeholder="N°, libellé ou référence…"
                    className={`${input} w-64`}
                />
                <button
                    onClick={() => setShowForm((v) => !v)}
                    className="ml-auto rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + Écriture manuelle (OD)
                </button>
            </div>

            {showForm && (
                <form onSubmit={handleSubmit} className="space-y-3 rounded-xl bg-white p-5 shadow-sm">
                    {error && (
                        <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
                    )}
                    <div className="flex gap-3">
                        <div className="flex-1">
                            <label className="mb-1 block text-xs font-medium text-slate-600">Libellé *</label>
                            <input required value={libelle} onChange={(e) => setLibelle(e.target.value)} className={`${input} w-full`} />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Date</label>
                            <input type="date" value={dateEcriture} onChange={(e) => setDateEcriture(e.target.value)} className={input} />
                        </div>
                    </div>

                    <table className="w-full text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="pb-1 pr-2 text-left" style={{ width: '35%' }}>Compte</th>
                                <th className="pb-1 pr-2 text-left">Libellé</th>
                                <th className="pb-1 pr-2 text-right" style={{ width: '15%' }}>Débit</th>
                                <th className="pb-1 pr-2 text-right" style={{ width: '15%' }}>Crédit</th>
                                <th style={{ width: '4%' }}></th>
                            </tr>
                        </thead>
                        <tbody>
                            {lignes.map((ligne, index) => (
                                <tr key={index}>
                                    <td className="py-1 pr-2">
                                        <select
                                            required
                                            value={ligne.compte_id}
                                            onChange={(e) => setLigne(index, { compte_id: e.target.value })}
                                            className={`${input} w-full`}
                                        >
                                            <option value="">— Compte —</option>
                                            {comptes.map((compte) => (
                                                <option key={compte.id} value={compte.id}>
                                                    {compte.code} — {compte.label}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="py-1 pr-2">
                                        <input value={ligne.libelle} onChange={(e) => setLigne(index, { libelle: e.target.value })} className={`${input} w-full`} />
                                    </td>
                                    <td className="py-1 pr-2">
                                        <input type="number" step="0.01" min="0" value={ligne.debit} onChange={(e) => setLigne(index, { debit: e.target.value, credit: '' })} className={`${input} w-full text-right`} />
                                    </td>
                                    <td className="py-1 pr-2">
                                        <input type="number" step="0.01" min="0" value={ligne.credit} onChange={(e) => setLigne(index, { credit: e.target.value, debit: '' })} className={`${input} w-full text-right`} />
                                    </td>
                                    <td className="py-1 text-right">
                                        <button
                                            type="button"
                                            onClick={() => setLignes((prev) => prev.filter((_, i) => i !== index))}
                                            disabled={lignes.length <= 2}
                                            className="text-red-500 disabled:opacity-30"
                                        >
                                            ✕
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="flex items-center justify-between">
                        <button
                            type="button"
                            onClick={() => setLignes((prev) => [...prev, { ...emptyLigne }])}
                            className="rounded-md border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:border-emerald-500 hover:text-emerald-600"
                        >
                            + Ligne
                        </button>
                        <div className="flex items-center gap-4 text-sm">
                            <span className="text-slate-600">
                                Débit <span className="font-medium tabular-nums">{formatMAD(totalDebit)}</span>
                                {' · '}
                                Crédit <span className="font-medium tabular-nums">{formatMAD(totalCredit)}</span>
                            </span>
                            {equilibre ? (
                                <span className="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Équilibrée</span>
                            ) : (
                                <span className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">Déséquilibrée</span>
                            )}
                            <button
                                type="submit"
                                disabled={!equilibre || mutation.isPending}
                                className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                            >
                                Enregistrer
                            </button>
                        </div>
                    </div>
                </form>
            )}

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">N°</th>
                            <th className="px-4 py-3">Date</th>
                            <th className="px-4 py-3">Journal</th>
                            <th className="px-4 py-3">Libellé</th>
                            <th className="px-4 py-3 text-right">Montant</th>
                            <th className="px-4 py-3">Origine</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                                    Aucune écriture. Validez une facture ou saisissez une OD.
                                </td>
                            </tr>
                        )}
                        {data?.data.map((ecriture) => (
                            <Fragment key={ecriture.id}>
                                <tr
                                    onClick={() => setExpanded(expanded === ecriture.id ? null : ecriture.id)}
                                    className="cursor-pointer hover:bg-slate-50"
                                >
                                    <td className="px-4 py-3 font-mono text-xs font-medium text-emerald-700">{ecriture.numero}</td>
                                    <td className="px-4 py-3 text-slate-600">{ecriture.date_ecriture}</td>
                                    <td className="px-4 py-3">
                                        <span
                                            className={`rounded px-1.5 py-0.5 text-xs ${journalInfo(ecriture.journal).classes}`}
                                            title={journalInfo(ecriture.journal).label}
                                        >
                                            {ecriture.journal}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-slate-900">{ecriture.libelle}</td>
                                    <td className="px-4 py-3 text-right tabular-nums font-medium text-slate-900">
                                        {formatMAD(ecriture.total_debit)}
                                    </td>
                                    <td className="px-4 py-3">
                                        {ecriture.is_auto ? (
                                            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">auto</span>
                                        ) : (
                                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700">manuelle</span>
                                        )}
                                    </td>
                                </tr>
                                {expanded === ecriture.id && (
                                    <tr>
                                        <td colSpan={6} className="bg-slate-50 px-8 py-3">
                                            <table className="w-full text-xs">
                                                <thead className="text-slate-500">
                                                    <tr>
                                                        <th className="pb-1 text-left">Compte</th>
                                                        <th className="pb-1 text-left">Intitulé</th>
                                                        <th className="pb-1 text-right">Débit</th>
                                                        <th className="pb-1 text-right">Crédit</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {ecriture.lignes.map((ligne) => (
                                                        <tr key={ligne.id} className="border-t border-slate-200">
                                                            <td className="py-1 font-mono">{ligne.compte_code}</td>
                                                            <td className="py-1">{ligne.compte_label}</td>
                                                            <td className="py-1 text-right tabular-nums">
                                                                {parseFloat(ligne.debit) > 0 ? formatMAD(ligne.debit) : ''}
                                                            </td>
                                                            <td className="py-1 text-right tabular-nums">
                                                                {parseFloat(ligne.credit) > 0 ? formatMAD(ligne.credit) : ''}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </tbody>
                </table>

                {data && data.meta.last_page > 1 && (
                    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm">
                        <span className="text-slate-500">
                            Page {data.meta.current_page} / {data.meta.last_page} — {data.meta.total} écritures
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
