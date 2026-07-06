import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import type { TimelineItem } from '@/types';

const KIND = {
    activite: { icon: '💬', color: 'bg-sky-100 text-sky-700', label: 'Activité' },
    opportunite: { icon: '📈', color: 'bg-violet-100 text-violet-700', label: 'Opportunité' },
    document: { icon: '🧾', color: 'bg-emerald-100 text-emerald-700', label: 'Document' },
};

/** Timeline 360° d'un client : activités, opportunités et documents réunis. */
export default function TiersTimeline({ tiersId }: { tiersId: string }) {
    const { data, isLoading } = useQuery({
        queryKey: ['tiers-timeline', tiersId],
        queryFn: async () => {
            const { data } = await api.get<{ data: TimelineItem[] }>(`/crm/tiers/${tiersId}/timeline`);
            return data.data;
        },
    });

    return (
        <div className="rounded-xl bg-white p-5 shadow-sm">
            <h2 className="mb-4 font-medium text-slate-900">Historique client (360°)</h2>

            {isLoading && <div className="py-4 text-sm text-slate-400">Chargement…</div>}
            {!isLoading && data?.length === 0 && (
                <p className="text-sm text-slate-400">
                    Aucune activité, opportunité ni document pour ce client pour l'instant.
                </p>
            )}

            <div className="space-y-3">
                {data?.map((item) => {
                    const meta = KIND[item.kind];
                    const inner = (
                        <>
                            <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm">
                                {meta.icon}
                            </span>
                            <div className="flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium text-slate-900">{item.titre}</span>
                                    <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${meta.color}`}>
                                        {meta.label}
                                    </span>
                                </div>
                                {item.detail && <div className="text-xs text-slate-500">{item.detail}</div>}
                            </div>
                            <span className="shrink-0 text-xs text-slate-400">{item.date}</span>
                        </>
                    );

                    // Les documents sont cliquables vers leur détail.
                    return item.kind === 'document' ? (
                        <Link
                            key={`${item.kind}-${item.id}`}
                            to={`/ventes/${item.id}`}
                            className="flex items-start gap-3 rounded-lg border border-transparent p-1 transition hover:border-slate-200 hover:bg-slate-50"
                        >
                            {inner}
                        </Link>
                    ) : (
                        <div key={`${item.kind}-${item.id}`} className="flex items-start gap-3 p-1">
                            {inner}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
