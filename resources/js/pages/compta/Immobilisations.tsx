import { Fragment, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';

interface Immo {
    id: number;
    code: string;
    label: string;
    category: string;
    date_acquisition: string;
    valeur_acquisition: string;
    duree_annees: number;
    compte_immo: string | null;
    compte_amort: string | null;
    statut: 'en_service' | 'cede';
    date_cession: string | null;
    valeur_cession: string | null;
    cumul_amortissement: string;
    vna: string;
    facture_achat?: string | null;
}

interface Categorie {
    cle: string;
    label: string;
    compte_immo: string;
    compte_amort: string;
    duree: number;
}

interface PlanRow {
    annee: number;
    mois: number;
    dotation: string;
    cumul: string;
    vna: string;
}

export default function Immobilisations() {
    const [showForm, setShowForm] = useState(false);
    const [expanded, setExpanded] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const anneeCourante = new Date().getFullYear();

    const [label, setLabel] = useState('');
    const [category, setCategory] = useState('materiel_informatique');
    const [dateAcq, setDateAcq] = useState(`${anneeCourante}-01-01`);
    const [valeur, setValeur] = useState('');
    const [duree, setDuree] = useState('5');

    const { data: categories } = useQuery({
        queryKey: ['immo-categories'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Categorie[] }>('/compta/immobilisations/categories');
            return data.data;
        },
    });

    const { data, isLoading } = useQuery({
        queryKey: ['immobilisations'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Immo[]; meta: { total: number } }>('/compta/immobilisations', {
                params: { per_page: 100 },
            });
            return data.data;
        },
    });

    const { data: detail } = useQuery({
        queryKey: ['immo-detail', expanded],
        queryFn: async () => {
            const { data } = await api.get<{ plan: PlanRow[]; dotations_comptabilisees: { annee: number; montant: string }[] }>(
                `/compta/immobilisations/${expanded}`,
            );
            return data;
        },
        enabled: expanded !== null,
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['immobilisations'] });
        queryClient.invalidateQueries({ queryKey: ['immo-detail'] });
        queryClient.invalidateQueries({ queryKey: ['compta-ecritures'] });
        queryClient.invalidateQueries({ queryKey: ['compta-balance'] });
    };

    const onError = (err: any) => {
        setMessage(null);
        const messages = err?.response?.data?.errors;
        setError(
            messages
                ? (Object.values(messages).flat() as string[]).join(' ')
                : err?.response?.data?.message ?? 'Action impossible.',
        );
    };

    const creer = useMutation({
        mutationFn: () =>
            api.post('/compta/immobilisations', {
                label,
                category,
                date_acquisition: dateAcq,
                valeur_acquisition: parseFloat(valeur),
                duree_annees: parseInt(duree, 10),
            }),
        onSuccess: () => {
            setError(null);
            setLabel('');
            setValeur('');
            setShowForm(false);
            invalidate();
        },
        onError,
    });

    const dotations = useMutation({
        mutationFn: (annee: number) => api.post('/compta/immobilisations/dotations', { annee }),
        onSuccess: ({ data }) => {
            setError(null);
            setMessage(
                data.immobilisations > 0
                    ? `Dotations ${data.ecriture} : ${data.immobilisations} immobilisation(s), ${formatMAD(data.total)} — écriture générée.`
                    : 'Aucune dotation à générer pour cette année (déjà fait ou aucun bien concerné).',
            );
            invalidate();
        },
        onError,
    });

    const ceder = useMutation({
        mutationFn: ({ id, date, prix, taux }: { id: number; date: string; prix: number; taux: number }) =>
            api.post(`/compta/immobilisations/${id}/ceder`, {
                date_cession: date,
                valeur_cession: prix,
                tva_rate: taux,
            }),
        onSuccess: () => {
            setError(null);
            setMessage('Immobilisation cédée — sortie de l\'actif + produit avec TVA collectée générés.');
            invalidate();
        },
        onError,
    });

    const onCategory = (cle: string) => {
        setCategory(cle);
        const cat = categories?.find((c) => c.cle === cle);
        if (cat) setDuree(String(cat.duree));
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        creer.mutate();
    };

    const handleCeder = (immo: Immo) => {
        const date = window.prompt(
            `Céder « ${immo.label} » — date de cession (AAAA-MM-JJ) :`,
            `${anneeCourante}-12-31`,
        );
        if (!date) return;
        const prixStr = window.prompt('Prix de cession HT en MAD (0 = mise au rebut) :', immo.vna);
        if (prixStr === null) return;
        const prix = parseFloat(prixStr) || 0;
        let taux = 0;
        if (prix > 0) {
            const tauxStr = window.prompt('Taux de TVA collectée sur la cession (%) — 0 si exonéré :', '20');
            if (tauxStr === null) return;
            taux = parseFloat(tauxStr) || 0;
        }
        ceder.mutate({ id: immo.id, date, prix, taux });
    };

    const catLabel = (cle: string) => categories?.find((c) => c.cle === cle)?.label ?? cle;

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <button
                    onClick={() => setShowForm((v) => !v)}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + Immobilisation
                </button>
                <button
                    onClick={() => dotations.mutate(anneeCourante)}
                    disabled={dotations.isPending}
                    className="rounded-md border border-emerald-600 px-4 py-2 text-sm font-medium text-emerald-700 transition hover:bg-emerald-50 disabled:opacity-50"
                >
                    ⚙ Générer les dotations {anneeCourante}
                </button>
                <span className="text-xs text-slate-500">
                    L'acquisition se comptabilise via la facture fournisseur ou une OD ; ce module gère
                    l'amortissement et la cession.
                </span>
            </div>

            {message && <div className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{message}</div>}
            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            {showForm && (
                <form onSubmit={handleSubmit} className="rounded-xl bg-white p-5 shadow-sm">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="lg:col-span-2">
                            <label className="mb-1 block text-xs font-medium text-slate-600">Désignation</label>
                            <input required value={label} onChange={(e) => setLabel(e.target.value)} className={`${input} w-full`} />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Catégorie</label>
                            <select value={category} onChange={(e) => onCategory(e.target.value)} className={`${input} w-full`}>
                                {categories?.map((cat) => (
                                    <option key={cat.cle} value={cat.cle}>
                                        {cat.label} ({cat.compte_immo})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Date d'acquisition</label>
                            <input type="date" value={dateAcq} onChange={(e) => setDateAcq(e.target.value)} className={`${input} w-full`} />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Valeur HT (MAD)</label>
                            <input type="number" step="0.01" min="0" required value={valeur} onChange={(e) => setValeur(e.target.value)} className={`${input} w-full`} />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-600">Durée (années)</label>
                            <input type="number" min="1" max="40" required value={duree} onChange={(e) => setDuree(e.target.value)} className={`${input} w-full`} />
                        </div>
                    </div>
                    <div className="mt-4">
                        <button
                            type="submit"
                            disabled={creer.isPending}
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
                            <th className="px-4 py-3">Désignation</th>
                            <th className="px-4 py-3">Acquisition</th>
                            <th className="px-4 py-3 text-right">Valeur HT</th>
                            <th className="px-4 py-3 text-right">Cumul amort.</th>
                            <th className="px-4 py-3 text-right">VNA</th>
                            <th className="px-4 py-3">Statut</th>
                            <th className="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={8} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
                        )}
                        {!isLoading && data?.length === 0 && (
                            <tr>
                                <td colSpan={8} className="px-4 py-8 text-center text-slate-400">
                                    Aucune immobilisation enregistrée.
                                </td>
                            </tr>
                        )}
                        {data?.map((immo) => (
                            <Fragment key={immo.id}>
                                <tr className="hover:bg-slate-50">
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{immo.code}</td>
                                    <td className="px-4 py-3">
                                        <button
                                            onClick={() => setExpanded(expanded === immo.id ? null : immo.id)}
                                            className="font-medium text-slate-900 hover:text-emerald-700"
                                        >
                                            {immo.label}
                                        </button>
                                        <div className="text-xs text-slate-400">
                                            {catLabel(immo.category)} · {immo.duree_annees} ans · {immo.compte_immo}
                                            {immo.facture_achat && (
                                                <span className="ml-1 rounded bg-teal-100 px-1.5 py-0.5 text-teal-700">
                                                    ⇐ {immo.facture_achat}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 text-slate-600">{immo.date_acquisition}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-700">{formatMAD(immo.valeur_acquisition)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">{formatMAD(immo.cumul_amortissement)}</td>
                                    <td className="px-4 py-3 text-right tabular-nums font-medium text-slate-900">{formatMAD(immo.vna)}</td>
                                    <td className="px-4 py-3">
                                        {immo.statut === 'cede' ? (
                                            <span className="rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-700">
                                                Cédée {immo.date_cession}
                                            </span>
                                        ) : (
                                            <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700">
                                                En service
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        <button
                                            onClick={() => setExpanded(expanded === immo.id ? null : immo.id)}
                                            className="mr-3 text-emerald-600 hover:underline"
                                        >
                                            Plan
                                        </button>
                                        {immo.statut === 'en_service' && (
                                            <button onClick={() => handleCeder(immo)} className="text-amber-600 hover:underline">
                                                Céder
                                            </button>
                                        )}
                                    </td>
                                </tr>
                                {expanded === immo.id && detail && (
                                    <tr>
                                        <td colSpan={8} className="bg-slate-50 px-8 py-4">
                                            <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                                Plan d'amortissement linéaire
                                            </div>
                                            <table className="w-full max-w-2xl text-xs">
                                                <thead className="text-slate-500">
                                                    <tr>
                                                        <th className="pb-1 text-left">Année</th>
                                                        <th className="pb-1 text-right">Mois</th>
                                                        <th className="pb-1 text-right">Dotation</th>
                                                        <th className="pb-1 text-right">Cumul</th>
                                                        <th className="pb-1 text-right">VNA</th>
                                                        <th className="pb-1 text-right">Comptabilisée</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {detail.plan.map((row) => {
                                                        const posted = detail.dotations_comptabilisees.find((d) => d.annee === row.annee);
                                                        return (
                                                            <tr key={row.annee} className="border-t border-slate-200">
                                                                <td className="py-1">{row.annee}</td>
                                                                <td className="py-1 text-right text-slate-400">{row.mois}</td>
                                                                <td className="py-1 text-right tabular-nums">{formatMAD(row.dotation)}</td>
                                                                <td className="py-1 text-right tabular-nums text-slate-500">{formatMAD(row.cumul)}</td>
                                                                <td className="py-1 text-right tabular-nums">{formatMAD(row.vna)}</td>
                                                                <td className="py-1 text-right">
                                                                    {posted ? (
                                                                        <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-700">✓</span>
                                                                    ) : (
                                                                        <span className="text-slate-300">—</span>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
