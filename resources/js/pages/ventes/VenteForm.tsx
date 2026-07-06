import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { TYPE_LABELS } from '@/pages/ventes/common';
import type { DocumentType, DocumentVente, Paginated, Produit, Tiers } from '@/types';

const TVA_RATES = ['20', '14', '10', '7', '0'];

interface LigneForm {
    produit_id: string;
    designation: string;
    quantite: string;
    prix_unitaire: string;
    remise_percent: string;
    tva_rate: string;
}

const emptyLigne: LigneForm = {
    produit_id: '',
    designation: '',
    quantite: '1',
    prix_unitaire: '0',
    remise_percent: '0',
    tva_rate: '20',
};

function ligneHt(ligne: LigneForm): number {
    const qty = parseFloat(ligne.quantite || '0');
    const pu = parseFloat(ligne.prix_unitaire || '0');
    const remise = parseFloat(ligne.remise_percent || '0');
    return Math.round(qty * pu * (1 - remise / 100) * 100) / 100;
}

export default function VenteForm() {
    const { id } = useParams();
    const isEdit = id !== undefined;
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const [type, setType] = useState<DocumentType>(
        (['devis', 'commande', 'facture', 'avoir'].includes(searchParams.get('type') ?? '')
            ? searchParams.get('type')
            : 'devis') as DocumentType,
    );
    const [tiersId, setTiersId] = useState('');
    const [dateDocument, setDateDocument] = useState(() => new Date().toISOString().slice(0, 10));
    const [dateEcheance, setDateEcheance] = useState('');
    const [notes, setNotes] = useState('');
    const [lignes, setLignes] = useState<LigneForm[]>([{ ...emptyLigne }]);
    const [error, setError] = useState<string | null>(null);

    const { data: tiersList } = useQuery({
        queryKey: ['tiers-options'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tiers>>('/tiers', { params: { per_page: 200 } });
            return data.data;
        },
    });

    const { data: produits } = useQuery({
        queryKey: ['produits-options'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Produit>>('/produits', { params: { per_page: 200 } });
            return data.data;
        },
    });

    const { data: existing } = useQuery({
        queryKey: ['vente-detail', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: DocumentVente }>(`/ventes/documents/${id}`);
            return data.data;
        },
        enabled: isEdit,
    });

    useEffect(() => {
        if (existing) {
            setType(existing.type);
            setTiersId(String(existing.tiers_id));
            setDateDocument(existing.date_document);
            setDateEcheance(existing.date_echeance ?? '');
            setNotes(existing.notes ?? '');
            setLignes(
                (existing.lignes ?? []).map((l) => ({
                    produit_id: l.produit_id ? String(l.produit_id) : '',
                    designation: l.designation,
                    quantite: String(parseFloat(l.quantite)),
                    prix_unitaire: l.prix_unitaire,
                    remise_percent: String(parseFloat(l.remise_percent)),
                    tva_rate: String(parseFloat(l.tva_rate)),
                })),
            );
        }
    }, [existing]);

    const setLigne = (index: number, patch: Partial<LigneForm>) =>
        setLignes((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    const onProduitChange = (index: number, produitId: string) => {
        const produit = produits?.find((p) => String(p.id) === produitId);
        if (produit) {
            setLigne(index, {
                produit_id: produitId,
                designation: produit.name,
                prix_unitaire: produit.sell_price,
                tva_rate: String(parseFloat(produit.tva_rate)),
            });
        } else {
            setLigne(index, { produit_id: '' });
        }
    };

    const totalHt = lignes.reduce((sum, l) => sum + ligneHt(l), 0);
    const totalTva = lignes.reduce(
        (sum, l) => sum + Math.round(ligneHt(l) * parseFloat(l.tva_rate || '0')) / 100,
        0,
    );

    const mutation = useMutation({
        mutationFn: (payload: Record<string, unknown>) =>
            isEdit
                ? api.put(`/ventes/documents/${id}`, payload)
                : api.post('/ventes/documents', payload),
        onSuccess: ({ data }) => {
            queryClient.invalidateQueries({ queryKey: ['ventes'] });
            queryClient.invalidateQueries({ queryKey: ['vente-detail'] });
            navigate(`/ventes/${data.data.id}`);
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setError(
                messages
                    ? (Object.values(messages).flat() as string[]).join(' ')
                    : 'Enregistrement impossible.',
            );
        },
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        const payload: Record<string, unknown> = {
            tiers_id: parseInt(tiersId, 10),
            date_document: dateDocument,
            date_echeance: dateEcheance || null,
            notes: notes || null,
            lignes: lignes.map((l) => ({
                produit_id: l.produit_id ? parseInt(l.produit_id, 10) : null,
                designation: l.designation || null,
                quantite: parseFloat(l.quantite || '0'),
                prix_unitaire: parseFloat(l.prix_unitaire || '0'),
                remise_percent: parseFloat(l.remise_percent || '0'),
                tva_rate: parseFloat(l.tva_rate),
            })),
        };
        if (!isEdit) {
            payload.type = type;
        }
        mutation.mutate(payload);
    };

    const input =
        'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
    const label = 'mb-1 block text-sm font-medium text-slate-700';

    return (
        <div className="max-w-5xl space-y-4">
            <div>
                <Link to={isEdit ? `/ventes/${id}` : '/ventes'} className="text-sm text-emerald-600 hover:underline">
                    ← Retour
                </Link>
                <h1 className="mt-2 text-xl font-semibold text-slate-900">
                    {isEdit ? `Modifier ${existing?.code ?? ''}` : `Nouveau ${TYPE_LABELS[type].toLowerCase()}`}
                </h1>
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <div className="grid gap-4 sm:grid-cols-4">
                        <div>
                            <label className={label}>Client *</label>
                            <select required value={tiersId} onChange={(e) => setTiersId(e.target.value)} className={input}>
                                <option value="">— Choisir —</option>
                                {tiersList?.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={label}>Date</label>
                            <input type="date" value={dateDocument} onChange={(e) => setDateDocument(e.target.value)} className={input} />
                        </div>
                        <div>
                            <label className={label}>Échéance</label>
                            <input type="date" value={dateEcheance} onChange={(e) => setDateEcheance(e.target.value)} className={input} />
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="mb-4 font-medium text-slate-900">Lignes</h2>
                    <table className="w-full text-sm">
                        <thead className="text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="pb-2 pr-2 text-left" style={{ width: '18%' }}>Produit</th>
                                <th className="pb-2 pr-2 text-left">Désignation *</th>
                                <th className="pb-2 pr-2 text-right" style={{ width: '8%' }}>Qté</th>
                                <th className="pb-2 pr-2 text-right" style={{ width: '12%' }}>P.U. HT</th>
                                <th className="pb-2 pr-2 text-right" style={{ width: '9%' }}>Remise %</th>
                                <th className="pb-2 pr-2 text-right" style={{ width: '9%' }}>TVA</th>
                                <th className="pb-2 pr-2 text-right" style={{ width: '12%' }}>Total HT</th>
                                <th className="pb-2" style={{ width: '4%' }}></th>
                            </tr>
                        </thead>
                        <tbody>
                            {lignes.map((ligne, index) => (
                                <tr key={index} className="border-t border-slate-100">
                                    <td className="py-2 pr-2">
                                        <select
                                            value={ligne.produit_id}
                                            onChange={(e) => onProduitChange(index, e.target.value)}
                                            className={input}
                                        >
                                            <option value="">Ligne libre</option>
                                            {produits?.map((p) => (
                                                <option key={p.id} value={p.id}>
                                                    {p.name}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input
                                            required
                                            value={ligne.designation}
                                            onChange={(e) => setLigne(index, { designation: e.target.value })}
                                            className={input}
                                        />
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input
                                            type="number"
                                            step="0.001"
                                            min="0.001"
                                            required
                                            value={ligne.quantite}
                                            onChange={(e) => setLigne(index, { quantite: e.target.value })}
                                            className={`${input} text-right`}
                                        />
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            required
                                            value={ligne.prix_unitaire}
                                            onChange={(e) => setLigne(index, { prix_unitaire: e.target.value })}
                                            className={`${input} text-right`}
                                        />
                                    </td>
                                    <td className="py-2 pr-2">
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            value={ligne.remise_percent}
                                            onChange={(e) => setLigne(index, { remise_percent: e.target.value })}
                                            className={`${input} text-right`}
                                        />
                                    </td>
                                    <td className="py-2 pr-2">
                                        <select
                                            value={ligne.tva_rate}
                                            onChange={(e) => setLigne(index, { tva_rate: e.target.value })}
                                            className={input}
                                        >
                                            {TVA_RATES.map((rate) => (
                                                <option key={rate} value={rate}>
                                                    {rate} %
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="py-2 pr-2 text-right tabular-nums text-slate-700">
                                        {formatMAD(ligneHt(ligne))}
                                    </td>
                                    <td className="py-2 text-right">
                                        <button
                                            type="button"
                                            onClick={() => setLignes((prev) => prev.filter((_, i) => i !== index))}
                                            disabled={lignes.length === 1}
                                            className="text-red-500 hover:text-red-700 disabled:opacity-30"
                                            title="Supprimer la ligne"
                                        >
                                            ✕
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <button
                        type="button"
                        onClick={() => setLignes((prev) => [...prev, { ...emptyLigne }])}
                        className="mt-3 rounded-md border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:border-emerald-500 hover:text-emerald-600"
                    >
                        + Ajouter une ligne
                    </button>

                    <div className="mt-4 flex justify-end">
                        <div className="w-64 space-y-1 rounded-md bg-slate-50 p-4 text-sm">
                            <div className="flex justify-between text-slate-600">
                                <span>Total HT</span>
                                <span className="tabular-nums">{formatMAD(totalHt)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600">
                                <span>TVA</span>
                                <span className="tabular-nums">{formatMAD(totalTva)}</span>
                            </div>
                            <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold text-slate-900">
                                <span>Total TTC</span>
                                <span className="tabular-nums">{formatMAD(totalHt + totalTva)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <label className={label}>Notes</label>
                    <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} className={input} />
                </div>

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={mutation.isPending || !tiersId}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {mutation.isPending ? 'Enregistrement…' : 'Enregistrer le brouillon'}
                    </button>
                    <Link
                        to={isEdit ? `/ventes/${id}` : '/ventes'}
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        Annuler
                    </Link>
                </div>
            </form>
        </div>
    );
}
