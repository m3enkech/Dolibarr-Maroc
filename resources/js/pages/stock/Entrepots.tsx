import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Entrepot } from '@/types';

export default function Entrepots({ entrepots }: { entrepots: Entrepot[] }) {
    const [name, setName] = useState('');
    const [address, setAddress] = useState('');
    const [error, setError] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['entrepots'] });

    const onError = (err: any) => {
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
    };

    const creer = useMutation({
        mutationFn: () => api.post('/stock/entrepots', { name, address: address || null }),
        onSuccess: () => {
            setName('');
            setAddress('');
            setError(null);
            invalidate();
        },
        onError,
    });

    const definirDefaut = useMutation({
        mutationFn: (id: number) => api.put(`/stock/entrepots/${id}`, { is_default: true }),
        onSuccess: () => {
            setError(null);
            invalidate();
        },
        onError,
    });

    const supprimer = useMutation({
        mutationFn: (id: number) => api.delete(`/stock/entrepots/${id}`),
        onSuccess: () => {
            setError(null);
            invalidate();
        },
        onError,
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        creer.mutate();
    };

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <div className="space-y-4">
            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 shadow-sm">
                <div>
                    <label className="mb-1 block text-xs font-medium text-slate-600">Nom de l'entrepôt</label>
                    <input required value={name} onChange={(e) => setName(e.target.value)} className={`${input} w-64`} />
                </div>
                <div className="flex-1">
                    <label className="mb-1 block text-xs font-medium text-slate-600">Adresse</label>
                    <input value={address} onChange={(e) => setAddress(e.target.value)} className={`${input} w-full`} />
                </div>
                <button
                    type="submit"
                    disabled={creer.isPending}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                >
                    + Ajouter
                </button>
            </form>

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Code</th>
                            <th className="px-4 py-3">Nom</th>
                            <th className="px-4 py-3">Adresse</th>
                            <th className="px-4 py-3">Par défaut</th>
                            <th className="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {entrepots.length === 0 && (
                            <tr>
                                <td colSpan={5} className="px-4 py-8 text-center text-slate-400">
                                    Aucun entrepôt. Le premier créé deviendra l'entrepôt par défaut.
                                </td>
                            </tr>
                        )}
                        {entrepots.map((entrepot) => (
                            <tr key={entrepot.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3 font-mono text-xs text-slate-600">{entrepot.code}</td>
                                <td className="px-4 py-3 font-medium text-slate-900">{entrepot.name}</td>
                                <td className="px-4 py-3 text-slate-600">{entrepot.address ?? '—'}</td>
                                <td className="px-4 py-3">
                                    {entrepot.is_default ? (
                                        <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700">
                                            Par défaut
                                        </span>
                                    ) : (
                                        <button
                                            onClick={() => definirDefaut.mutate(entrepot.id)}
                                            className="text-xs text-emerald-600 hover:underline"
                                        >
                                            Définir par défaut
                                        </button>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-right">
                                    <button
                                        onClick={() =>
                                            window.confirm(`Supprimer « ${entrepot.name} » ?`) &&
                                            supprimer.mutate(entrepot.id)
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

            <p className="text-xs text-slate-500">
                Les sorties de stock automatiques (validation de facture) utilisent l'entrepôt par défaut.
                Un entrepôt ayant des mouvements ne peut pas être supprimé.
            </p>
        </div>
    );
}
