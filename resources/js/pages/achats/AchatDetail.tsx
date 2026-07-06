import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD, formatTva } from '@/lib/format';
import { ACHAT_TYPE_LABELS, achatStatutClasses, achatStatutLabel } from '@/pages/achats/common';
import { MODES_PAIEMENT } from '@/pages/ventes/common';
import { useFeatures } from '@/lib/features';
import type { DocumentAchat } from '@/types';

export default function AchatDetail() {
    const { id } = useParams();
    const navigate = useNavigate();
    const { features } = useFeatures();
    const queryClient = useQueryClient();
    const [error, setError] = useState<string | null>(null);
    const [montant, setMontant] = useState('');
    const [mode, setMode] = useState('virement');
    const [reference, setReference] = useState('');

    const { data: doc, isLoading } = useQuery({
        queryKey: ['achat-detail', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: DocumentAchat }>(`/achats/documents/${id}`);
            return data.data;
        },
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['achats'] });
        queryClient.invalidateQueries({ queryKey: ['achat-detail', id] });
        queryClient.invalidateQueries({ queryKey: ['stock-niveaux'] });
        queryClient.invalidateQueries({ queryKey: ['stock-mouvements'] });
    };

    const onError = (err: any) => {
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
    };

    const action = useMutation({
        mutationFn: ({ path, body }: { path: string; body?: Record<string, unknown> }) =>
            api.post(`/achats/documents/${id}/${path}`, body ?? {}),
        onSuccess: () => {
            setError(null);
            invalidate();
        },
        onError,
    });

    const transformer = useMutation({
        mutationFn: (targetType: string) =>
            api.post(`/achats/documents/${id}/transformer`, { type: targetType }),
        onSuccess: ({ data }) => {
            invalidate();
            navigate(`/achats/${data.data.id}`);
        },
        onError,
    });

    const supprimer = useMutation({
        mutationFn: () => api.delete(`/achats/documents/${id}`),
        onSuccess: () => {
            invalidate();
            navigate('/achats');
        },
        onError,
    });

    const tirerEffet = useMutation({
        mutationFn: (dateEcheance: string) =>
            api.post('/effets', { type: 'payer', facture_id: Number(id), date_echeance: dateEcheance }),
        onSuccess: () => {
            setError(null);
            navigate('/effets');
        },
        onError,
    });

    const telechargerPdf = async () => {
        const response = await api.get(`/achats/documents/${id}/pdf`, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = `${doc?.code ?? 'document'}.pdf`;
        link.click();
        URL.revokeObjectURL(url);
    };

    const ajouterPaiement = (e: FormEvent) => {
        e.preventDefault();
        action.mutate(
            { path: 'paiements', body: { montant: parseFloat(montant), mode, reference: reference || null } },
            { onSuccess: () => { setMontant(''); setReference(''); setError(null); invalidate(); } },
        );
    };

    if (isLoading || !doc) {
        return <div className="py-12 text-center text-slate-400">Chargement…</div>;
    }

    const isCommande = doc.type === 'commande';
    const peutReceptionner = isCommande && ['valide', 'recue_partielle'].includes(doc.statut);
    const peutFacturer =
        (isCommande && ['valide', 'recue_partielle', 'recue'].includes(doc.statut)) ||
        (doc.type === 'reception' && doc.statut === 'valide');

    const btn = 'rounded-md px-3 py-1.5 text-sm font-medium transition';
    const btnPrimary = `${btn} bg-emerald-600 text-white hover:bg-emerald-700`;
    const btnSecondary = `${btn} border border-slate-300 text-slate-700 hover:bg-slate-50`;
    const btnDanger = `${btn} border border-red-200 text-red-600 hover:bg-red-50`;

    return (
        <div className="max-w-5xl space-y-4">
            <div>
                <Link to={`/achats?type=${doc.type}`} className="text-sm text-emerald-600 hover:underline">
                    ← Retour aux achats
                </Link>
                <div className="mt-2 flex items-center justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-xl font-semibold text-slate-900">
                                {ACHAT_TYPE_LABELS[doc.type]} {doc.code}
                            </h1>
                            <span className={`rounded px-2 py-0.5 text-xs ${achatStatutClasses(doc.statut)}`}>
                                {achatStatutLabel(doc)}
                            </span>
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                            {doc.tiers?.name} — {doc.date_document}
                            {doc.entrepot && ` · Entrepôt : ${doc.entrepot.name}`}
                            {doc.ref_fournisseur && ` · Réf. fournisseur : ${doc.ref_fournisseur}`}
                            {doc.source && (
                                <>
                                    {' '}· Issu de{' '}
                                    <Link to={`/achats/${doc.source.id}`} className="text-emerald-600 hover:underline">
                                        {doc.source.code}
                                    </Link>
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap justify-end gap-2">
                        <button onClick={telechargerPdf} className={btnSecondary}>
                            ⬇ PDF
                        </button>
                        {features.effets &&
                            doc.type === 'facture' &&
                            parseFloat(doc.reste_a_payer ?? doc.total_ttc) > 0 && (
                                <button
                                    onClick={() => {
                                        const defaut = new Date();
                                        defaut.setDate(defaut.getDate() + 60);
                                        const saisie = window.prompt(
                                            'Échéance de l\'effet à payer (AAAA-MM-JJ) :',
                                            defaut.toISOString().slice(0, 10),
                                        );
                                        if (saisie) tirerEffet.mutate(saisie);
                                    }}
                                    className={btnSecondary}
                                    title="Accepter un effet à payer (LCN) sur cette facture"
                                >
                                    🧾 Tirer un effet
                                </button>
                            )}
                        {doc.statut === 'brouillon' && (
                            <>
                                <button onClick={() => action.mutate({ path: 'valider' })} className={btnPrimary}>
                                    ✓ Valider
                                </button>
                                <Link to={`/achats/${doc.id}/modifier`} className={btnSecondary}>
                                    Modifier
                                </Link>
                                <button
                                    onClick={() => window.confirm(`Supprimer ${doc.code} ?`) && supprimer.mutate()}
                                    className={btnDanger}
                                >
                                    Supprimer
                                </button>
                            </>
                        )}
                        {peutReceptionner && (
                            <button onClick={() => transformer.mutate('reception')} className={btnPrimary}>
                                📦 Réceptionner le reste
                            </button>
                        )}
                        {peutFacturer && (
                            <button onClick={() => transformer.mutate('facture')} className={btnSecondary}>
                                → Facture fournisseur
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Désignation</th>
                            <th className="px-4 py-3 text-right">Qté</th>
                            {isCommande && <th className="px-4 py-3 text-right">Reçu</th>}
                            <th className="px-4 py-3 text-right">P.U. HT</th>
                            <th className="px-4 py-3 text-right">TVA</th>
                            <th className="px-4 py-3 text-right">Total HT</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {doc.lignes?.map((ligne) => {
                            const recue = parseFloat(ligne.quantite_recue);
                            const commandee = parseFloat(ligne.quantite);
                            return (
                                <tr key={ligne.id}>
                                    <td className="px-4 py-3 text-slate-900">{ligne.designation}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">{commandee}</td>
                                    {isCommande && (
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            <span
                                                className={
                                                    recue >= commandee
                                                        ? 'text-emerald-600'
                                                        : recue > 0
                                                          ? 'text-amber-600'
                                                          : 'text-slate-400'
                                                }
                                            >
                                                {recue} / {commandee}
                                            </span>
                                        </td>
                                    )}
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                        {formatMAD(ligne.prix_unitaire)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                        {formatTva(ligne.tva_rate)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums font-medium text-slate-900">
                                        {formatMAD(ligne.montant_ht)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div className="flex justify-end border-t border-slate-200 px-4 py-4">
                    <div className="w-64 space-y-1 text-sm">
                        <div className="flex justify-between text-slate-600">
                            <span>Total HT</span>
                            <span className="tabular-nums">{formatMAD(doc.total_ht)}</span>
                        </div>
                        <div className="flex justify-between text-slate-600">
                            <span>TVA</span>
                            <span className="tabular-nums">{formatMAD(doc.total_tva)}</span>
                        </div>
                        <div className="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold text-slate-900">
                            <span>Total TTC</span>
                            <span className="tabular-nums">{formatMAD(doc.total_ttc)}</span>
                        </div>
                    </div>
                </div>
            </div>

            {doc.type === 'facture' && doc.statut !== 'brouillon' && (
                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="font-medium text-slate-900">Règlements fournisseur</h2>
                        <div className="text-sm text-slate-600">
                            Payé : <span className="font-medium tabular-nums">{formatMAD(doc.montant_paye ?? 0)}</span>
                            {' — '}
                            Reste :{' '}
                            <span className="font-semibold tabular-nums text-slate-900">
                                {formatMAD(doc.reste_a_payer ?? doc.total_ttc)}
                            </span>
                        </div>
                    </div>

                    {(doc.paiements?.length ?? 0) > 0 && (
                        <table className="mb-4 w-full text-left text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="pb-2">Date</th>
                                    <th className="pb-2">Mode</th>
                                    <th className="pb-2">Référence</th>
                                    <th className="pb-2 text-right">Montant</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {doc.paiements?.map((paiement) => (
                                    <tr key={paiement.id}>
                                        <td className="py-2 text-slate-600">{paiement.date_paiement}</td>
                                        <td className="py-2 text-slate-600">{MODES_PAIEMENT[paiement.mode] ?? paiement.mode}</td>
                                        <td className="py-2 text-slate-500">{paiement.reference ?? '—'}</td>
                                        <td className="py-2 text-right tabular-nums font-medium text-slate-900">
                                            {formatMAD(paiement.montant)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}

                    {doc.statut !== 'paye' && (
                        <form onSubmit={ajouterPaiement} className="flex flex-wrap items-end gap-3">
                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-600">Montant (MAD)</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    required
                                    value={montant}
                                    onChange={(e) => setMontant(e.target.value)}
                                    className="w-36 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-600">Mode</label>
                                <select
                                    value={mode}
                                    onChange={(e) => setMode(e.target.value)}
                                    className="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                                >
                                    {Object.entries(MODES_PAIEMENT).map(([value, labelText]) => (
                                        <option key={value} value={value}>
                                            {labelText}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-600">Référence</label>
                                <input
                                    value={reference}
                                    onChange={(e) => setReference(e.target.value)}
                                    placeholder="N° chèque, virement…"
                                    className="w-44 rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                                />
                            </div>
                            <button type="submit" disabled={action.isPending} className={btnPrimary}>
                                Régler
                            </button>
                        </form>
                    )}
                </div>
            )}

            {doc.notes && (
                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="mb-2 font-medium text-slate-900">Notes</h2>
                    <p className="whitespace-pre-wrap text-sm text-slate-600">{doc.notes}</p>
                </div>
            )}
        </div>
    );
}
