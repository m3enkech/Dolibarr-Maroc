import { useState, type FormEvent } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { ComptaMappingRow, Compte } from '@/types';

export default function PlanComptable({
    comptes,
    classes,
    mappings,
}: {
    comptes: Compte[];
    classes: Record<string, string>;
    mappings: ComptaMappingRow[];
}) {
    const [search, setSearch] = useState('');
    const [code, setCode] = useState('');
    const [label, setLabel] = useState('');
    const [error, setError] = useState<string | null>(null);
    const queryClient = useQueryClient();

    const onError = (err: any) => {
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.');
    };

    const creerCompte = useMutation({
        mutationFn: () => api.post('/compta/comptes', { code, label }),
        onSuccess: () => {
            setCode('');
            setLabel('');
            setError(null);
            queryClient.invalidateQueries({ queryKey: ['compta-comptes'] });
        },
        onError,
    });

    const changerMapping = useMutation({
        mutationFn: ({ cle, compteId }: { cle: string; compteId: number }) =>
            api.put('/compta/mappings', { cle, compte_id: compteId }),
        onSuccess: () => {
            setError(null);
            queryClient.invalidateQueries({ queryKey: ['compta-mappings'] });
        },
        onError,
    });

    const filtres = comptes.filter(
        (compte) =>
            !search ||
            compte.code.includes(search) ||
            compte.label.toLowerCase().includes(search.toLowerCase()),
    );

    const parClasse = Object.entries(classes).map(([classe, classeLabel]) => ({
        classe: parseInt(classe, 10),
        classeLabel,
        comptes: filtres.filter((compte) => compte.classe === parseInt(classe, 10)),
    }));

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        creerCompte.mutate();
    };

    return (
        <div className="space-y-4">
            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <div className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="mb-1 font-medium text-slate-900">Comptes par défaut</h2>
                <p className="mb-4 text-sm text-slate-500">
                    C'est ici que l'ERP traduit vos opérations courantes en comptes CGNC : les écritures
                    sont générées automatiquement, vous n'avez jamais à choisir un compte au quotidien.
                </p>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {mappings.map((mapping) => (
                        <div key={mapping.cle}>
                            <label className="mb-1 block text-xs font-medium text-slate-600">{mapping.label}</label>
                            <select
                                value={mapping.compte_id ?? ''}
                                onChange={(e) =>
                                    changerMapping.mutate({ cle: mapping.cle, compteId: parseInt(e.target.value, 10) })
                                }
                                className={`${input} w-full`}
                            >
                                {comptes.map((compte) => (
                                    <option key={compte.id} value={compte.id}>
                                        {compte.code} — {compte.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-end gap-3 rounded-xl bg-white p-5 shadow-sm">
                <div>
                    <label className="mb-1 block text-xs font-medium text-slate-600">
                        Code CGNC <span className="font-normal text-slate-400">(ex. 71141)</span>
                    </label>
                    <input required value={code} onChange={(e) => setCode(e.target.value)} className={`${input} w-32 font-mono`} />
                </div>
                <div className="flex-1">
                    <label className="mb-1 block text-xs font-medium text-slate-600">Intitulé</label>
                    <input required value={label} onChange={(e) => setLabel(e.target.value)} className={`${input} w-full`} />
                </div>
                <button
                    onClick={handleSubmit}
                    disabled={creerCompte.isPending}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                >
                    + Sous-compte
                </button>
            </div>

            <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Rechercher un compte (code ou intitulé)…"
                className={`${input} w-80`}
            />

            {parClasse.map(({ classe, classeLabel, comptes: comptesClasse }) =>
                comptesClasse.length === 0 ? null : (
                    <div key={classe} className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="border-b border-slate-200 bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-700">
                            Classe {classe} — {classeLabel}
                        </div>
                        <table className="w-full text-left text-sm">
                            <tbody className="divide-y divide-slate-100">
                                {comptesClasse.map((compte) => (
                                    <tr key={compte.id} className="hover:bg-slate-50">
                                        <td className="w-28 px-4 py-2 font-mono text-xs text-slate-700">{compte.code}</td>
                                        <td className="px-4 py-2 text-slate-900">{compte.label}</td>
                                        <td className="w-28 px-4 py-2 text-right">
                                            {!compte.is_system && (
                                                <span className="rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-700">
                                                    personnalisé
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ),
            )}
        </div>
    );
}
