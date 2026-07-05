import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { ComptaMappingRow, Compte } from '@/types';

interface LettrageLigne {
    id: number;
    date_ecriture: string;
    numero: string;
    journal: string;
    libelle: string;
    reference: string | null;
    tiers: string | null;
    debit: string;
    credit: string;
    lettrage: string | null;
}

interface LettrageResponse {
    data: LettrageLigne[];
    solde_non_lettre: string;
}

export default function Lettrage({
    comptes,
    mappings,
}: {
    comptes: Compte[];
    mappings: ComptaMappingRow[];
}) {
    const compteClients = mappings.find((m) => m.cle === 'clients')?.compte_id;
    const compteFournisseurs = mappings.find((m) => m.cle === 'fournisseurs')?.compte_id;

    const [compteId, setCompteId] = useState<string>('');
    const [statut, setStatut] = useState('non_lettres');
    const [selection, setSelection] = useState<number[]>([]);
    const [message, setMessage] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const compteActif = compteId || (compteClients ? String(compteClients) : '');

    const { data, isLoading } = useQuery({
        queryKey: ['compta-lettrage', { compte: compteActif, statut }],
        queryFn: async () => {
            const { data } = await api.get<LettrageResponse>('/compta/lettrage', {
                params: { compte_id: compteActif, statut },
            });
            return data;
        },
        enabled: compteActif !== '',
    });

    const invalidate = () => {
        setSelection([]);
        queryClient.invalidateQueries({ queryKey: ['compta-lettrage'] });
        queryClient.invalidateQueries({ queryKey: ['compta-ecritures'] });
    };

    const onError = (err: any) => {
        setMessage(null);
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
    };

    const lettrer = useMutation({
        mutationFn: () => api.post<{ code: string; lignes: number }>('/compta/lettrage', { ligne_ids: selection }),
        onSuccess: ({ data }) => {
            setError(null);
            setMessage(`Lettre « ${data.code} » posée sur ${data.lignes} lignes.`);
            invalidate();
        },
        onError,
    });

    const auto = useMutation({
        mutationFn: () =>
            api.post<{ groupes: number; lignes: number }>('/compta/lettrage/auto', {
                compte_id: parseInt(compteActif, 10),
            }),
        onSuccess: ({ data }) => {
            setError(null);
            setMessage(
                data.groupes > 0
                    ? `Lettrage automatique : ${data.groupes} groupe(s), ${data.lignes} lignes lettrées.`
                    : 'Rien à lettrer automatiquement (aucun groupe équilibré par référence).',
            );
            invalidate();
        },
        onError,
    });

    const delettrer = useMutation({
        mutationFn: (code: string) =>
            api.post('/compta/lettrage/delettrer', { compte_id: parseInt(compteActif, 10), code }),
        onSuccess: () => {
            setError(null);
            setMessage('Lettrage supprimé.');
            invalidate();
        },
        onError,
    });

    const toggle = (id: number) =>
        setSelection((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

    const { totalDebit, totalCredit } = useMemo(() => {
        const selected = (data?.data ?? []).filter((l) => selection.includes(l.id));
        return {
            totalDebit: selected.reduce((s, l) => s + parseFloat(l.debit), 0),
            totalCredit: selected.reduce((s, l) => s + parseFloat(l.credit), 0),
        };
    }, [data, selection]);

    const ecart = Math.round((totalDebit - totalCredit) * 100) / 100;
    const equilibre = selection.length >= 2 && Math.abs(ecart) < 0.005;

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';
    const quickBtn = (actif: boolean) =>
        `rounded-md px-3 py-1.5 text-sm font-medium transition ${
            actif ? 'bg-emerald-600 text-white' : 'border border-slate-300 text-slate-600 hover:bg-slate-50'
        }`;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                {compteClients && (
                    <button
                        onClick={() => { setCompteId(String(compteClients)); setSelection([]); }}
                        className={quickBtn(compteActif === String(compteClients))}
                    >
                        Clients (3411)
                    </button>
                )}
                {compteFournisseurs && (
                    <button
                        onClick={() => { setCompteId(String(compteFournisseurs)); setSelection([]); }}
                        className={quickBtn(compteActif === String(compteFournisseurs))}
                    >
                        Fournisseurs (4411)
                    </button>
                )}
                <select
                    value={compteActif}
                    onChange={(e) => { setCompteId(e.target.value); setSelection([]); }}
                    className={input}
                >
                    {comptes.map((compte) => (
                        <option key={compte.id} value={compte.id}>
                            {compte.code} — {compte.label}
                        </option>
                    ))}
                </select>
                <select value={statut} onChange={(e) => { setStatut(e.target.value); setSelection([]); }} className={input}>
                    <option value="non_lettres">Non lettrées</option>
                    <option value="lettres">Lettrées</option>
                    <option value="tous">Toutes</option>
                </select>
                <button
                    onClick={() => auto.mutate()}
                    disabled={auto.isPending || !compteActif}
                    className="ml-auto rounded-md border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 transition hover:bg-emerald-50 disabled:opacity-50"
                >
                    ⚡ Lettrage automatique
                </button>
            </div>

            {message && <div className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{message}</div>}
            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="w-10 px-4 py-3"></th>
                            <th className="px-4 py-3">Date</th>
                            <th className="px-4 py-3">Écriture</th>
                            <th className="px-4 py-3">Libellé</th>
                            <th className="px-4 py-3">Tiers</th>
                            <th className="px-4 py-3 text-right">Débit</th>
                            <th className="px-4 py-3 text-right">Crédit</th>
                            <th className="px-4 py-3">Lettre</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={8} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
                        )}
                        {!isLoading && data?.data.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-8 text-center text-slate-400">
                                    Aucune ligne {statut === 'lettres' ? 'lettrée' : 'à lettrer'} sur ce compte.
                                </td>
                            </tr>
                        )}
                        {data?.data.map((ligne) => (
                            <tr
                                key={ligne.id}
                                className={`hover:bg-slate-50 ${selection.includes(ligne.id) ? 'bg-emerald-50/60' : ''}`}
                            >
                                <td className="px-4 py-2.5">
                                    {ligne.lettrage === null && (
                                        <input
                                            type="checkbox"
                                            checked={selection.includes(ligne.id)}
                                            onChange={() => toggle(ligne.id)}
                                            className="rounded border-slate-300"
                                        />
                                    )}
                                </td>
                                <td className="px-4 py-2.5 text-slate-600">{ligne.date_ecriture}</td>
                                <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{ligne.numero}</td>
                                <td className="px-4 py-2.5 text-slate-900">{ligne.libelle}</td>
                                <td className="px-4 py-2.5 text-slate-600">{ligne.tiers ?? '—'}</td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-slate-900">
                                    {parseFloat(ligne.debit) > 0 ? formatMAD(ligne.debit) : ''}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-slate-900">
                                    {parseFloat(ligne.credit) > 0 ? formatMAD(ligne.credit) : ''}
                                </td>
                                <td className="px-4 py-2.5">
                                    {ligne.lettrage && (
                                        <button
                                            onClick={() =>
                                                window.confirm(`Délettrer le groupe « ${ligne.lettrage} » ?`) &&
                                                delettrer.mutate(ligne.lettrage!)
                                            }
                                            title="Cliquer pour délettrer"
                                            className="rounded bg-emerald-100 px-2 py-0.5 font-mono text-xs font-semibold text-emerald-700 hover:bg-red-100 hover:text-red-700"
                                        >
                                            {ligne.lettrage}
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>

                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    <span className="text-slate-600">
                        Solde non lettré :{' '}
                        <span className="font-semibold tabular-nums text-slate-900">
                            {formatMAD(data?.solde_non_lettre ?? 0)}
                        </span>
                    </span>
                    <div className="flex items-center gap-4">
                        <span className="text-slate-600">
                            Sélection : <span className="tabular-nums">{formatMAD(totalDebit)}</span> D /{' '}
                            <span className="tabular-nums">{formatMAD(totalCredit)}</span> C
                            {selection.length > 0 && (
                                <span className={`ml-2 rounded px-1.5 py-0.5 text-xs ${equilibre ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}`}>
                                    {equilibre ? 'équilibrée' : `écart ${formatMAD(ecart)}`}
                                </span>
                            )}
                        </span>
                        <button
                            onClick={() => lettrer.mutate()}
                            disabled={!equilibre || lettrer.isPending}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            Lettrer la sélection
                        </button>
                    </div>
                </div>
            </div>

            <p className="text-xs text-slate-500">
                Le lettrage automatique rapproche par référence (FA-…, FF-…) les groupes équilibrés.
                Cochez manuellement pour les cas particuliers (règlements groupés, avoirs…) — le bouton
                s'active quand la sélection est équilibrée. Cliquez sur une lettre pour délettrer.
            </p>
        </div>
    );
}
