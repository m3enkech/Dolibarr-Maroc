import { useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';

interface LignePreview {
    code: string;
    libelle: string;
    debit: string;
    credit: string;
    existe: boolean;
}

interface Preview {
    lignes: LignePreview[];
    total_debit: string;
    total_credit: string;
    equilibre: boolean;
    ecart: string;
}

export default function Ouverture() {
    const [fichier, setFichier] = useState<File | null>(null);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const queryClient = useQueryClient();

    const onError = (fallback: string) => (err: any) => {
        setMessage(null);
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : fallback);
    };

    const previsualiser = useMutation({
        mutationFn: (file: File) => {
            const form = new FormData();
            form.append('fichier', file);
            return api.post<Preview>('/compta/ouverture/previsualiser', form);
        },
        onSuccess: ({ data }) => {
            setError(null);
            setMessage(null);
            setPreview(data);
        },
        onError: onError('Fichier illisible (attendu : Excel/CSV avec colonnes Compte, Libellé, Débit, Crédit).'),
    });

    const importer = useMutation({
        mutationFn: () => {
            const form = new FormData();
            form.append('fichier', fichier as File);
            return api.post('/compta/ouverture/importer', form);
        },
        onSuccess: ({ data }) => {
            setError(null);
            setPreview(null);
            setFichier(null);
            if (inputRef.current) inputRef.current.value = '';
            setMessage(`Balance d'ouverture importée — écriture ${data.numero} (${data.lignes} lignes) au ${data.date}.`);
            queryClient.invalidateQueries({ queryKey: ['compta-ecritures'] });
            queryClient.invalidateQueries({ queryKey: ['compta-balance'] });
        },
        onError: onError('Import impossible.'),
    });

    const choisir = (file: File | null) => {
        setFichier(file);
        setPreview(null);
        setMessage(null);
        setError(null);
        if (file) previsualiser.mutate(file);
    };

    const telechargerModele = async () => {
        const response = await api.get('/compta/ouverture/modele', { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'modele-balance-ouverture.xlsx';
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <div className="space-y-4">
            <div className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="font-medium text-slate-900">Reprise des à-nouveaux</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Pour démarrer avec l'historique d'une année gérée hors de l'app, importez la balance
                    de clôture (bilan) de l'exercice précédent. L'app crée une écriture d'à-nouveaux
                    équilibrée au 1er janvier. Colonnes attendues : <strong>Compte, Libellé, Débit, Crédit</strong>.
                </p>
                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <button
                        onClick={telechargerModele}
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    >
                        ⬇ Télécharger le modèle Excel
                    </button>
                    <input
                        ref={inputRef}
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        onChange={(e) => choisir(e.target.files?.[0] ?? null)}
                        className="text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-emerald-700"
                    />
                    {previsualiser.isPending && <span className="text-sm text-slate-400">Lecture du fichier…</span>}
                </div>
            </div>

            {message && <div className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{message}</div>}
            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            {preview && (
                <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                    <div className="border-b border-slate-200 px-4 py-3">
                        <span className="text-sm font-medium text-slate-900">Aperçu — {preview.lignes.length} lignes</span>
                    </div>
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-3">Compte</th>
                                <th className="px-4 py-3">Libellé</th>
                                <th className="px-4 py-3 text-right">Débit</th>
                                <th className="px-4 py-3 text-right">Crédit</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {preview.lignes.map((l, i) => (
                                <tr key={i} className="hover:bg-slate-50">
                                    <td className="px-4 py-2 font-mono text-xs text-slate-700">
                                        {l.code}
                                        {!l.existe && (
                                            <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-700">
                                                nouveau
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-2 text-slate-700">{l.libelle || '—'}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-slate-700">
                                        {parseFloat(l.debit) > 0 ? formatMAD(l.debit) : ''}
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums text-slate-700">
                                        {parseFloat(l.credit) > 0 ? formatMAD(l.credit) : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot className="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                            <tr>
                                <td colSpan={2} className="px-4 py-3 text-slate-900">Totaux</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatMAD(preview.total_debit)}</td>
                                <td className="px-4 py-3 text-right tabular-nums">{formatMAD(preview.total_credit)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
                        {preview.equilibre ? (
                            <span className="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">
                                Balance équilibrée
                            </span>
                        ) : (
                            <span className="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">
                                Déséquilibre : écart {formatMAD(preview.ecart)}
                            </span>
                        )}
                        <button
                            onClick={() => importer.mutate()}
                            disabled={!preview.equilibre || importer.isPending}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {importer.isPending ? 'Import…' : 'Importer la balance d\'ouverture'}
                        </button>
                    </div>
                </div>
            )}

            <p className="text-xs text-slate-500">
                Les comptes absents du plan (badge « nouveau ») seront créés automatiquement. Le report
                à nouveau (résultat cumulé des années précédentes) se saisit sur le compte 1151 (bénéfice)
                ou 1152 (perte). Un seul import par exercice.
            </p>
        </div>
    );
}
