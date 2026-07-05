import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { Produit } from '@/types';

const TVA_RATES = [20, 14, 10, 7, 0] as const;

interface ProduitFormData {
    name: string;
    description: string;
    type: 'product' | 'service';
    sell_price: string;
    buy_price: string;
    tva_rate: string;
    unit: string;
    barcode: string;
    is_active: boolean;
}

const emptyForm: ProduitFormData = {
    name: '',
    description: '',
    type: 'product',
    sell_price: '',
    buy_price: '',
    tva_rate: '20',
    unit: '',
    barcode: '',
    is_active: true,
};

function toPayload(form: ProduitFormData, isEdit: boolean) {
    const payload: Record<string, unknown> = {
        name: form.name,
        description: form.description || null,
        sell_price: parseFloat(form.sell_price || '0'),
        buy_price: form.buy_price === '' ? null : parseFloat(form.buy_price),
        tva_rate: parseFloat(form.tva_rate),
        unit: form.unit || null,
        barcode: form.barcode || null,
        is_active: form.is_active,
    };
    if (!isEdit) {
        payload.type = form.type;
    }
    return payload;
}

export default function ProduitForm() {
    const { id } = useParams();
    const isEdit = id !== undefined;
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<ProduitFormData>(emptyForm);
    const [error, setError] = useState<string | null>(null);

    const { data: existing } = useQuery({
        queryKey: ['produit-detail', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Produit }>(`/produits/${id}`);
            return data.data;
        },
        enabled: isEdit,
    });

    useEffect(() => {
        if (existing) {
            setForm({
                name: existing.name,
                description: existing.description ?? '',
                type: existing.type,
                sell_price: existing.sell_price,
                buy_price: existing.buy_price ?? '',
                tva_rate: String(parseFloat(existing.tva_rate)),
                unit: existing.unit ?? '',
                barcode: existing.barcode ?? '',
                is_active: existing.is_active,
            });
        }
    }, [existing]);

    const mutation = useMutation({
        mutationFn: (payload: Record<string, unknown>) =>
            isEdit ? api.put(`/produits/${id}`, payload) : api.post('/produits', payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['produits'] });
            queryClient.invalidateQueries({ queryKey: ['produits-count'] });
            navigate('/catalogue');
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
        mutation.mutate(toPayload(form, isEdit));
    };

    const text =
        (key: keyof ProduitFormData) =>
        (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
            setForm((f) => ({ ...f, [key]: e.target.value }));

    const sellPrice = parseFloat(form.sell_price || '0');
    const tva = parseFloat(form.tva_rate);
    const ttc = Math.round(sellPrice * (1 + tva / 100) * 100) / 100;

    const input =
        'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
    const label = 'mb-1 block text-sm font-medium text-slate-700';

    return (
        <div className="max-w-3xl space-y-4">
            <div>
                <Link to="/catalogue" className="text-sm text-emerald-600 hover:underline">
                    ← Retour au catalogue
                </Link>
                <h1 className="mt-2 text-xl font-semibold text-slate-900">
                    {isEdit ? `Modifier ${existing?.name ?? ''}` : 'Nouveau produit ou service'}
                </h1>
                {isEdit && existing && (
                    <p className="mt-1 font-mono text-xs text-slate-500">{existing.code}</p>
                )}
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
                <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">Identité</legend>
                    <h2 className="mb-4 font-medium text-slate-900">Identité</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className={label}>Désignation *</label>
                            <input required value={form.name} onChange={text('name')} className={input} />
                        </div>
                        <div>
                            <span className={label}>Type {isEdit && '(immuable)'}</span>
                            <div className="flex gap-4 pt-2">
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="product"
                                        checked={form.type === 'product'}
                                        onChange={text('type')}
                                        disabled={isEdit}
                                    />
                                    Produit
                                </label>
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="service"
                                        checked={form.type === 'service'}
                                        onChange={text('type')}
                                        disabled={isEdit}
                                    />
                                    Service
                                </label>
                            </div>
                        </div>
                        <div>
                            <label className={label}>
                                Unité <span className="font-normal text-slate-400">(kg, m³, heure…)</span>
                            </label>
                            <input value={form.unit} onChange={text('unit')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Code-barres</label>
                            <input value={form.barcode} onChange={text('barcode')} className={input} />
                        </div>
                        <div className="flex items-end pb-2">
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
                                    className="rounded border-slate-300"
                                />
                                Actif
                            </label>
                        </div>
                        <div className="sm:col-span-2">
                            <label className={label}>Description</label>
                            <textarea
                                value={form.description}
                                onChange={text('description')}
                                rows={3}
                                className={input}
                            />
                        </div>
                    </div>
                </fieldset>

                <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">Prix et TVA</legend>
                    <h2 className="mb-4 font-medium text-slate-900">Prix & TVA</h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label className={label}>Prix de vente HT (MAD) *</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                required
                                value={form.sell_price}
                                onChange={text('sell_price')}
                                className={input}
                            />
                        </div>
                        <div>
                            <label className={label}>Prix d'achat HT (MAD)</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.buy_price}
                                onChange={text('buy_price')}
                                className={input}
                            />
                        </div>
                        <div>
                            <label className={label}>Taux de TVA</label>
                            <select value={form.tva_rate} onChange={text('tva_rate')} className={input}>
                                {TVA_RATES.map((rate) => (
                                    <option key={rate} value={rate}>
                                        {rate === 0 ? 'Exonéré (0 %)' : `${rate} %`}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        Prix de vente TTC :{' '}
                        <span className="font-semibold tabular-nums">{formatMAD(ttc)}</span>
                    </div>
                </fieldset>

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={mutation.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {mutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                    <Link
                        to="/catalogue"
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        Annuler
                    </Link>
                </div>
            </form>
        </div>
    );
}
