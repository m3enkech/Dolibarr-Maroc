import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { useT } from '@/lib/langue';
import type { ProduitDepots } from '@/types';

/** Tons des types de mouvement — littéraux, jamais construits : Tailwind ne compile pas `text-${x}-700`. */
const TON_MOUVEMENT: Record<string, string> = {
    entree: 'bg-emerald-50 text-emerald-700',
    achat: 'bg-emerald-50 text-emerald-700',
    retour: 'bg-emerald-50 text-emerald-700',
    sortie: 'bg-red-50 text-red-700',
    vente: 'bg-red-50 text-red-700',
    ajustement: 'bg-amber-50 text-amber-700',
    transfert: 'bg-sky-50 text-sky-700',
};

function Ligne({ libelle, valeur, unite }: { libelle: string; valeur: string; unite?: string | null }) {
    const t = useT();

    return (
        <div className="flex items-baseline justify-between gap-3 py-1.5">
            <span className="text-sm text-slate-500">{t(libelle)}</span>
            <span className="tabular-nums text-sm font-medium text-slate-900">
                {parseFloat(valeur)}
                {unite && <span className="ms-1 text-xs font-normal text-slate-400">{unite}</span>}
            </span>
        </div>
    );
}

/**
 * Ce qu'un article détient et attend, dépôt par dépôt.
 *
 * UN SEUL composant pour deux formes. Au bureau, panneau collant à droite : le
 * tableau ne bouge jamais, donc comparer deux articles ne réorganise pas
 * l'écran. Sous 1024 px la grille s'effondre et un panneau collant se
 * retrouverait sous un long tableau, hors d'atteinte : il devient alors une
 * feuille remontée du bas, avec son voile — le patron du menu mobile.
 *
 * Les classes de position fixe sont celles par défaut et sont annulées au-delà
 * de `lg`, exactement comme la barre latérale : un seul chemin de code, aucun
 * balisage dupliqué.
 */
export default function DetailDepots({ produitId, onFermer }: { produitId: number; onFermer: () => void }) {
    const t = useT();

    const { data, isLoading, isError } = useQuery({
        queryKey: ['pilotage', 'depots', produitId],
        queryFn: async () => {
            const { data } = await api.get<{ data: ProduitDepots }>(`/stock/produits/${produitId}/depots`);
            return data.data;
        },
        staleTime: 30_000,
    });

    const sansEntrepot = parseFloat(data?.sans_entrepot.en_commande ?? '0');

    return (
        <>
            {/* Voile : ferme au geste, et empêche de cliquer au travers. */}
            <div
                onClick={onFermer}
                className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
                aria-hidden
            />

            <aside className="fixed inset-x-0 bottom-0 z-40 max-h-[70vh] overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl lg:sticky lg:inset-x-auto lg:bottom-auto lg:top-4 lg:z-auto lg:max-h-none lg:self-start lg:rounded-xl lg:shadow-sm">
                <div className="mb-3 flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="truncate font-medium text-slate-900">
                            {data?.produit.name ?? t('Détail du produit')}
                        </h2>
                        {data && (
                            <p className="font-mono text-xs text-slate-500">{data.produit.code}</p>
                        )}
                    </div>
                    <button
                        type="button"
                        onClick={onFermer}
                        aria-label={t('Fermer le détail')}
                        className="-me-2 -mt-1 rounded-md px-2 py-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                    >
                        ✕
                    </button>
                </div>

                {isLoading && <p className="py-6 text-center text-sm text-slate-400">{t('Chargement…')}</p>}

                {isError && (
                    <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {t('Impossible de charger le détail de cet article.')}
                    </p>
                )}

                {data && (
                    <>
                        <div className="rounded-lg bg-slate-50 p-3">
                            <Ligne libelle="Stock total" valeur={data.total.quantite} unite={data.produit.unit} />
                            <Ligne libelle="Attendu" valeur={data.total.en_commande} unite={data.produit.unit} />
                            {data.produit.stock_min !== null && (
                                <Ligne libelle="Seuil d'alerte" valeur={data.produit.stock_min} unite={data.produit.unit} />
                            )}
                            {data.produit.buy_price !== null && (
                                <div className="flex items-baseline justify-between gap-3 border-t border-slate-200 py-1.5 pt-2">
                                    <span className="text-sm text-slate-500">{t("Dernier prix d'achat")}</span>
                                    <span className="tabular-nums text-sm font-medium text-slate-900">
                                        {formatMAD(data.produit.buy_price)}
                                    </span>
                                </div>
                            )}
                        </div>

                        <h3 className="mb-2 mt-5 text-xs font-medium uppercase tracking-wide text-slate-500">
                            {t('Par dépôt')}
                        </h3>
                        <table className="w-full text-start text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th className="pb-1 text-start font-medium">{t('Dépôt')}</th>
                                    <th className="pb-1 text-end font-medium">{t('Stock')}</th>
                                    <th className="pb-1 text-end font-medium">{t('Attendu')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.depots.map((depot) => {
                                    const stock = parseFloat(depot.quantite);
                                    return (
                                        <tr key={depot.entrepot_id}>
                                            <td className="py-2 pe-2">
                                                <span className="text-slate-900">{depot.name}</span>
                                                {depot.is_default && (
                                                    <span className="ms-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                                                        {t('défaut')}
                                                    </span>
                                                )}
                                            </td>
                                            <td
                                                className={`py-2 text-end tabular-nums ${
                                                    stock <= 0 ? 'font-medium text-red-600' : 'text-slate-900'
                                                }`}
                                            >
                                                {stock}
                                            </td>
                                            <td className="py-2 text-end tabular-nums text-slate-500">
                                                {parseFloat(depot.en_commande) || '—'}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>

                        {/*
                         * Une commande sans dépôt désigné n'est attribuée à
                         * aucun : la dire ici évite qu'on cherche en vain
                         * pourquoi les colonnes ne somment pas au total.
                         */}
                        {sansEntrepot > 0 && (
                            <p className="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                {t('{n} attendus sur une commande sans dépôt désigné, donc rattachés à aucun.', {
                                    n: sansEntrepot,
                                })}
                            </p>
                        )}

                        <h3 className="mb-2 mt-5 text-xs font-medium uppercase tracking-wide text-slate-500">
                            {t('Derniers mouvements')}
                        </h3>
                        {data.derniers_mouvements.length === 0 ? (
                            <p className="py-3 text-sm text-slate-400">{t('Aucun mouvement enregistré.')}</p>
                        ) : (
                            <ul className="divide-y divide-slate-100">
                                {data.derniers_mouvements.map((mouvement) => (
                                    <li key={mouvement.id} className="flex items-center gap-2 py-2 text-sm">
                                        <span
                                            className={`rounded px-1.5 py-0.5 text-xs ${
                                                TON_MOUVEMENT[mouvement.type] ?? 'bg-slate-100 text-slate-600'
                                            }`}
                                        >
                                            {t(mouvement.type)}
                                        </span>
                                        <span className="tabular-nums font-medium text-slate-900">
                                            {parseFloat(mouvement.quantite) > 0 ? '+' : ''}
                                            {parseFloat(mouvement.quantite)}
                                        </span>
                                        <span className="min-w-0 truncate text-xs text-slate-500">
                                            {mouvement.entrepot}
                                            {mouvement.reference && ` · ${mouvement.reference}`}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </aside>
        </>
    );
}
