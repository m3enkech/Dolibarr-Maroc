import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import type { CategorieProduit, Compte, Paginated } from '@/types';

interface FormState {
    id: number | null;
    name: string;
    compte_vente_id: string;
    compte_achat_id: string;
    is_immobilisation: boolean;
    compte_amortissement_id: string;
    duree_amortissement: string;
}

const emptyForm: FormState = {
    id: null,
    name: '',
    compte_vente_id: '',
    compte_achat_id: '',
    is_immobilisation: false,
    compte_amortissement_id: '',
    duree_amortissement: '',
};

export default function CategoriesProduit() {
    const [form, setForm] = useState<FormState>(emptyForm);
    const [error, setError] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const { data: categories } = useQuery({
        queryKey: ['categories-produit'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<CategorieProduit>>('/categories-produit');
            return data.data;
        },
    });

    const { data: comptes } = useQuery({
        queryKey: ['compta-comptes'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Compte[] }>('/compta/comptes');
            return data.data;
        },
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['categories-produit'] });
        setForm(emptyForm);
        setError(null);
    };

    const onError = (err: any) => {
        const messages = err?.response?.data?.errors;
        setError(
            messages
                ? (Object.values(messages).flat() as string[]).join(' ')
                : err?.response?.data?.message ?? 'Action impossible.',
        );
    };

    const save = useMutation({
        mutationFn: () => {
            const payload = {
                name: form.name,
                compte_vente_id: form.compte_vente_id ? parseInt(form.compte_vente_id, 10) : null,
                compte_achat_id: form.compte_achat_id ? parseInt(form.compte_achat_id, 10) : null,
                is_immobilisation: form.is_immobilisation,
                compte_amortissement_id: form.compte_amortissement_id ? parseInt(form.compte_amortissement_id, 10) : null,
                duree_amortissement: form.duree_amortissement ? parseInt(form.duree_amortissement, 10) : null,
            };
            return form.id
                ? api.put(`/categories-produit/${form.id}`, payload)
                : api.post('/categories-produit', payload);
        },
        onSuccess: invalidate,
        onError,
    });

    const remove = useMutation({
        mutationFn: (id: number) => api.delete(`/categories-produit/${id}`),
        onSuccess: invalidate,
        onError,
    });

    const edit = (cat: CategorieProduit) =>
        setForm({
            id: cat.id,
            name: cat.name,
            compte_vente_id: cat.compte_vente_id ? String(cat.compte_vente_id) : '',
            compte_achat_id: cat.compte_achat_id ? String(cat.compte_achat_id) : '',
            is_immobilisation: cat.is_immobilisation,
            compte_amortissement_id: cat.compte_amortissement_id ? String(cat.compte_amortissement_id) : '',
            duree_amortissement: cat.duree_amortissement ? String(cat.duree_amortissement) : '',
        });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        save.mutate();
    };

    const input =
        'w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
    const label = 'mb-1 block text-xs font-medium text-slate-600';
    const compteOptions = (placeholder: string) => (
        <>
            <option value="">{placeholder}</option>
            {comptes?.map((c) => (
                <option key={c.id} value={c.id}>
                    {c.code} — {c.label}
                </option>
            ))}
        </>
    );

    return (
        <div className="max-w-5xl space-y-4">
            <div>
                <Link to="/catalogue" className="text-sm text-emerald-600 hover:underline">
                    ← Retour au catalogue
                </Link>
                <h1 className="mt-2 text-xl font-semibold text-slate-900">Catégories comptables</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Rattachez des comptes GL de vente et d'achat à une catégorie ; les produits en
                    héritent. Une catégorie « immobilisation » crée automatiquement le bien à l'achat.
                </p>
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-5 shadow-sm">
                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label className={label}>Nom de la catégorie *</label>
                        <input
                            required
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            className={input}
                        />
                    </div>
                    <div>
                        <label className={label}>Compte de vente</label>
                        <select
                            value={form.compte_vente_id}
                            onChange={(e) => setForm((f) => ({ ...f, compte_vente_id: e.target.value }))}
                            className={input}
                        >
                            {compteOptions('— Défaut (par type) —')}
                        </select>
                    </div>
                    <div>
                        <label className={label}>
                            Compte d'achat {form.is_immobilisation && '(classe 2)'}
                        </label>
                        <select
                            value={form.compte_achat_id}
                            onChange={(e) => setForm((f) => ({ ...f, compte_achat_id: e.target.value }))}
                            className={input}
                        >
                            {compteOptions('— Défaut (par type) —')}
                        </select>
                    </div>
                </div>

                <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={form.is_immobilisation}
                        onChange={(e) => setForm((f) => ({ ...f, is_immobilisation: e.target.checked }))}
                        className="rounded border-slate-300"
                    />
                    Catégorie d'immobilisation (l'achat crée un bien amortissable)
                </label>

                {form.is_immobilisation && (
                    <div className="grid gap-4 rounded-md bg-slate-50 p-4 sm:grid-cols-2">
                        <div>
                            <label className={label}>Compte d'amortissement (28xx) *</label>
                            <select
                                value={form.compte_amortissement_id}
                                onChange={(e) => setForm((f) => ({ ...f, compte_amortissement_id: e.target.value }))}
                                className={input}
                            >
                                {compteOptions('— Choisir —')}
                            </select>
                        </div>
                        <div>
                            <label className={label}>Durée d'amortissement (années) *</label>
                            <input
                                type="number"
                                min="1"
                                max="40"
                                value={form.duree_amortissement}
                                onChange={(e) => setForm((f) => ({ ...f, duree_amortissement: e.target.value }))}
                                className={input}
                            />
                        </div>
                    </div>
                )}

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={save.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {form.id ? 'Mettre à jour' : 'Ajouter la catégorie'}
                    </button>
                    {form.id && (
                        <button
                            type="button"
                            onClick={() => setForm(emptyForm)}
                            className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            Annuler
                        </button>
                    )}
                </div>
            </form>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Catégorie</th>
                            <th className="px-4 py-3">Compte vente</th>
                            <th className="px-4 py-3">Compte achat</th>
                            <th className="px-4 py-3">Immo.</th>
                            <th className="px-4 py-3 text-right">Produits</th>
                            <th className="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {categories?.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                                    Aucune catégorie. Les produits utilisent alors les comptes par défaut.
                                </td>
                            </tr>
                        )}
                        {categories?.map((cat) => (
                            <tr key={cat.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3 font-medium text-slate-900">{cat.name}</td>
                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{cat.compte_vente ?? '—'}</td>
                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{cat.compte_achat ?? '—'}</td>
                                <td className="px-4 py-3">
                                    {cat.is_immobilisation ? (
                                        <span className="rounded bg-violet-100 px-1.5 py-0.5 text-xs text-violet-700">
                                            {cat.duree_amortissement} ans · {cat.compte_amortissement}
                                        </span>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                    {cat.produits_count ?? 0}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <button onClick={() => edit(cat)} className="mr-3 text-emerald-600 hover:underline">
                                        Modifier
                                    </button>
                                    <button
                                        onClick={() =>
                                            window.confirm(`Supprimer « ${cat.name} » ?`) && remove.mutate(cat.id)
                                        }
                                        className="text-red-500 hover:underline"
                                    >
                                        Supprimer
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
