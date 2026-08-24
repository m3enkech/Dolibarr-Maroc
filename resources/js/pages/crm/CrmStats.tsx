import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { LEAD_SOURCE_LABELS, type CrmStats as Stats } from '@/types';
import { useT } from '@/lib/langue';

const ETAPE_LABELS: Record<string, string> = {
    nouveau: 'Nouveau',
    qualifie: 'Qualifié',
    proposition: 'Proposition',
    negociation: 'Négociation',
};

const PERIODES = [
    { key: '', label: 'Tout' },
    { key: '90', label: '90 jours' },
    { key: '365', label: '12 mois' },
];

// Classes écrites en toutes lettres : Tailwind ne compile pas les noms de
// classes construits dynamiquement (`text-${tone}-700` ne produit rien).
const TONE_TEXT: Record<string, string> = {
    slate: 'text-slate-800',
    emerald: 'text-emerald-700',
    indigo: 'text-indigo-700',
    red: 'text-red-600',
};

const TONE_BAR: Record<string, string> = {
    emerald: 'bg-emerald-500',
    indigo: 'bg-indigo-500',
};

function Kpi({ label, value, hint, tone = 'slate' }: { label: string; value: string; hint?: string; tone?: string }) {
    return (
        <div className="rounded-xl bg-white p-4 shadow-sm">
            <div className="text-xs text-slate-500">{label}</div>
            <div className={`mt-1 text-2xl font-semibold tabular-nums ${TONE_TEXT[tone] ?? TONE_TEXT.slate}`}>{value}</div>
            {hint && <div className="mt-0.5 text-xs text-slate-400">{hint}</div>}
        </div>
    );
}

/** Barre de progression horizontale simple (part relative au max). */
function Barre({ ratio, tone = 'emerald' }: { ratio: number; tone?: string }) {
    return (
        <div className="h-2 w-full rounded-full bg-slate-100">
            <div
                className={`h-2 rounded-full ${TONE_BAR[tone] ?? TONE_BAR.emerald}`}
                style={{ width: `${Math.max(2, Math.round(ratio * 100))}%` }}
            />
        </div>
    );
}

export default function CrmStats() {
    const t = useT();
    const [periode, setPeriode] = useState('');

    const depuis = periode
        ? new Date(Date.now() - Number(periode) * 86400000).toISOString().slice(0, 10)
        : undefined;

    const { data, isLoading } = useQuery({
        queryKey: ['crm-stats', periode],
        queryFn: async () =>
            (await api.get<{ data: Stats }>('/crm/stats', { params: { depuis } })).data.data,
    });

    if (isLoading || !data) return <div className="text-sm text-slate-400">{t('Chargement…')}</div>;

    const s = data.synthese;
    const maxEtape = Math.max(1, ...data.par_etape.map((e) => parseFloat(e.montant)));
    const maxVendeur = Math.max(1, ...data.par_vendeur.map((v) => parseFloat(v.montant_gagne)));

    return (
        <div className="space-y-4">
            <div className="flex rounded-lg border border-slate-200 bg-white p-1" style={{ width: 'fit-content' }}>
                {PERIODES.map((p) => (
                    <button
                        key={p.key}
                        onClick={() => setPeriode(p.key)}
                        className={`rounded-md px-3 py-1 text-xs font-medium transition ${
                            periode === p.key ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        {t(p.label)}
                    </button>
                ))}
            </div>

            {/* KPIs */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Kpi
                    label={t('Taux de conversion')}
                    value={`${s.taux_conversion} %`}
                    hint={`${s.gagnees} gagnées / ${s.gagnees + s.perdues} tranchées`}
                    tone="emerald"
                />
                <Kpi label={t('Montant gagné')} value={formatMAD(s.montant_gagne)} hint={`${s.gagnees} affaire(s)`} tone="emerald" />
                <Kpi label="Pipeline ouvert" value={formatMAD(s.pipeline_ouvert)} hint={`${s.ouvertes} en cours`} tone="indigo" />
                <Kpi label={t('Prévision pondérée')} value={formatMAD(s.forecast_pondere)} hint={t('pipeline × probabilité')} tone="indigo" />
                <Kpi label={t('Panier moyen gagné')} value={formatMAD(s.panier_moyen_gagne)} />
                <Kpi label={t('Montant perdu')} value={formatMAD(s.montant_perdu)} hint={`${s.perdues} affaire(s)`} tone="red" />
                <Kpi
                    label={t('Durée moyenne du cycle')}
                    value={s.duree_moyenne_jours === null ? '—' : `${s.duree_moyenne_jours} j`}
                    hint={t('création → clôture')}
                />
                <Kpi
                    label="Entonnoir"
                    value={`${data.transformation.devis} devis`}
                    hint={`${data.transformation.taux_devis} % des ${data.transformation.opportunites} opportunités`}
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                {/* Pipeline par étape */}
                <section className="rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="text-sm font-semibold text-slate-900">{t('Pipeline par étape')}</h2>
                    <div className="mt-3 space-y-3">
                        {data.par_etape.map((e) => (
                            <div key={e.etape}>
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-600">
                                        {ETAPE_LABELS[e.etape] ?? e.etape}{' '}
                                        <span className="text-slate-400">({e.nombre})</span>
                                    </span>
                                    <span className="tabular-nums text-slate-700">{formatMAD(e.montant)}</span>
                                </div>
                                <div className="mt-1">
                                    <Barre ratio={parseFloat(e.montant) / maxEtape} tone="indigo" />
                                </div>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Origines de lead */}
                <section className="rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="text-sm font-semibold text-slate-900">{t('Origine des prospects')}</h2>
                    {data.par_source.length === 0 ? (
                        <p className="mt-3 text-sm text-slate-400">
                            Aucune origine renseignée. Ajoutez une source sur vos prospects pour mesurer vos canaux
                            d'acquisition.
                        </p>
                    ) : (
                        <table className="mt-3 w-full text-left text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="pb-2">{t('Source')}</th>
                                    <th className="pb-2 text-right">{t('Total')}</th>
                                    <th className="pb-2 text-right">{t('Prospects')}</th>
                                    <th className="pb-2 text-right">{t('Convertis')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.par_source.map((src) => (
                                    <tr key={src.source}>
                                        <td className="py-2 text-slate-700">{LEAD_SOURCE_LABELS[src.source] ?? src.source}</td>
                                        <td className="py-2 text-right tabular-nums text-slate-600">{src.total}</td>
                                        <td className="py-2 text-right tabular-nums text-indigo-600">{src.prospects}</td>
                                        <td className="py-2 text-right tabular-nums font-medium text-emerald-600">
                                            {src.convertis}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </section>
            </div>

            {/* Performance par commercial */}
            <section className="overflow-hidden rounded-xl bg-white shadow-sm">
                <div className="border-b border-slate-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-slate-900">{t('Performance par commercial')}</h2>
                    <p className="mt-0.5 text-xs text-slate-500">
                        Basée sur les opportunités affectées à chaque utilisateur (montants estimés).
                    </p>
                </div>
                {data.par_vendeur.length === 0 ? (
                    <div className="px-5 py-8 text-center text-sm text-slate-400">{t('Aucune opportunité sur la période.')}</div>
                ) : (
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-2">{t('Commercial')}</th>
                                <th className="px-5 py-2 text-right">{t('Gagnées')}</th>
                                <th className="px-5 py-2 text-right">{t('Perdues')}</th>
                                <th className="px-5 py-2 text-right">{t('En cours')}</th>
                                <th className="px-5 py-2 text-right">{t('Taux')}</th>
                                <th className="px-5 py-2 text-right">{t('Montant gagné')}</th>
                                <th className="px-5 py-2 w-32" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {data.par_vendeur.map((v) => (
                                <tr key={v.user_id ?? 'na'}>
                                    <td className="px-5 py-2 font-medium text-slate-800">{v.vendeur}</td>
                                    <td className="px-5 py-2 text-right tabular-nums text-emerald-600">{v.gagnees}</td>
                                    <td className="px-5 py-2 text-right tabular-nums text-red-500">{v.perdues}</td>
                                    <td className="px-5 py-2 text-right tabular-nums text-slate-600">{v.ouvertes}</td>
                                    <td className="px-5 py-2 text-right tabular-nums font-medium text-slate-800">
                                        {v.taux_conversion} %
                                    </td>
                                    <td className="px-5 py-2 text-right tabular-nums text-slate-700">
                                        {formatMAD(v.montant_gagne)}
                                    </td>
                                    <td className="px-5 py-2">
                                        <Barre ratio={parseFloat(v.montant_gagne) / maxVendeur} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </section>
        </div>
    );
}
