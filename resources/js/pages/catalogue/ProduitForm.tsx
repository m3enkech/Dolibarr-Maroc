import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { CategorieProduit, Paginated, Produit } from '@/types';

const TVA_RATES = [20, 14, 10, 7, 0] as const;

interface ComposantRow {
    produit_id: string;
    quantite: string;
}

interface ProduitFormData {
    name: string;
    description: string;
    type: 'product' | 'service' | 'kit';
    categorie_produit_id: string;
    sell_price: string;
    buy_price: string;
    tva_rate: string;
    unit: string;
    stock_min: string;
    stock_reappro: string;
    barcode: string;
    is_active: boolean;
}

const emptyForm: ProduitFormData = {
    name: '',
    description: '',
    type: 'product',
    categorie_produit_id: '',
    sell_price: '',
    buy_price: '',
    tva_rate: '20',
    unit: '',
    stock_min: '',
    stock_reappro: '',
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
        categorie_produit_id: form.categorie_produit_id ? parseInt(form.categorie_produit_id, 10) : null,
        unit: form.unit || null,
        // Seuils de réappro : uniquement pertinents pour les produits stockables.
        stock_min: form.type === 'product' && form.stock_min !== '' ? parseFloat(form.stock_min) : null,
        stock_reappro: form.type === 'product' && form.stock_reappro !== '' ? parseFloat(form.stock_reappro) : null,
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
    const [composants, setComposants] = useState<ComposantRow[]>([]);
    const [error, setError] = useState<string | null>(null);

    const { data: existing } = useQuery({
        queryKey: ['produit-detail', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Produit }>(`/produits/${id}`);
            return data.data;
        },
        enabled: isEdit,
    });

    const { data: categories } = useQuery({
        queryKey: ['categories-produit'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<CategorieProduit>>('/categories-produit');
            return data.data;
        },
    });

    // Options de composition d'un kit : produits et services, jamais un kit.
    const { data: produitsOptions } = useQuery({
        queryKey: ['produits-composants-options'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Produit>>('/produits', { params: { per_page: 500 } });
            return data.data.filter((p) => p.type !== 'kit' && String(p.id) !== id);
        },
        enabled: form.type === 'kit',
    });

    useEffect(() => {
        if (existing) {
            setForm({
                name: existing.name,
                description: existing.description ?? '',
                type: existing.type,
                categorie_produit_id: existing.categorie_produit_id ? String(existing.categorie_produit_id) : '',
                sell_price: existing.sell_price,
                buy_price: existing.buy_price ?? '',
                tva_rate: String(parseFloat(existing.tva_rate)),
                unit: existing.unit ?? '',
                stock_min: existing.stock_min !== null ? String(parseFloat(existing.stock_min)) : '',
                stock_reappro: existing.stock_reappro !== null ? String(parseFloat(existing.stock_reappro)) : '',
                barcode: existing.barcode ?? '',
                is_active: existing.is_active,
            });
            setComposants(
                (existing.composants ?? []).map((c) => ({
                    produit_id: String(c.produit_id),
                    quantite: String(parseFloat(c.quantite)),
                })),
            );
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
        const payload = toPayload(form, isEdit);
        if (form.type === 'kit') {
            payload.composants = composants
                .filter((c) => c.produit_id !== '' && parseFloat(c.quantite) > 0)
                .map((c) => ({ produit_id: parseInt(c.produit_id, 10), quantite: parseFloat(c.quantite) }));
        }
        mutation.mutate(payload);
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
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="kit"
                                        checked={form.type === 'kit'}
                                        onChange={text('type')}
                                        disabled={isEdit}
                                    />
                                    Kit
                                </label>
                            </div>
                        </div>
                        <div>
                            <label className={label}>
                                Catégorie comptable{' '}
                                <span className="font-normal text-slate-400">(comptes GL)</span>
                            </label>
                            <select
                                value={form.categorie_produit_id}
                                onChange={text('categorie_produit_id')}
                                className={input}
                            >
                                <option value="">— Aucune (comptes par défaut) —</option>
                                {categories?.map((cat) => (
                                    <option key={cat.id} value={cat.id}>
                                        {cat.name}
                                        {cat.is_immobilisation ? ' (immobilisation)' : ''}
                                    </option>
                                ))}
                            </select>
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

                {form.type === 'kit' && (
                    <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                        <legend className="sr-only">Composition du kit</legend>
                        <h2 className="mb-1 font-medium text-slate-900">Composition du kit</h2>
                        <p className="mb-4 text-xs text-slate-500">
                            La vente du kit sort du stock chaque composant physique (quantité vendue ×
                            quantité du composant). Les services inclus ne bougent pas le stock.
                        </p>
                        <div className="space-y-2">
                            {composants.map((composant, index) => (
                                <div key={index} className="flex items-center gap-3">
                                    <select
                                        required
                                        value={composant.produit_id}
                                        onChange={(e) =>
                                            setComposants((list) =>
                                                list.map((c, i) => (i === index ? { ...c, produit_id: e.target.value } : c)),
                                            )
                                        }
                                        className={`${input} flex-1`}
                                    >
                                        <option value="">— Choisir un produit ou service —</option>
                                        {produitsOptions?.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name} ({p.code})
                                            </option>
                                        ))}
                                    </select>
                                    <input
                                        type="number"
                                        step="0.001"
                                        min="0.001"
                                        required
                                        value={composant.quantite}
                                        onChange={(e) =>
                                            setComposants((list) =>
                                                list.map((c, i) => (i === index ? { ...c, quantite: e.target.value } : c)),
                                            )
                                        }
                                        className={`${input} w-28`}
                                        placeholder="Qté"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setComposants((list) => list.filter((_, i) => i !== index))}
                                        className="text-red-500 hover:underline"
                                    >
                                        Retirer
                                    </button>
                                </div>
                            ))}
                            {composants.length === 0 && (
                                <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                    Aucun composant. Un kit sans composant se vend comme un service (aucun
                                    mouvement de stock).
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => setComposants((list) => [...list, { produit_id: '', quantite: '1' }])}
                            className="mt-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            + Ajouter un composant
                        </button>
                    </fieldset>
                )}

                {form.type === 'product' && (
                    <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                        <legend className="sr-only">Réapprovisionnement</legend>
                        <h2 className="mb-1 font-medium text-slate-900">Réapprovisionnement</h2>
                        <p className="mb-4 text-xs text-slate-500">
                            Le seuil déclenche une alerte quand le stock passe en dessous. La quantité
                            cible sert à suggérer la quantité à commander. Laissez vide pour ne pas suivre.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className={label}>
                                    Seuil d'alerte{' '}
                                    <span className="font-normal text-slate-400">(stock minimum)</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.stock_min}
                                    onChange={text('stock_min')}
                                    className={input}
                                />
                            </div>
                            <div>
                                <label className={label}>
                                    Quantité cible{' '}
                                    <span className="font-normal text-slate-400">(à réapprovisionner)</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.stock_reappro}
                                    onChange={text('stock_reappro')}
                                    className={input}
                                />
                            </div>
                        </div>
                    </fieldset>
                )}

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
