import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatMAD } from '@/lib/format';
import type { DashboardData, DashboardKpi } from '@/types';

const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function moisLabel(cle: string): string {
    const m = parseInt(cle.slice(5, 7), 10);
    return MOIS_COURTS[m - 1] ?? cle;
}

/** Carte KPI avec valeur et, optionnellement, la variation vs mois précédent. */
function KpiCard({
    label,
    value,
    kpi,
    tone = 'slate',
    sub,
}: {
    label: string;
    value: string;
    kpi?: DashboardKpi;
    tone?: 'slate' | 'emerald' | 'amber' | 'red';
    sub?: string;
}) {
    const v = kpi?.variation_pct;
    const hausse = typeof v === 'number' && v > 0;
    const baisse = typeof v === 'number' && v < 0;
    const toneCls = {
        slate: 'text-slate-900',
        emerald: 'text-emerald-600',
        amber: 'text-amber-600',
        red: 'text-red-600',
    }[tone];

    return (
        <div className="rounded-xl bg-white p-5 shadow-sm">
            <div className="text-sm text-slate-500">{label}</div>
            <div className={`mt-1 text-2xl font-semibold ${toneCls}`}>{value}</div>
            <div className="mt-1 flex items-center gap-2 text-xs">
                {typeof v === 'number' && (
                    <span className={hausse ? 'text-emerald-600' : baisse ? 'text-red-600' : 'text-slate-400'}>
                        {hausse ? '▲' : baisse ? '▼' : '='} {Math.abs(v)}%
                    </span>
                )}
                {sub && <span className="text-slate-400">{sub}</span>}
            </div>
        </div>
    );
}

/** Graphe en barres du CA sur 12 mois, avec une courbe des achats en surimpression. */
function BarChart12({ data, withAchats }: { data: DashboardData['ventes_12_mois']; withAchats: boolean }) {
    const W = 720;
    const H = 240;
    const padL = 8;
    const padB = 22;
    const padT = 12;
    const n = data.length || 1;
    const slot = (W - padL) / n;
    const bw = slot * 0.55;

    const max = Math.max(1, ...data.map((d) => Math.max(d.ca, d.achats ?? 0)));
    const y = (val: number) => padT + (H - padT - padB) * (1 - val / max);
    const x = (i: number) => padL + slot * i + (slot - bw) / 2;

    const achatsPts = data
        .map((d, i) => `${x(i) + bw / 2},${y(d.achats ?? 0)}`)
        .join(' ');

    return (
        <div className="overflow-x-auto">
            <svg viewBox={`0 0 ${W} ${H}`} className="w-full min-w-[560px]" role="img" aria-label="CA 12 mois">
                {/* lignes de repère */}
                {[0.25, 0.5, 0.75, 1].map((t) => (
                    <line
                        key={t}
                        x1={padL}
                        x2={W}
                        y1={y(max * t)}
                        y2={y(max * t)}
                        stroke="#f1f5f9"
                        strokeWidth={1}
                    />
                ))}
                {/* barres CA */}
                {data.map((d, i) => (
                    <g key={d.mois}>
                        <rect
                            x={x(i)}
                            y={y(d.ca)}
                            width={bw}
                            height={Math.max(0, H - padB - y(d.ca))}
                            rx={3}
                            fill="#059669"
                        >
                            <title>{`${moisLabel(d.mois)} : ${formatMAD(d.ca)}`}</title>
                        </rect>
                        <text x={x(i) + bw / 2} y={H - 6} textAnchor="middle" className="fill-slate-400" fontSize={10}>
                            {moisLabel(d.mois)}
                        </text>
                    </g>
                ))}
                {/* courbe achats */}
                {withAchats && (
                    <polyline points={achatsPts} fill="none" stroke="#f59e0b" strokeWidth={2} strokeLinejoin="round" />
                )}
                {withAchats &&
                    data.map((d, i) => (
                        <circle key={`a-${d.mois}`} cx={x(i) + bw / 2} cy={y(d.achats ?? 0)} r={2.5} fill="#f59e0b">
                            <title>{`Achats ${moisLabel(d.mois)} : ${formatMAD(d.achats ?? 0)}`}</title>
                        </circle>
                    ))}
            </svg>
            <div className="mt-2 flex gap-4 text-xs text-slate-500">
                <span className="flex items-center gap-1.5">
                    <span className="inline-block h-2.5 w-2.5 rounded-sm bg-emerald-600" /> Chiffre d'affaires
                </span>
                {withAchats && (
                    <span className="flex items-center gap-1.5">
                        <span className="inline-block h-2.5 w-2.5 rounded-sm bg-amber-500" /> Achats
                    </span>
                )}
            </div>
        </div>
    );
}

/** Donut de répartition des documents de vente par type. */
function Donut({ data }: { data: NonNullable<DashboardData['repartition_ventes']> }) {
    const segments = [
        { label: 'Devis', value: data.devis, color: '#6366f1' },
        { label: 'Commandes', value: data.commandes, color: '#0ea5e9' },
        { label: 'Factures', value: data.factures, color: '#059669' },
    ];
    const total = segments.reduce((s, x) => s + x.value, 0);
    const r = 52;
    const c = 2 * Math.PI * r;
    let offset = 0;

    return (
        <div className="flex items-center gap-6">
            <svg viewBox="0 0 140 140" className="h-32 w-32 shrink-0">
                <g transform="translate(70,70) rotate(-90)">
                    <circle r={r} fill="none" stroke="#f1f5f9" strokeWidth={16} />
                    {total > 0 &&
                        segments.map((s) => {
                            const len = (s.value / total) * c;
                            const el = (
                                <circle
                                    key={s.label}
                                    r={r}
                                    fill="none"
                                    stroke={s.color}
                                    strokeWidth={16}
                                    strokeDasharray={`${len} ${c - len}`}
                                    strokeDashoffset={-offset}
                                />
                            );
                            offset += len;
                            return el;
                        })}
                </g>
                <text x="70" y="66" textAnchor="middle" className="fill-slate-900" fontSize={20} fontWeight={600}>
                    {total}
                </text>
                <text x="70" y="82" textAnchor="middle" className="fill-slate-400" fontSize={10}>
                    documents
                </text>
            </svg>
            <div className="space-y-2 text-sm">
                {segments.map((s) => (
                    <div key={s.label} className="flex items-center gap-2">
                        <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ background: s.color }} />
                        <span className="text-slate-600">{s.label}</span>
                        <span className="ml-auto font-medium text-slate-900">{s.value}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

/** Liste top (clients ou produits) avec barre de proportion. */
function TopList({ items }: { items: { name: string; total: number }[] }) {
    const max = Math.max(1, ...items.map((i) => i.total));
    if (items.length === 0) {
        return <div className="py-6 text-sm text-slate-400">Aucune donnée pour l'instant.</div>;
    }
    return (
        <ul className="space-y-3">
            {items.map((i, idx) => (
                <li key={idx}>
                    <div className="flex justify-between text-sm">
                        <span className="truncate text-slate-700">{i.name}</span>
                        <span className="ml-3 shrink-0 font-medium text-slate-900">{formatMAD(i.total)}</span>
                    </div>
                    <div className="mt-1 h-1.5 w-full rounded-full bg-slate-100">
                        <div className="h-full rounded-full bg-emerald-500" style={{ width: `${(i.total / max) * 100}%` }} />
                    </div>
                </li>
            ))}
        </ul>
    );
}

export default function Dashboard() {
    const { tenant } = useAuth();

    const { data, isLoading } = useQuery({
        queryKey: ['dashboard'],
        queryFn: async () => (await api.get<{ data: DashboardData }>('/dashboard')).data.data,
    });

    if (isLoading || !data) {
        return <div className="text-sm text-slate-400">Chargement du tableau de bord…</div>;
    }

    const k = data.kpis;
    const alertes = [
        data.alertes.factures_echues && data.alertes.factures_echues.count > 0
            ? {
                  to: '/relances',
                  label: 'Factures échues impayées',
                  value: `${data.alertes.factures_echues.count} · ${formatMAD(data.alertes.factures_echues.montant)}`,
              }
            : null,
        data.alertes.stock_sous_seuil && data.alertes.stock_sous_seuil.count > 0
            ? { to: '/stock', label: 'Produits sous le seuil de stock', value: `${data.alertes.stock_sous_seuil.count}` }
            : null,
        data.alertes.devis_attente && data.alertes.devis_attente.count > 0
            ? { to: '/ventes', label: 'Devis en attente de réponse', value: `${data.alertes.devis_attente.count}` }
            : null,
    ].filter(Boolean) as { to: string; label: string; value: string }[];

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Tableau de bord</h1>
                <p className="mt-1 text-sm text-slate-500">{tenant?.name} — vue d'ensemble</p>
            </div>

            {/* KPIs */}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {k.ca_mois && (
                    <KpiCard label="CA du mois" value={formatMAD(k.ca_mois.value)} kpi={k.ca_mois} tone="emerald" sub="vs mois préc." />
                )}
                {k.ca_annee && <KpiCard label="CA cumulé (année)" value={formatMAD(k.ca_annee.value)} />}
                {k.encaissements_mois && (
                    <KpiCard label="Encaissements du mois" value={formatMAD(k.encaissements_mois.value)} kpi={k.encaissements_mois} sub="vs mois préc." />
                )}
                {k.tresorerie && <KpiCard label="Trésorerie" value={formatMAD(k.tresorerie.value)} />}
                {k.resultat && (
                    <KpiCard
                        label="Résultat (année)"
                        value={formatMAD(k.resultat.value)}
                        tone={k.resultat.value >= 0 ? 'emerald' : 'red'}
                    />
                )}
                {k.creances && (
                    <KpiCard
                        label="Créances clients"
                        value={formatMAD(k.creances.total)}
                        tone={k.creances.echu > 0 ? 'amber' : 'slate'}
                        sub={k.creances.echu > 0 ? `dont ${formatMAD(k.creances.echu)} ancien` : undefined}
                    />
                )}
                {k.dettes && <KpiCard label="Dettes fournisseurs" value={formatMAD(k.dettes.total)} />}
            </div>

            {/* Alertes */}
            {alertes.length > 0 && (
                <div className="grid gap-4 sm:grid-cols-3">
                    {alertes.map((a) => (
                        <Link
                            key={a.to}
                            to={a.to}
                            className="flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 p-4 transition hover:bg-amber-100"
                        >
                            <div>
                                <div className="text-sm font-medium text-amber-900">{a.label}</div>
                                <div className="mt-0.5 text-lg font-semibold text-amber-700">{a.value}</div>
                            </div>
                            <span className="text-amber-500" aria-hidden>→</span>
                        </Link>
                    ))}
                </div>
            )}

            {/* Graphe CA + répartition */}
            {data.capabilities.ventes && (
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="rounded-xl bg-white p-5 shadow-sm lg:col-span-2">
                        <h2 className="font-medium text-slate-900">Chiffre d'affaires — 12 derniers mois</h2>
                        <div className="mt-4">
                            <BarChart12 data={data.ventes_12_mois} withAchats={data.capabilities.achats} />
                        </div>
                    </div>
                    {data.repartition_ventes && (
                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <h2 className="font-medium text-slate-900">Documents de vente (année)</h2>
                            <div className="mt-6">
                                <Donut data={data.repartition_ventes} />
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Top clients / produits */}
            {data.capabilities.ventes && (
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-xl bg-white p-5 shadow-sm">
                        <h2 className="font-medium text-slate-900">Top 5 clients (année)</h2>
                        <div className="mt-4">
                            <TopList items={data.top_clients} />
                        </div>
                    </div>
                    <div className="rounded-xl bg-white p-5 shadow-sm">
                        <h2 className="font-medium text-slate-900">Top 5 produits (année)</h2>
                        <div className="mt-4">
                            <TopList items={data.top_produits} />
                        </div>
                    </div>
                </div>
            )}

            {/* Fallback si aucun bloc (rôle très restreint) */}
            {!data.capabilities.ventes && !data.capabilities.compta && (
                <div className="rounded-xl bg-white p-5 shadow-sm text-sm text-slate-500">
                    Votre profil donne accès à la caisse et au catalogue. Rendez-vous dans le menu pour démarrer.
                </div>
            )}
        </div>
    );
}
