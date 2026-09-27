import { useQuery } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import type { TimelineItem } from '@/types';
import { useT } from '@/lib/langue';

const KIND = {
    activite: { icon: '💬', color: 'bg-sky-100 text-sky-700', label: 'Activité' },
    opportunite: { icon: '📈', color: 'bg-violet-100 text-violet-700', label: 'Opportunité' },
    document: { icon: '🧾', color: 'bg-emerald-100 text-emerald-700', label: 'Document' },
};

/** Timeline 360° d'un client : activités, opportunités et documents réunis. */
export default function TiersTimeline({ tiersId }: { tiersId: string }) {
    const t = useT();
    const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
        queryKey: ['tiers-timeline', tiersId],
        queryFn: async () => {
            const { data } = await api.get<{ data: TimelineItem[] }>(`/crm/tiers/${tiersId}/timeline`);
            return data.data;
        },
        // Un refus (403) ne changera pas au second essai : on l'affiche tout de
        // suite. Seule une panne réseau mérite qu'on réessaie une fois.
        retry: (echecs, err) => (isAxiosError(err) && err.response !== undefined ? false : echecs < 1),
    });

    // Refus du serveur : module CRM désactivé, ou rôle sans accès. La fiche
    // masque déjà l'onglet dans ces deux cas ; ce message couvre le décalage
    // entre les deux (module coupé pendant que la fiche était ouverte). Ce
    // décalage survient sur un RECHARGEMENT de l'onglet : d'où la liste masquée
    // en erreur plus bas, la requête gardant sa donnée d'avant le refus.
    const refuse = isError && isAxiosError(error) && error.response?.status === 403;

    return (
        <div className="rounded-xl bg-white p-5 shadow-sm">
            <h2 className="mb-4 font-medium text-slate-900">{t('Historique client (360°)')}</h2>

            {isLoading && <div className="py-4 text-sm text-slate-400">{t('Chargement…')}</div>}

            {/* Une erreur n'est PAS un historique vide : afficher « aucune
                activité » ferait croire à un client sans histoire. */}
            {isError && (
                <div role="alert" className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {refuse
                        ? t("L'historique n'est pas disponible : le module CRM est désactivé, ou votre rôle n'y donne pas accès.")
                        : t("Impossible de charger l'historique pour l'instant.")}
                    {!refuse && (
                        <button
                            type="button"
                            onClick={() => refetch()}
                            disabled={isFetching}
                            className="ms-2 font-medium text-amber-900 underline disabled:opacity-50"
                        >
                            {t('Réessayer')}
                        </button>
                    )}
                </div>
            )}

            {!isLoading && !isError && data?.length === 0 && (
                <p className="text-sm text-slate-400">
                    {t("Aucune activité, opportunité ni document pour ce client pour l'instant.")}
                </p>
            )}

            <div className="space-y-3">
                {/* Un rechargement raté garde l'ancienne `data` : l'afficher sous
                    le bandeau ferait lire un historique « à jour » juste sous
                    « l'historique n'est pas disponible ». En erreur, rien. */}
                {!isError && data?.map((item) => {
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
                                        {t(meta.label)}
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
