import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { useT } from '@/lib/langue';
import type { PilotageFlux } from '@/types';

/** Tons littéraux : Tailwind ne compile pas `text-${ton}-700`. */
const TON = {
    neutre: 'text-slate-900',
    attention: 'text-amber-600',
    alerte: 'text-red-600',
    bien: 'text-emerald-600',
} as const;

type Ton = keyof typeof TON;

function Tuile({
    libelle,
    valeur,
    detail,
    ton = 'neutre',
    vers,
    aide,
}: {
    libelle: string;
    valeur: string | number;
    detail?: string;
    ton?: Ton;
    vers?: string;
    aide?: string;
}) {
    const t = useT();

    const contenu = (
        <>
            <p className="text-xs uppercase tracking-wide text-slate-500">{t(libelle)}</p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums ${TON[ton]}`}>{valeur}</p>
            {detail && <p className="mt-0.5 text-xs text-slate-500">{detail}</p>}
        </>
    );

    const classe =
        'min-w-[9.5rem] shrink-0 snap-start rounded-xl bg-white p-4 shadow-sm sm:min-w-0 sm:shrink';

    return vers ? (
        <Link to={vers} title={aide ? t(aide) : undefined} className={`${classe} transition hover:shadow-md`}>
            {contenu}
        </Link>
    ) : (
        <div title={aide ? t(aide) : undefined} className={classe}>
            {contenu}
        </div>
    );
}

/**
 * Les compteurs de flux.
 *
 * Sur téléphone, une rangée qui défile plutôt qu'une grille : huit tuiles
 * empilées pousseraient le tableau produits sous la ligne de flottaison, alors
 * que c'est lui qu'on vient voir.
 *
 * Les blocs absents de la réponse ne sont pas rendus — le serveur OMET ce que
 * l'utilisateur n'a pas le droit de voir, il ne renvoie pas des zéros.
 */
export default function FluxBandeau() {
    const t = useT();

    const { data, isLoading } = useQuery({
        queryKey: ['pilotage', 'flux'],
        queryFn: async () => {
            const { data } = await api.get<{ data: PilotageFlux }>('/pilotage/flux');
            return data.data;
        },
        staleTime: 60_000,
        refetchInterval: 60_000,
        refetchIntervalInBackground: false,
        refetchOnWindowFocus: true,
        // Le défaut global réessaie une fois : sur un refus de droits, cela
        // ferait deux 403 pour rien.
        retry: false,
    });

    if (isLoading) {
        return <p className="text-sm text-slate-400">{t('Chargement des compteurs…')}</p>;
    }

    if (!data) {
        return null;
    }

    return (
        <section className="space-y-3">
            <div className="flex snap-x gap-3 overflow-x-auto pb-1 sm:grid sm:grid-cols-2 sm:overflow-visible lg:grid-cols-4">
                {data.stock && (
                    <>
                        <Tuile
                            libelle="En rupture"
                            valeur={data.stock.en_rupture}
                            ton={data.stock.en_rupture > 0 ? 'alerte' : 'bien'}
                            detail={t('sur {n} références', { n: data.stock.references_actives })}
                        />
                        <Tuile
                            libelle="Sous le seuil"
                            valeur={data.stock.sous_seuil}
                            ton={data.stock.sous_seuil > 0 ? 'attention' : 'bien'}
                            detail={t('valeur du stock : {v}', { v: formatMAD(data.stock.valeur_achat) })}
                        />
                    </>
                )}

                {data.ventes && (
                    <>
                        <Tuile
                            libelle="Commandes clients"
                            valeur={data.ventes.commandes_ouvertes.count}
                            detail={formatMAD(data.ventes.commandes_ouvertes.montant_ttc ?? null)}
                            vers="/ventes?type=commande"
                            aide="Commandes validées : le carnet ferme."
                        />
                        <Tuile
                            libelle="Avec reliquat"
                            valeur={data.ventes.commandes_reliquat.count}
                            ton={data.ventes.commandes_reliquat.count > 0 ? 'attention' : 'neutre'}
                            aide="Commandes dont une ligne reste à livrer. Une commande facturée sans bon de livraison y figure aussi : la livraison n'est tracée que par les bons de livraison issus de la commande."
                        />
                        <Tuile
                            libelle="Devis en attente"
                            valeur={data.ventes.devis_en_attente.count}
                            detail={formatMAD(data.ventes.devis_en_attente.montant_ttc ?? null)}
                            vers="/ventes?type=devis"
                        />
                        <Tuile
                            libelle="Impayé"
                            valeur={formatMAD(data.ventes.factures_impayees.reste ?? null)}
                            ton={data.ventes.factures_echues.count > 0 ? 'alerte' : 'neutre'}
                            detail={t('{n} facture(s), dont {e} échue(s)', {
                                n: data.ventes.factures_impayees.count,
                                e: data.ventes.factures_echues.count,
                            })}
                            vers="/ventes?type=facture"
                        />
                    </>
                )}

                {data.achats && (
                    <>
                        <Tuile
                            libelle="Commandes fournisseur"
                            valeur={data.achats.commandes_ouvertes.count}
                            detail={t('{n} reçue(s) en partie', { n: data.achats.reception_partielle.count })}
                            vers="/achats?type=commande"
                        />
                        <Tuile
                            libelle="Reste à recevoir"
                            valeur={formatMAD(data.achats.reste_a_recevoir.montant_ht ?? null)}
                            aide="Valeur de la marchandise commandée qui n'est pas encore arrivée."
                        />
                    </>
                )}
            </div>

            {/*
             * Les chiffres qu'on ne sait pas calculer honnêtement, dits plutôt
             * que remplacés par une approximation.
             */}
            {data.indisponible.length > 0 && (
                <p className="text-xs text-slate-400">
                    {t('Non affiché faute de donnée fiable :')}{' '}
                    {data.indisponible.map((entree, index) => (
                        <span key={entree.cle}>
                            {index > 0 && ' · '}
                            <span className="underline decoration-dotted" title={t(entree.raison)}>
                                {t(entree.libelle)}
                            </span>
                        </span>
                    ))}
                </p>
            )}
        </section>
    );
}
