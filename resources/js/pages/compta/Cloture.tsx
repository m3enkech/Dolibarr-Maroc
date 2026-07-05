import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatMAD } from '@/lib/format';

interface ExerciceRow {
    annee: number;
    statut: 'ouvert' | 'cloture';
    produits?: string;
    charges?: string;
    resultat: string;
    cloture_at?: string;
    ecriture_resultat?: string | null;
    ecriture_an?: string | null;
}

export default function Cloture() {
    const [error, setError] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const queryClient = useQueryClient();
    const { user } = useAuth();
    const isSuperadmin = user?.is_superadmin === true;

    const { data, isLoading } = useQuery({
        queryKey: ['compta-exercices'],
        queryFn: async () => {
            const { data } = await api.get<{ data: ExerciceRow[] }>('/compta/exercices');
            return data.data;
        },
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['compta-exercices'] });
        queryClient.invalidateQueries({ queryKey: ['compta-ecritures'] });
        queryClient.invalidateQueries({ queryKey: ['compta-balance'] });
    };

    const onError = (fallback: string) => (err: any) => {
        setMessage(null);
        const messages = err?.response?.data?.errors;
        setError(messages ? (Object.values(messages).flat() as string[]).join(' ') : fallback);
    };

    const cloturer = useMutation({
        mutationFn: (annee: number) => api.post('/compta/exercices/cloturer', { annee }),
        onSuccess: ({ data }) => {
            setError(null);
            setMessage(
                `Exercice ${data.data.annee} clôturé — résultat ${formatMAD(data.data.resultat)}. ` +
                'Les à-nouveaux ont été générés et l\'exercice est verrouillé.',
            );
            invalidate();
        },
        onError: onError('Clôture impossible.'),
    });

    const rouvrir = useMutation({
        mutationFn: (annee: number) => api.delete(`/compta/exercices/${annee}`),
        onSuccess: (_res, annee) => {
            setError(null);
            setMessage(`Exercice ${annee} rouvert — les écritures de clôture ont été supprimées et le verrou levé.`);
            invalidate();
        },
        onError: onError('Réouverture impossible.'),
    });

    const confirmerReouverture = (exercice: ExerciceRow) => {
        const detail =
            `Rouvrir l'exercice ${exercice.annee} ?\n\n` +
            'Les écritures de détermination du résultat et d\'à-nouveaux seront SUPPRIMÉES ' +
            'et le verrou levé (vous pourrez de nouveau saisir dans cet exercice).';
        if (window.confirm(detail)) {
            rouvrir.mutate(exercice.annee);
        }
    };

    const confirmer = (exercice: ExerciceRow) => {
        const detail =
            `Clôturer l'exercice ${exercice.annee} ?\n\n` +
            `Résultat : ${formatMAD(exercice.resultat)}\n\n` +
            'Cette opération est IRRÉVERSIBLE :\n' +
            '• les comptes de charges et produits seront soldés vers le résultat (1161/1162)\n' +
            `• les à-nouveaux seront générés au 01/01/${exercice.annee + 1}\n` +
            `• plus aucune écriture ne pourra être datée en ${exercice.annee}`;

        if (window.confirm(detail)) {
            cloturer.mutate(exercice.annee);
        }
    };

    // Seul le plus ancien exercice ouvert est clôturable (chronologie imposée).
    const premierOuvert = data?.find((e) => e.statut === 'ouvert')?.annee;
    // Seul le dernier exercice clôturé est rouvrable.
    const dernierCloture = data
        ?.filter((e) => e.statut === 'cloture')
        .reduce<number | undefined>((max, e) => (max === undefined || e.annee > max ? e.annee : max), undefined);

    return (
        <div className="space-y-4">
            {message && <div className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{message}</div>}
            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Exercice</th>
                            <th className="px-4 py-3">Statut</th>
                            <th className="px-4 py-3 text-right">Produits</th>
                            <th className="px-4 py-3 text-right">Charges</th>
                            <th className="px-4 py-3 text-right">Résultat</th>
                            <th className="px-4 py-3">Écritures de clôture</th>
                            <th className="px-4 py-3 text-right"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-slate-400">Chargement…</td></tr>
                        )}
                        {data?.map((exercice) => {
                            const resultatNum = parseFloat(exercice.resultat);
                            return (
                                <tr key={exercice.annee} className="hover:bg-slate-50">
                                    <td className="px-4 py-3 text-base font-semibold text-slate-900">{exercice.annee}</td>
                                    <td className="px-4 py-3">
                                        {exercice.statut === 'cloture' ? (
                                            <span className="rounded bg-slate-200 px-1.5 py-0.5 text-xs text-slate-700">
                                                🔒 Clôturé{exercice.cloture_at ? ` le ${exercice.cloture_at}` : ''}
                                            </span>
                                        ) : (
                                            <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700">
                                                Ouvert
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                        {exercice.produits !== undefined ? formatMAD(exercice.produits) : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                        {exercice.charges !== undefined ? formatMAD(exercice.charges) : '—'}
                                    </td>
                                    <td
                                        className={`px-4 py-3 text-right font-semibold tabular-nums ${
                                            resultatNum > 0 ? 'text-emerald-700' : resultatNum < 0 ? 'text-red-600' : 'text-slate-500'
                                        }`}
                                    >
                                        {formatMAD(exercice.resultat)}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-slate-500">
                                        {exercice.statut === 'cloture'
                                            ? [exercice.ecriture_resultat, exercice.ecriture_an].filter(Boolean).join(' · ') || '—'
                                            : ''}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {exercice.statut === 'ouvert' && exercice.annee === premierOuvert && (
                                            <button
                                                onClick={() => confirmer(exercice)}
                                                disabled={cloturer.isPending}
                                                className="rounded-md bg-slate-800 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-slate-900 disabled:opacity-50"
                                            >
                                                🔒 Clôturer
                                            </button>
                                        )}
                                        {isSuperadmin && exercice.statut === 'cloture' && exercice.annee === dernierCloture && (
                                            <button
                                                onClick={() => confirmerReouverture(exercice)}
                                                disabled={rouvrir.isPending}
                                                className="rounded-md border border-amber-400 px-3 py-1.5 text-sm font-medium text-amber-700 transition hover:bg-amber-50 disabled:opacity-50"
                                            >
                                                🔓 Rouvrir
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            <p className="text-xs text-slate-500">
                La clôture solde les classes 6 et 7 vers le résultat (1161 bénéfice / 1162 perte),
                génère les à-nouveaux au 1er janvier suivant (journal AN) et verrouille définitivement
                l'exercice : plus aucune écriture ni facture ne pourra y être datée. Les exercices se
                clôturent dans l'ordre chronologique. La réouverture (« Rouvrir ») est réservée au
                superadmin de la plateforme ; les comptes entreprises ne peuvent pas défaire une clôture.
            </p>
        </div>
    );
}
