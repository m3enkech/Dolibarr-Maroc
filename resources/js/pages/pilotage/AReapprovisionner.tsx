import { useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatMAD } from '@/lib/format';
import { useT } from '@/lib/langue';
import type { CommandeReapproResultat, ReapproOrigine, StockAlerte } from '@/types';

/** Combien de lignes on montre : un écran de suivi veut les plus urgentes, pas la page 7. */
const PLAFOND = 25;

const ORIGINE: Record<ReapproOrigine, { libelle: string; classe: string }> = {
    ventes: { libelle: 'ventes', classe: 'bg-emerald-50 text-emerald-700' },
    ventes_court: { libelle: "peu d'historique", classe: 'bg-amber-50 text-amber-700' },
    seuil: { libelle: 'seuil', classe: 'bg-slate-100 text-slate-600' },
    sans_historique: { libelle: 'sans historique', classe: 'bg-slate-100 text-slate-500' },
};

export default function AReapprovisionner({ entrepotId }: { entrepotId: string }) {
    const t = useT();
    const { can } = useAuth();
    const queryClient = useQueryClient();
    const [selection, setSelection] = useState<number[]>([]);
    const [confirmation, setConfirmation] = useState(false);
    const [resultat, setResultat] = useState<CommandeReapproResultat | null>(null);

    // Sans droit d'écriture sur les achats, la sélection n'a pas lieu d'être :
    // elle ne mènerait qu'à un refus du serveur.
    const peutCommander = can('achats', 'write');

    /**
     * Engendrée UNE fois par sélection, et renouvelée seulement après un succès.
     * Un double clic renvoie donc la même clé : le serveur rejoue sa réponse au
     * lieu de créer une seconde commande.
     */
    const cle = useRef<string>(crypto.randomUUID());

    const { data, isLoading } = useQuery({
        queryKey: ['pilotage', 'reappro', { entrepotId }],
        queryFn: async () => {
            const { data } = await api.get<{ data: StockAlerte[] }>('/stock/alertes', {
                params: { entrepot_id: entrepotId || undefined },
            });
            return data.data;
        },
        staleTime: 60_000,
        refetchInterval: 120_000,
        refetchIntervalInBackground: false,
    });

    /**
     * Triées par urgence, pas par nom : ce qui tient le moins de jours passe
     * devant. Les articles sans couverture calculable ferment la marche — on ne
     * les fait pas passer pour urgents faute de chiffre.
     */
    const lignes = useMemo(() => {
        const toutes = [...(data ?? [])].sort((a, b) => {
            const ca = a.couverture_restante ?? Number.POSITIVE_INFINITY;
            const cb = b.couverture_restante ?? Number.POSITIVE_INFINITY;
            return ca - cb;
        });
        return { visibles: toutes.slice(0, PLAFOND), total: toutes.length };
    }, [data]);

    const commandable = (a: StockAlerte) => a.suggestion !== null && parseFloat(a.suggestion) > 0;
    const selectionnees = lignes.visibles.filter((a) => selection.includes(a.produit_id) && commandable(a));

    const mutation = useMutation({
        mutationFn: async () => {
            const { data } = await api.post<{ data: CommandeReapproResultat }>('/achats/commandes-reappro', {
                produit_ids: selectionnees.map((a) => a.produit_id),
                entrepot_id: entrepotId ? Number(entrepotId) : null,
                cle_idempotence: cle.current,
            });
            return data.data;
        },
        onSuccess: (data) => {
            setResultat(data);
            setConfirmation(false);
            setSelection([]);
            cle.current = crypto.randomUUID();
            queryClient.invalidateQueries({ queryKey: ['pilotage'] });
            queryClient.invalidateQueries({ queryKey: ['stock-niveaux'] });
            queryClient.invalidateQueries({ queryKey: ['stock-alertes'] });
        },
    });

    if (isLoading) {
        return <p className="text-sm text-slate-400">{t('Chargement des articles à commander…')}</p>;
    }

    if (lignes.total === 0) {
        return null;
    }

    return (
        <section className="min-w-0 space-y-3">
            <div className="flex flex-wrap items-baseline gap-2">
                <h2 className="font-medium text-slate-900">{t('À commander')}</h2>
                <span className="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">
                    {t('{n} article(s)', { n: lignes.total })}
                </span>
                {lignes.total > PLAFOND && (
                    <span className="text-xs text-slate-400">
                        {t('les {n} plus urgents sont affichés', { n: PLAFOND })}
                    </span>
                )}
            </div>

            {resultat && <Resultat resultat={resultat} onFermer={() => setResultat(null)} />}

            {mutation.isError && (
                <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                    {(mutation.error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
                        t('La commande n’a pas pu être créée.')}
                </p>
            )}

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table className="w-full text-start text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            {peutCommander && <th className="w-10 ps-4 py-3" />}
                            <th className="px-4 py-3 text-start">{t('Produit')}</th>
                            <th className="px-4 py-3 text-end">{t('Stock')}</th>
                            <th className="px-4 py-3 text-end">{t('Couverture')}</th>
                            <th className="px-4 py-3 text-end">{t('À commander')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {lignes.visibles.map((alerte) => {
                            const origine = ORIGINE[alerte.origine];
                            const couverture = alerte.couverture_restante;

                            return (
                                <tr key={alerte.produit_id} className="hover:bg-slate-50">
                                    {peutCommander && (
                                        <td className="ps-4 py-3">
                                            <input
                                                type="checkbox"
                                                checked={selection.includes(alerte.produit_id)}
                                                disabled={!commandable(alerte)}
                                                onChange={() =>
                                                    setSelection((s) =>
                                                        s.includes(alerte.produit_id)
                                                            ? s.filter((id) => id !== alerte.produit_id)
                                                            : [...s, alerte.produit_id],
                                                    )
                                                }
                                                aria-label={t('Sélectionner {produit}', { produit: alerte.name })}
                                            />
                                        </td>
                                    )}
                                    <td className="px-4 py-3">
                                        <span className="font-medium text-slate-900">{alerte.name}</span>
                                        <span className={`ms-2 rounded px-1.5 py-0.5 text-xs ${origine.classe}`}>
                                            {t(origine.libelle)}
                                        </span>
                                        {alerte.fournisseur_nom ? (
                                            <span className="ms-1 rounded bg-sky-50 px-1.5 py-0.5 text-xs text-sky-700">
                                                {alerte.fournisseur_nom}
                                            </span>
                                        ) : (
                                            <span
                                                className="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500"
                                                title={t("Aucun achat passé : le fournisseur ne peut pas être déduit.")}
                                            >
                                                {t('fournisseur inconnu')}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-end font-semibold tabular-nums text-amber-600">
                                        {parseFloat(alerte.quantite)}
                                    </td>
                                    <td
                                        className={`px-4 py-3 text-end tabular-nums ${
                                            couverture !== null && couverture < 7 ? 'font-semibold text-red-600' : 'text-slate-500'
                                        }`}
                                    >
                                        {couverture !== null ? t('{n} j', { n: couverture }) : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        {commandable(alerte) ? (
                                            <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-700">
                                                {parseFloat(alerte.suggestion as string)}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-slate-400">{t('couvert')}</span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {peutCommander && selectionnees.length > 0 && (
                <div className="sticky bottom-4 z-20 flex flex-wrap items-center gap-3 rounded-xl border border-emerald-200 bg-white p-4 shadow-lg">
                    <span className="text-sm text-slate-700">
                        {t('{n} article(s) sélectionné(s)', { n: selectionnees.length })}
                    </span>
                    <button
                        type="button"
                        onClick={() => setSelection([])}
                        className="text-sm text-slate-500 underline"
                    >
                        {t('Tout désélectionner')}
                    </button>
                    <button
                        type="button"
                        onClick={() => setConfirmation(true)}
                        className="ms-auto rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                    >
                        {t('Commander')}
                    </button>
                </div>
            )}

            {confirmation && (
                <Confirmation
                    lignes={selectionnees}
                    enCours={mutation.isPending}
                    onAnnuler={() => setConfirmation(false)}
                    onConfirmer={() => mutation.mutate()}
                />
            )}
        </section>
    );
}

/**
 * La relecture avant écriture.
 *
 * Le formulaire pré-rempli servait aussi à cela : on voyait ce qu'on allait
 * créer avant de l'enregistrer. En commandant d'un geste, il faut la remplacer,
 * sans quoi le clic devient un pari.
 */
function Confirmation({
    lignes,
    enCours,
    onAnnuler,
    onConfirmer,
}: {
    lignes: StockAlerte[];
    enCours: boolean;
    onAnnuler: () => void;
    onConfirmer: () => void;
}) {
    const t = useT();

    const groupes = new Map<string, StockAlerte[]>();
    for (const ligne of lignes) {
        const cle = ligne.fournisseur_nom ?? '';
        groupes.set(cle, [...(groupes.get(cle) ?? []), ligne]);
    }

    const orphelins = groupes.get('') ?? [];

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 p-4 sm:items-center">
            <div className="max-h-[85vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
                <h3 className="font-medium text-slate-900">{t('Confirmer la commande')}</h3>
                <p className="mt-1 text-sm text-slate-500">
                    {t('Une commande par fournisseur sera créée et validée. Les quantités sont recalculées à cet instant.')}
                </p>

                <div className="mt-4 space-y-3">
                    {[...groupes.entries()]
                        .filter(([nom]) => nom !== '')
                        .map(([nom, articles]) => (
                            <div key={nom} className="rounded-lg border border-slate-200 p-3">
                                <p className="text-sm font-medium text-slate-900">{nom}</p>
                                <ul className="mt-1 space-y-0.5">
                                    {articles.map((a) => (
                                        <li key={a.produit_id} className="flex justify-between gap-3 text-sm text-slate-600">
                                            <span className="min-w-0 truncate">{a.name}</span>
                                            <span className="tabular-nums">
                                                {parseFloat(a.suggestion as string)}
                                                {a.dernier_prix_achat && (
                                                    <span className="ms-2 text-xs text-slate-400">
                                                        {formatMAD(a.dernier_prix_achat)}
                                                    </span>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}

                    {orphelins.length > 0 && (
                        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            {t("{n} article(s) sans fournisseur connu ne seront PAS commandés : aucun achat passé ne permet de le déduire.", {
                                n: orphelins.length,
                            })}
                        </p>
                    )}
                </div>

                <div className="mt-5 flex justify-end gap-3">
                    <button type="button" onClick={onAnnuler} className="rounded-md border border-slate-300 px-4 py-2 text-sm">
                        {t('Annuler')}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirmer}
                        disabled={enCours}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {enCours ? t('Création…') : t('Créer les commandes')}
                    </button>
                </div>
            </div>
        </div>
    );
}

function Resultat({ resultat, onFermer }: { resultat: CommandeReapproResultat; onFermer: () => void }) {
    const t = useT();

    return (
        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm">
            <div className="flex items-start justify-between gap-3">
                <div>
                    {resultat.commandes.length > 0 ? (
                        <p className="text-emerald-800">
                            {t('{n} commande(s) créée(s) :', { n: resultat.commandes.length })}{' '}
                            {resultat.commandes.map((c) => c.code).join(', ')}
                        </p>
                    ) : (
                        <p className="text-slate-700">{t('Aucune commande à créer.')}</p>
                    )}

                    {/* Les écartés sont DITS, jamais escamotés. */}
                    {resultat.ignores.length > 0 && (
                        <p className="mt-1 text-slate-600">
                            {t('{n} article(s) écarté(s) :', { n: resultat.ignores.length })}{' '}
                            {resultat.ignores
                                .map((i) =>
                                    i.raison === 'fournisseur_inconnu'
                                        ? `${i.name} (${t('fournisseur inconnu')})`
                                        : `${i.name} (${t('déjà couvert')})`,
                                )
                                .join(', ')}
                        </p>
                    )}
                </div>
                <button type="button" onClick={onFermer} aria-label={t('Fermer')} className="text-slate-400">
                    ✕
                </button>
            </div>
        </div>
    );
}
