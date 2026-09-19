import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD, formatTva } from '@/lib/format';
import { statutClasses, statutLabel, TYPE_LABELS } from '@/pages/ventes/common';
import type { DocumentVente } from '@/types';
import { useT } from '@/lib/langue';

/**
 * Le détail d'une pièce, EN LECTURE, sans quitter la liste.
 *
 * Trois partis pris.
 *
 * 1. PAS DE MODE ÉDITION. Neuf fois sur dix on clique pour VÉRIFIER — le
 *    montant, la date, ce qu'il y avait dessus. Ouvrir un formulaire pour ça,
 *    c'est proposer de modifier une pièce comptable à quelqu'un qui voulait la
 *    lire, et c'est deux clics pour revenir. Le lien « Ouvrir la fiche » reste
 *    là pour qui veut vraiment agir.
 *
 * 2. UN PANNEAU, PAS UNE LIGNE DÉPLIANTE. Une dépliante pousse toutes les
 *    lignes suivantes vers le bas : sur un tableau qu'on balaie du regard, la
 *    ligne qu'on comparait change de place à chaque clic.
 *
 * 3. SUR TÉLÉPHONE, UNE FEUILLE DU BAS. Un panneau latéral y deviendrait un
 *    piège sous un long tableau. Même composant, conteneur différent — comme
 *    le détail par dépôt de l'écran de suivi.
 */
export default function ApercuVente({ id, onFermer }: { id: number; onFermer: () => void }) {
    const t = useT();

    const { data: doc, isLoading } = useQuery({
        queryKey: ['vente', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: DocumentVente }>(`/ventes/documents/${id}`);
            return data.data;
        },
    });

    return (
        <aside className="fixed inset-x-0 bottom-0 z-40 max-h-[75vh] overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl lg:sticky lg:inset-x-auto lg:bottom-auto lg:top-4 lg:z-auto lg:max-h-none lg:self-start lg:rounded-xl lg:shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-xs uppercase tracking-wide text-slate-500">
                        {doc ? t(TYPE_LABELS[doc.type]) : t('Chargement…')}
                    </div>
                    <div className="truncate font-mono text-sm font-semibold text-slate-900">{doc?.code ?? '…'}</div>
                </div>
                <button
                    onClick={onFermer}
                    className="shrink-0 rounded-md px-2 py-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                    aria-label={t('Fermer')}
                >
                    ✕
                </button>
            </div>

            {isLoading && <div className="py-8 text-center text-sm text-slate-400">{t('Chargement…')}</div>}

            {doc && (
                <>
                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <span className={`rounded px-1.5 py-0.5 text-xs ${statutClasses(doc.statut)}`}>
                            {statutLabel(doc)}
                        </span>
                        <span className="text-sm text-slate-600">{doc.date_document}</span>
                    </div>

                    <div className="mt-4 space-y-1 border-t border-slate-100 pt-4 text-sm">
                        <Ligne libelle={t('Client')} valeur={doc.tiers?.name} />
                        {doc.tiers?.ice && <Ligne libelle="ICE" valeur={doc.tiers.ice} />}
                        {doc.reference_client && <Ligne libelle={t('Bon de commande')} valeur={doc.reference_client} />}
                        {doc.date_echeance && <Ligne libelle={t('Échéance')} valeur={doc.date_echeance} />}
                    </div>

                    <div className="mt-4 border-t border-slate-100 pt-4">
                        <div className="mb-2 text-xs uppercase tracking-wide text-slate-500">
                            {t('Lignes')} ({doc.lignes?.length ?? 0})
                        </div>
                        <div className="space-y-2">
                            {doc.lignes?.map((l) => (
                                <div key={l.id} className="flex items-baseline justify-between gap-3 text-sm">
                                    <div className="min-w-0">
                                        <div className="truncate text-slate-900">{l.designation}</div>
                                        <div className="text-xs text-slate-500">
                                            {Number(l.quantite).toLocaleString('fr-MA')} × {formatMAD(l.prix_unitaire)}
                                            {Number(l.remise_percent) > 0 && ` − ${formatTva(l.remise_percent)}`}
                                            {' · '}
                                            {t('TVA')} {formatTva(l.tva_rate)}
                                        </div>
                                    </div>
                                    <div className="shrink-0 tabular-nums text-slate-900">{formatMAD(l.montant_ht)}</div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="mt-4 space-y-1 border-t border-slate-100 pt-4 text-sm">
                        <Ligne libelle={t('Total HT')} valeur={formatMAD(doc.total_ht)} />
                        <Ligne libelle={t('TVA')} valeur={formatMAD(doc.total_tva)} />
                        <div className="flex items-baseline justify-between gap-3 border-t border-slate-200 pt-1 font-semibold text-slate-900">
                            <span>{t('Total TTC')}</span>
                            <span className="tabular-nums">{formatMAD(doc.total_ttc)}</span>
                        </div>
                        {doc.reste_a_payer !== undefined && doc.statut !== 'brouillon' && (
                            <div className="flex items-baseline justify-between gap-3 pt-1">
                                <span className="text-slate-500">{t('Reste à payer')}</span>
                                <span
                                    className={`tabular-nums font-medium ${
                                        Number(doc.reste_a_payer) > 0 ? 'text-amber-700' : 'text-emerald-700'
                                    }`}
                                >
                                    {formatMAD(doc.reste_a_payer)}
                                </span>
                            </div>
                        )}
                    </div>

                    {doc.notes && (
                        <p className="mt-4 whitespace-pre-line border-t border-slate-100 pt-4 text-xs text-slate-500">
                            {doc.notes}
                        </p>
                    )}

                    <div className="mt-5 flex flex-wrap gap-2">
                        <Link
                            to={`/ventes/${doc.id}`}
                            className="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-emerald-700"
                        >
                            {t('Ouvrir la fiche')}
                        </Link>
                        {doc.tiers && (
                            <Link
                                to={`/tiers/${doc.tiers.id}`}
                                className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                            >
                                {t('Voir le client')}
                            </Link>
                        )}
                    </div>
                </>
            )}
        </aside>
    );
}

function Ligne({ libelle, valeur }: { libelle: string; valeur?: string | null }) {
    if (!valeur) return null;

    return (
        <div className="flex items-baseline justify-between gap-3">
            <span className="shrink-0 text-slate-500">{libelle}</span>
            <span className="min-w-0 truncate text-end text-slate-900">{valeur}</span>
        </div>
    );
}
