import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { OpportuniteDetail as Detail } from '@/types';
import { useT } from '@/lib/langue';

const ETAPES = ['nouveau', 'qualifie', 'proposition', 'negociation'] as const;

const ETAPE_LABELS: Record<string, string> = {
    nouveau: 'Nouveau',
    qualifie: 'Qualifié',
    proposition: 'Proposition',
    negociation: 'Négociation',
};

const TYPE_ACTIVITE: Record<string, string> = {
    appel: '📞', email: '✉️', reunion: '👥', note: '📝', tache: '☑️',
};

const STATUT_BADGE: Record<string, string> = {
    ouverte: 'bg-indigo-100 text-indigo-700',
    gagnee: 'bg-emerald-100 text-emerald-700',
    perdue: 'bg-red-100 text-red-700',
};

export default function OpportuniteDetail() {
    const t = useT();
    const { id } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['opportunite', id],
        queryFn: async () => (await api.get<{ data: Detail }>(`/crm/opportunites/${id}`)).data.data,
    });

    const refresh = () => {
        queryClient.invalidateQueries({ queryKey: ['opportunite', id] });
        queryClient.invalidateQueries({ queryKey: ['crm-opportunites'] });
        queryClient.invalidateQueries({ queryKey: ['crm-stats'] });
    };

    const deplacer = useMutation({
        mutationFn: (etape: string) => api.post(`/crm/opportunites/${id}/deplacer`, { etape }),
        onSuccess: refresh,
    });

    const cloturer = useMutation({
        mutationFn: (statut: string) => api.post(`/crm/opportunites/${id}/cloturer`, { statut }),
        onSuccess: refresh,
    });

    const rouvrir = useMutation({
        mutationFn: () => api.post(`/crm/opportunites/${id}/rouvrir`),
        onSuccess: refresh,
    });

    const genererDevis = useMutation({
        mutationFn: () => api.post<{ devis_id: number }>(`/crm/opportunites/${id}/devis`),
        onSuccess: ({ data }) => navigate(`/ventes/${data.devis_id}/modifier`),
    });

    if (isLoading || !data) return <div className="text-sm text-slate-400">{t('Chargement…')}</div>;

    const o = data.opportunite;
    const ouverte = o.statut === 'ouverte';

    return (
        <div className="space-y-4">
            {/* En-tête */}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <Link to="/crm" className="text-xs text-slate-500 hover:text-emerald-600">{t('← Retour au pipeline')}</Link>
                    <h1 className="mt-1 text-xl font-semibold text-slate-900">{o.titre}</h1>
                    <p className="mt-0.5 font-mono text-xs text-slate-400">{o.code}</p>
                </div>
                <span className={`rounded-full px-3 py-1 text-xs font-medium ${STATUT_BADGE[o.statut]}`}>
                    {o.statut === 'ouverte' ? 'En cours' : o.statut === 'gagnee' ? 'Gagnée' : 'Perdue'}
                </span>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                {/* Colonne principale */}
                <div className="space-y-4 lg:col-span-2">
                    {/* Étapes du pipeline */}
                    <section className="rounded-xl bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-semibold text-slate-900">{t('Étape')}</h2>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {ETAPES.map((etape) => (
                                <button
                                    key={etape}
                                    disabled={!ouverte || deplacer.isPending}
                                    onClick={() => deplacer.mutate(etape)}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-medium transition disabled:opacity-50 ${
                                        o.etape === etape
                                            ? 'bg-emerald-600 text-white'
                                            : 'border border-slate-200 text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    {ETAPE_LABELS[etape]}
                                </button>
                            ))}
                        </div>

                        <div className="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                            {ouverte ? (
                                <>
                                    <button
                                        onClick={() => cloturer.mutate('gagnee')}
                                        className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700"
                                    >
                                        {t('✓ Marquer gagnée')}
                                    </button>
                                    <button
                                        onClick={() => cloturer.mutate('perdue')}
                                        className="rounded-md border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                                    >
                                        {t('✕ Marquer perdue')}
                                    </button>
                                    <button
                                        onClick={() => genererDevis.mutate()}
                                        disabled={genererDevis.isPending}
                                        className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                                    >
                                        {t('→ Générer un devis')}
                                    </button>
                                </>
                            ) : (
                                <button
                                    onClick={() => rouvrir.mutate()}
                                    className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                                >
                                    {t('↺ Rouvrir')}
                                </button>
                            )}
                        </div>
                    </section>

                    {/* Documents liés */}
                    <section className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">
                            Documents générés ({data.documents.length})
                        </div>
                        {data.documents.length === 0 ? (
                            <div className="px-5 py-6 text-center text-sm text-slate-400">
                                {t('Aucun devis généré depuis cette opportunité.')}
                            </div>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <tbody className="divide-y divide-slate-100">
                                    {data.documents.map((d) => (
                                        <tr key={d.id} className="hover:bg-slate-50">
                                            <td className="px-5 py-2">
                                                <Link to={`/ventes/${d.id}`} className="font-mono text-xs text-emerald-600 hover:underline">
                                                    {d.code}
                                                </Link>
                                            </td>
                                            <td className="px-5 py-2 text-slate-600 capitalize">{d.type}</td>
                                            <td className="px-5 py-2 text-slate-500">{d.date_document}</td>
                                            <td className="px-5 py-2 text-right tabular-nums text-slate-700">
                                                {formatMAD(d.total_ttc)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>

                    {/* Activités */}
                    <section className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-900">
                            Activités ({data.activites.length})
                        </div>
                        {data.activites.length === 0 ? (
                            <div className="px-5 py-6 text-center text-sm text-slate-400">
                                {t("Aucune activité rattachée. Créez-en depuis l'onglet Activités du CRM.")}
                            </div>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {data.activites.map((a) => (
                                    <li key={a.id} className="flex items-start gap-3 px-5 py-3">
                                        <span className="text-base">{TYPE_ACTIVITE[a.type] ?? '•'}</span>
                                        <div className="min-w-0 flex-1">
                                            <div className={`text-sm ${a.fait ? 'text-slate-400 line-through' : 'text-slate-800'}`}>
                                                {a.sujet}
                                            </div>
                                            {a.note && <div className="mt-0.5 text-xs text-slate-500">{a.note}</div>}
                                        </div>
                                        {a.date_prevue && (
                                            <span className="shrink-0 text-xs text-slate-400">{a.date_prevue}</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                {/* Colonne latérale */}
                <div className="space-y-4">
                    <section className="rounded-xl bg-white p-5 shadow-sm">
                        <h2 className="text-sm font-semibold text-slate-900">{t('Informations')}</h2>
                        <dl className="mt-3 space-y-2.5 text-sm">
                            <Info label="Client">
                                <Link to={`/tiers/${o.tiers_id}`} className="text-emerald-600 hover:underline">
                                    {o.tiers ?? '—'}
                                </Link>
                            </Info>
                            <Info label={t('Montant estimé')}>
                                <span className="font-semibold tabular-nums">{formatMAD(o.montant_estime)}</span>
                            </Info>
                            <Info label={t('Probabilité')}>{o.probabilite} %</Info>
                            <Info label="Commercial">{data.vendeur ?? '—'}</Info>
                            <Info label={t('Créée le')}>{data.dates.creee_le ?? '—'}</Info>
                            <Info label={t('Clôture prévue')}>{data.dates.cloture_prevue ?? '—'}</Info>
                            {data.dates.close_le && <Info label={t('Clôturée le')}>{data.dates.close_le}</Info>}
                            <Info label={t('Ancienneté')}>
                                {data.dates.jours_ouverts === null ? '—' : `${data.dates.jours_ouverts} jour(s)`}
                            </Info>
                        </dl>
                    </section>

                    {o.note && (
                        <section className="rounded-xl bg-white p-5 shadow-sm">
                            <h2 className="text-sm font-semibold text-slate-900">{t('Note')}</h2>
                            <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">{o.note}</p>
                        </section>
                    )}
                </div>
            </div>
        </div>
    );
}

function Info({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-3">
            <dt className="shrink-0 text-xs text-slate-500">{label}</dt>
            <dd className="text-right text-slate-700">{children}</dd>
        </div>
    );
}
