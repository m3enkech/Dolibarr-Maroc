import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { formatMAD } from '@/lib/format';
import { portailApi } from '@/lib/portail-api';
import { useT } from '@/lib/langue';

interface CommandeResume {
    id: number;
    code: string;
    date: string | null;
    etat: string;
    livraison: string;
    total_ttc: string;
    nb_lignes: number;
}

export const ETAT_COMMANDE: Record<string, { libelle: string; classe: string }> = {
    en_attente_confirmation: { libelle: 'En attente de confirmation', classe: 'bg-amber-100 text-amber-700' },
    confirmee: { libelle: 'Confirmée', classe: 'bg-sky-100 text-sky-700' },
    partiellement_livree: { libelle: 'Partiellement livrée', classe: 'bg-indigo-100 text-indigo-700' },
    livree: { libelle: 'Livrée', classe: 'bg-emerald-100 text-emerald-700' },
};

export default function PortailCommandes() {
    const { grossiste } = useParams();
    const t = useT();

    const { data, isLoading } = useQuery({
        queryKey: ['portail-commandes', grossiste],
        queryFn: async () =>
            (await portailApi.get<{ data: CommandeResume[]; meta: { total: number } }>(
                `/grossistes/${grossiste}/commandes`,
            )).data,
    });

    return (
        <div className="space-y-4">
            <h1 className="text-xl font-semibold text-slate-900">{t('Mes commandes')}</h1>

            {isLoading && <p className="text-sm text-slate-400">{t('Chargement…')}</p>}

            {!isLoading && (data?.data ?? []).length === 0 && (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center">
                    <p className="text-sm text-slate-500">{t("Vous n'avez pas encore passé de commande.")}</p>
                    <Link
                        to={`/portail/${grossiste}/catalogue`}
                        className="mt-2 inline-block text-sm font-medium text-emerald-600 hover:underline"
                    >
                        {t('Parcourir le catalogue →')}
                    </Link>
                </div>
            )}

            <div className="space-y-2">
                {(data?.data ?? []).map((c) => {
                    const etat = ETAT_COMMANDE[c.etat] ?? { libelle: c.etat, classe: 'bg-slate-200 text-slate-600' };

                    return (
                        <Link
                            key={c.id}
                            to={`/portail/${grossiste}/commandes/${c.id}`}
                            className="flex items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm transition hover:shadow"
                        >
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-sm text-slate-700">{c.code}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${etat.classe}`}>
                                        {t(etat.libelle)}
                                    </span>
                                </div>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    {c.date} ·{' '}
                                    {/* Deux clés plutôt qu'un « (s) » : le français reste
                                        correct, et l'arabe ne s'accorde pas comme lui. */}
                                    {t(c.nb_lignes > 1 ? '{n} articles' : '{n} article', { n: c.nb_lignes })}
                                </p>
                            </div>
                            <span className="shrink-0 font-semibold tabular-nums text-slate-900">
                                {formatMAD(c.total_ttc)}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
