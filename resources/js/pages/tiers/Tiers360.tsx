import { useEffect, useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useFeatures } from '@/lib/features';
import { formatMAD } from '@/lib/format';
import { useT } from '@/lib/langue';
import Pagination from '@/components/Pagination';
import ApercuVente from '@/pages/ventes/ApercuVente';
import { statutClasses, statutLabel } from '@/pages/ventes/common';
import ContactsTiers from '@/pages/tiers/ContactsTiers';
import TiersTimeline from '@/pages/tiers/TiersTimeline';
import type { DocumentAchat, DocumentVente, Paginated, Tiers } from '@/types';

type Synthese = {
    ventes: { devis: number; commandes: number; bons_livraison: number; factures: number; avoirs: number };
    achats: number;
    contacts: number;
    ca_ttc: string;
    ca_12_mois: string;
    impaye: string;
    achats_ttc: string;
    premier_document: string | null;
    dernier_document: string | null;
};

type ProduitEchange = {
    produit_id: number;
    code: string | null;
    name: string | null;
    unit: string | null;
    quantite: string;
    montant_ht: string;
    occurrences: number;
};

type Onglet = 'devis' | 'commande' | 'bon_livraison' | 'facture' | 'avoir' | 'achats' | 'produits' | 'contacts' | 'historique';

/** Un refus (403, 404) ne changera pas au second essai : seule une panne réseau mérite qu'on réessaie. */
const reessayerSiPanne = (echecs: number, err: unknown) =>
    isAxiosError(err) && err.response !== undefined ? false : echecs < 1;

/**
 * La fiche d'un tiers, en CONSULTATION — colonne de droite de l'espace tiers
 * au bureau, page entière sur téléphone.
 *
 * Elle répond aux questions qu'on se pose devant un client avant de l'appeler :
 * combien il pèse, ce qu'il doit, ce qu'il prend d'habitude, à qui parler. Il
 * fallait jusqu'ici ouvrir quatre écrans et recouper de tête.
 *
 * QUATRE PARTIS PRIS.
 *
 * 1. CONSULTATION PAR DÉFAUT, ÉDITION SUR DEMANDE. `/tiers/:id` ouvrait le
 *    FORMULAIRE : on venait regarder l'historique d'un client, on se retrouvait
 *    à pouvoir modifier son ICE. L'édition vit maintenant sur
 *    `/tiers/:id/modifier`, derrière un bouton.
 *
 * 2. LES ONGLETS CHARGENT CE QU'ON OUVRE, ET RIEN D'AUTRE. Les compteurs
 *    viennent d'une synthèse d'agrégats ; les pièces, de l'endpoint de liste
 *    déjà paginé et filtré. Charger les quatre cents factures d'un gros client
 *    pour n'en afficher que le nombre, c'est payer la page pour un chiffre.
 *
 * 3. MÊME APERÇU QUE LA LISTE DES VENTES. Cliquer une facture ici ou là-bas
 *    donne exactement la même chose — un composant, pas deux. Ici en
 *    surcouche : la fiche est déjà la colonne de droite.
 *
 * 4. CHANGER DE TIERS REMET LA FICHE À ZÉRO, SANS LA FAIRE CLIGNOTER. La
 *    fiche est remontée à chaque tiers (onglet, page et aperçu du précédent
 *    n'ont rien à faire sur le suivant), mais les données vivent AU-DESSUS :
 *    le tiers précédent reste affiché, estompé, le temps que l'autre arrive —
 *    au lieu d'un « Chargement… » qui ferait sauter toute la colonne.
 *
 *    Ce tiers précédent reste ENTIER et INERTE : la fiche est clé sur le tiers
 *    AFFICHÉ, pas sur l'URL, et tout ce qu'elle interroge ou vise part de lui.
 *    Clé sur l'URL, elle mêlait les deux — nom et boutons de l'ancien, pièces
 *    du nouveau — et « Supprimer » frappait l'ancien pendant que la liste
 *    surlignait déjà le nouveau.
 */
export default function Tiers360() {
    const t = useT();
    const { id } = useParams<{ id: string }>();
    const location = useLocation();

    const fiche = useQuery({
        queryKey: ['tiers', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Tiers }>(`/tiers/${id}`);
            return data.data;
        },
        placeholderData: keepPreviousData,
        retry: reessayerSiPanne,
    });

    const synthese = useQuery({
        queryKey: ['tiers-synthese', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Synthese }>(`/tiers/${id}/synthese`);
            return data.data;
        },
        placeholderData: keepPreviousData,
        retry: reessayerSiPanne,
    });

    const retour = { pathname: '/tiers', search: location.search };

    // L'erreur AVANT la donnée : une fiche qui répond 404 n'est pas « en cours
    // de chargement », et l'afficher ainsi faisait attendre indéfiniment. Mais
    // seulement quand il n'y a RIEN à montrer : un rafraîchissement en arrière-
    // plan qui échoue (502 pendant un redémarrage) garde ses données, et les
    // jeter emportait onglet, page et aperçu ouverts. Le passage vers un tiers
    // en 404 tombe bien ici : en erreur, la requête n'a plus de remplaçant.
    if (fiche.isError && fiche.data === undefined) {
        return <FicheIndisponible erreur={fiche.error} onReessayer={() => fiche.refetch()} retour={retour} />;
    }

    if (!fiche.data || id === undefined) {
        return (
            <div role="status" className="py-12 text-center text-sm text-slate-400">
                {t('Chargement…')}
            </div>
        );
    }

    const enTransition = fiche.isPlaceholderData;

    return (
        <>
            {fiche.isRefetchError && (
                <div role="alert" className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {t("La fiche n'a pas pu être actualisée.")}
                    <button
                        type="button"
                        onClick={() => fiche.refetch()}
                        disabled={fiche.isFetching}
                        className="ms-2 font-medium text-amber-900 underline disabled:opacity-50"
                    >
                        {t('Réessayer')}
                    </button>
                </div>
            )}
            <FicheTiers
                key={fiche.data.id}
                tiers={fiche.data}
                // Les chiffres d'un AUTRE tiers ne s'affichent jamais, même
                // estompés : en transition, la synthèse peut déjà être celle du
                // tiers demandé quand la fiche affichée est encore l'ancienne.
                synthese={enTransition || synthese.isPlaceholderData ? undefined : synthese.data}
                syntheseIndisponible={!enTransition && synthese.isError && synthese.data === undefined}
                onRechargerSynthese={() => synthese.refetch()}
                enTransition={enTransition}
                retour={retour}
            />
        </>
    );
}

function FicheTiers({
    tiers,
    synthese,
    syntheseIndisponible,
    onRechargerSynthese,
    enTransition,
    retour,
}: {
    tiers: Tiers;
    synthese?: Synthese;
    syntheseIndisponible: boolean;
    onRechargerSynthese: () => void;
    enTransition: boolean;
    retour: { pathname: string; search: string };
}) {
    const t = useT();
    const { can } = useAuth();
    const { features } = useFeatures();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    // Tout part du tiers AFFICHÉ, jamais de l'URL : les deux divergent le temps
    // d'une transition, et c'est sur celui qu'on voit qu'on agit.
    const id = String(tiers.id);

    // Ce que l'URL désigne À L'INSTANT, lu après coup par la suppression : on
    // a pu ouvrir un autre tiers pendant qu'elle partait.
    const { id: idRoute } = useParams<{ id: string }>();
    const routeCourante = useRef({ id: idRoute, retour });
    routeCourante.current = { id: idRoute, retour };

    // L'historique est servi par le module CRM : sa route répond 403 quand le
    // module est désactivé (défaut) ou que le rôle n'y a pas accès (comptable,
    // caissier). Proposer un onglet qui ne peut que refuser, c'est montrer une
    // liste vide qui fait croire à un client sans histoire. Même double test
    // que le menu : le module, puis le droit. Les pièces suivent la même
    // règle : un caissier (ventes fermées) voyait « Factures 12 » au-dessus de
    // « Aucune pièce », le compteur venant de la synthèse, la liste d'un refus.
    const historiqueVisible = features.crm && can('crm');
    const ventesVisibles = can('ventes');
    const achatsVisibles = tiers.is_supplier && can('achats');
    const [onglet, setOnglet] = useState<Onglet>(ventesVisibles ? 'facture' : achatsVisibles ? 'achats' : 'contacts');
    const [page, setPage] = useState(1);
    const [apercu, setApercu] = useState<number | null>(null);

    const estDocument = ventesVisibles && ['devis', 'commande', 'bon_livraison', 'facture', 'avoir'].includes(onglet);

    const documents = useQuery({
        queryKey: ['tiers-documents', id, onglet, page],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentVente>>('/ventes/documents', {
                params: { tiers_id: id, type: onglet, page, per_page: 15 },
            });
            return data;
        },
        enabled: estDocument,
        placeholderData: keepPreviousData,
        retry: reessayerSiPanne,
    });

    const achats = useQuery({
        queryKey: ['tiers-achats', id, page],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentAchat>>('/achats/documents', {
                params: { tiers_id: id, page, per_page: 15 },
            });
            return data;
        },
        enabled: achatsVisibles && onglet === 'achats',
        placeholderData: keepPreviousData,
        retry: reessayerSiPanne,
    });

    // Le sens dépend de `is_client` : c'est donc lui qui entre dans la clé, sans
    // quoi un tiers devenu client resservirait ses achats depuis le cache.
    const sens = tiers.is_client ? 'ventes' : 'achats';
    const produits = useQuery({
        queryKey: ['tiers-produits', id, sens],
        queryFn: async () => {
            const { data } = await api.get<{ data: ProduitEchange[] }>(`/tiers/${id}/produits`, {
                params: { sens },
            });
            return data.data;
        },
        enabled: onglet === 'produits',
        retry: reessayerSiPanne,
    });

    // Sous 1280 px, la liste qu'on vient de quitter est masquée : le focus qui
    // y était retombe sur <body>, et Tab repartait du menu. On le pose sur le
    // titre de la fiche, ce que lit aussi un lecteur d'écran. Au bureau, la
    // liste reste là, on n'y touche pas : on y parcourt les tiers au clavier.
    const titre = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        if (!window.matchMedia('(min-width: 1280px)').matches) {
            titre.current?.focus({ preventScroll: true });
        }
    }, []);

    // Héritée de l'ancienne liste en tableau, seul endroit où l'on pouvait
    // supprimer un tiers : la liste compacte n'a plus de colonne d'actions.
    const [erreurSuppression, setErreurSuppression] = useState<string | null>(null);
    const suppression = useMutation({
        mutationFn: () => api.delete(`/tiers/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: ['tiers'],
                // Sauf sa propre fiche : la relire ne rapporterait qu'un 404.
                predicate: (requete) => requete.queryKey[1] !== id,
            });
            queryClient.invalidateQueries({ queryKey: ['tiers-count'] });
        },
        onError: (err) => {
            setErreurSuppression(
                (isAxiosError(err) && (err.response?.data as { message?: string } | undefined)?.message) ||
                    t('La suppression a échoué.'),
            );
        },
    });

    const supprimer = () => {
        setErreurSuppression(null);
        if (window.confirm(t('Supprimer « {nom} » ({code}) ?', { nom: tiers.name, code: tiers.code }))) {
            // Le retour à la liste vit ICI et non dans useMutation : ce rappel-ci
            // n'est pas appelé si la fiche a été démontée entre-temps. Et même
            // montée, on ne ferme que si l'URL désigne ENCORE ce tiers — sinon
            // on fermait la fiche ouverte depuis. `replace` : la fiche d'un tiers
            // supprimé ne doit pas revenir par « Précédent », en « introuvable ».
            suppression.mutate(undefined, {
                onSuccess: () => {
                    if (routeCourante.current.id === id) {
                        navigate(routeCourante.current.retour, { replace: true });
                    }
                },
            });
        }
    };

    const changerOnglet = (o: Onglet) => {
        setOnglet(o);
        setPage(1);
        setApercu(null);
    };

    // Libellés traduits ICI, en littéraux : passés plus bas en t(o.label), ils
    // échappaient au relevé des traductions manquantes — « Articles » restait
    // ainsi en français dans la fiche en arabe.
    const ONGLETS: { cle: Onglet; label: string; compte?: number }[] = [
        ...(ventesVisibles
            ? [
                  { cle: 'facture' as Onglet, label: t('Factures'), compte: synthese?.ventes.factures },
                  { cle: 'commande' as Onglet, label: t('Commandes'), compte: synthese?.ventes.commandes },
                  { cle: 'devis' as Onglet, label: t('Devis'), compte: synthese?.ventes.devis },
                  { cle: 'bon_livraison' as Onglet, label: t('Bons de livraison'), compte: synthese?.ventes.bons_livraison },
                  { cle: 'avoir' as Onglet, label: t('Avoirs'), compte: synthese?.ventes.avoirs },
              ]
            : []),
        ...(achatsVisibles ? [{ cle: 'achats' as Onglet, label: t('Achats'), compte: synthese?.achats }] : []),
        { cle: 'produits', label: t('Articles') },
        { cle: 'contacts', label: t('Contacts'), compte: synthese?.contacts },
        ...(historiqueVisible ? [{ cle: 'historique' as Onglet, label: t('Historique') }] : []),
    ];

    // Les cases chiffrées : « … » tant que la synthèse est attendue, « — »
    // quand elle a échoué — « … » à vie ne disait pas qu'il fallait réessayer.
    const kpi = (valeur: (s: Synthese) => string) => (synthese ? valeur(synthese) : syntheseIndisponible ? '—' : '…');

    return (
        // `@container` : la fiche se dispose selon SA largeur, pas celle de
        // l'écran — à 1280 px elle n'a que 640 px à côté de la liste, quand un
        // téléphone de 390 px l'a pour lui seul en pleine page.
        //
        // `inert` en transition : la fiche estompée est celle du tiers QUITTÉ.
        // Ni clic ni focus ne doivent l'atteindre — « + Facture » ou
        // « Supprimer » y viseraient l'ancien tiers, la liste désignant déjà
        // le nouveau.
        <div
            className={`@container space-y-4 transition-opacity ${enTransition ? 'opacity-60' : ''}`}
            aria-busy={enTransition}
            inert={enTransition}
        >
            {/* ---------------------------- identité ---------------------------- */}
            <div className="rounded-xl bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        {/* Au bureau la liste est déjà à côté : le lien ne sert
                            qu'en pleine page, et y garde les filtres. Il dit à la
                            liste d'où l'on revient : elle y rend le focus, au
                            lieu de le laisser tomber sur <body>. */}
                        <Link
                            to={retour}
                            state={{ depuis: tiers.id }}
                            className="text-sm text-emerald-700 hover:underline xl:hidden"
                        >
                            <span aria-hidden className="inline-block rtl:rotate-180">
                                ←
                            </span>{' '}
                            {t('Tous les tiers')}
                        </Link>
                        {/* <bdi> : même raison que dans la liste — un nom latin
                            ouvert par un chiffre se retournait en arabe. */}
                        <h1
                            ref={titre}
                            tabIndex={-1}
                            className="mt-1 break-words text-xl font-semibold text-slate-900 focus:outline-none xl:mt-0"
                        >
                            <bdi>{tiers.name}</bdi>
                        </h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                            <span className="font-mono text-xs">{tiers.code}</span>
                            {tiers.is_client && <Etiquette couleur="emerald">{t('Client')}</Etiquette>}
                            {tiers.is_supplier && <Etiquette couleur="sky">{t('Fournisseur')}</Etiquette>}
                            {tiers.is_prospect && <Etiquette couleur="violet">{t('Prospect')}</Etiquette>}
                            {!tiers.is_active && <Etiquette couleur="slate">{t('Inactif')}</Etiquette>}
                        </div>
                        <div className="mt-2 space-y-0.5 break-words text-sm text-slate-600">
                            {tiers.ice && <div>ICE : {tiers.ice}</div>}
                            {tiers.address && <div>{tiers.address}</div>}
                            {(tiers.city || tiers.postal_code) && (
                                <div>{[tiers.postal_code, tiers.city].filter(Boolean).join(' ')}</div>
                            )}
                            {tiers.phone && <div>{tiers.phone}</div>}
                            {tiers.email && <div>{tiers.email}</div>}
                        </div>
                    </div>

                    {/* Chaque action n'est proposée qu'à qui peut la mener au
                        bout : sinon le formulaire se remplit, et l'enregistrement
                        se solde par un refus. */}
                    <div className="flex flex-wrap gap-2">
                        {can('ventes', 'write') && (
                            <>
                                <Link
                                    to={`/ventes/nouveau?type=devis&tiers_id=${id}`}
                                    className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                                >
                                    + {t('Devis')}
                                </Link>
                                <Link
                                    to={`/ventes/nouveau?type=facture&tiers_id=${id}`}
                                    className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                                >
                                    + {t('Facture')}
                                </Link>
                            </>
                        )}
                        {/* Pendant des deux boutons de vente pour un fournisseur :
                            le formulaire d'achat lit lui aussi ?tiers_id=. */}
                        {tiers.is_supplier && can('achats', 'write') && (
                            <Link
                                to={`/achats/nouveau?type=commande&tiers_id=${id}`}
                                className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                            >
                                + {t('Commande fournisseur')}
                            </Link>
                        )}
                        {can('tiers', 'write') && (
                            <>
                                {/* La chaîne de requête suit jusqu'au formulaire, qui la
                                    rend au retour : modifier un tiers ne coûte pas ses filtres. */}
                                <Link
                                    to={{ pathname: `/tiers/${id}/modifier`, search: retour.search }}
                                    className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                                >
                                    {t('Modifier')}
                                </Link>
                                <button
                                    type="button"
                                    onClick={supprimer}
                                    disabled={suppression.isPending}
                                    className="rounded-md border border-red-200 px-3 py-2 text-sm text-red-600 transition hover:bg-red-50 disabled:opacity-50"
                                >
                                    {t('Supprimer')}
                                </button>
                            </>
                        )}
                    </div>
                </div>

                {erreurSuppression && (
                    <p role="alert" className="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {erreurSuppression}
                    </p>
                )}

                {/* Les cases sont posées même sans synthèse : leur arrivée ne
                    décale rien, et « … » ne prétend aucun montant. */}
                <div className="mt-5 grid gap-3 border-t border-slate-100 pt-4 @md:grid-cols-2 @3xl:grid-cols-4">
                    <Kpi libelle={t("Chiffre d'affaires")} valeur={kpi((s) => formatMAD(s.ca_ttc))} />
                    <Kpi libelle={t('Sur 12 mois')} valeur={kpi((s) => formatMAD(s.ca_12_mois))} />
                    <Kpi
                        libelle={t('Impayé')}
                        valeur={kpi((s) => formatMAD(s.impaye))}
                        alerte={Number(synthese?.impaye ?? 0) > 0}
                    />
                    <Kpi
                        libelle={t('Relation depuis')}
                        valeur={kpi((s) => s.premier_document ?? '—')}
                        detail={synthese?.dernier_document ? `${t('dernière pièce')} ${synthese.dernier_document}` : undefined}
                    />
                </div>
                {syntheseIndisponible && (
                    <p role="alert" className="mt-3 text-sm text-amber-800">
                        {t('Chiffres indisponibles.')}
                        <button
                            type="button"
                            onClick={onRechargerSynthese}
                            className="ms-2 font-medium text-amber-900 underline"
                        >
                            {t('Réessayer')}
                        </button>
                    </p>
                )}
            </div>

            {/* ---------------------------- onglets ----------------------------- */}
            <div className="flex max-w-full gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1">
                {ONGLETS.map((o) => (
                    <button
                        key={o.cle}
                        type="button"
                        onClick={() => changerOnglet(o.cle)}
                        aria-pressed={onglet === o.cle}
                        className={`shrink-0 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition ${
                            onglet === o.cle ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        {o.label}
                        {o.compte !== undefined && (
                            <span className={onglet === o.cle ? 'ms-1.5 text-emerald-100' : 'ms-1.5 text-slate-400'}>
                                {o.compte}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* ---------------------------- contenu ----------------------------- */}
            {onglet === 'historique' && historiqueVisible && <TiersTimeline tiersId={String(tiers.id)} />}

            {onglet === 'contacts' && <ContactsTiers tiersId={tiers.id} />}

            {onglet === 'produits' && (
                <Tableau
                    vide={produits.isLoading ? t('Chargement…') : t('Aucun article facturé à ce tiers.')}
                    montrerVide={!produits.data || produits.data.length === 0}
                    requete={produits}
                    entetes={[t('Référence'), t('Article'), t('Quantité'), t('Total HT'), t('Pièces')]}
                >
                    {produits.data?.map((p) => (
                        <tr key={p.produit_id} className="hover:bg-slate-50">
                            <td className="px-4 py-3 font-mono text-xs text-slate-500">{p.code}</td>
                            <td className="px-4 py-3 font-medium text-slate-900">{p.name}</td>
                            <td className="px-4 py-3 text-end tabular-nums text-slate-600">
                                {Number(p.quantite).toLocaleString('fr-MA')} {p.unit}
                            </td>
                            <td className="px-4 py-3 text-end tabular-nums text-slate-900">{formatMAD(p.montant_ht)}</td>
                            <td className="px-4 py-3 text-end tabular-nums text-slate-500">{p.occurrences}</td>
                        </tr>
                    ))}
                </Tableau>
            )}

            {onglet === 'achats' && achatsVisibles && (
                <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                    <Tableau
                        vide={achats.isLoading ? t('Chargement…') : t('Aucun achat auprès de ce fournisseur.')}
                        montrerVide={!achats.data || achats.data.data.length === 0}
                        requete={achats}
                        entetes={[t('Code'), t('Date'), t('Total TTC'), t('Statut')]}
                        sansCadre
                    >
                        {achats.data?.data.map((doc) => (
                            <tr key={doc.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3">
                                    <Link to={`/achats/${doc.id}`} className="font-mono text-xs text-emerald-700 hover:underline">
                                        {doc.code}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 text-slate-600">{doc.date_document}</td>
                                <td className="px-4 py-3 text-end tabular-nums text-slate-900">{formatMAD(doc.total_ttc)}</td>
                                <td className="px-4 py-3 text-end text-slate-600">{doc.statut}</td>
                            </tr>
                        ))}
                    </Tableau>
                    {achats.data && <Pagination meta={achats.data.meta} onPage={setPage} />}
                </div>
            )}

            {estDocument && (
                <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                    <Tableau
                        vide={documents.isLoading ? t('Chargement…') : t('Aucune pièce de ce type pour ce tiers.')}
                        montrerVide={!documents.data || documents.data.data.length === 0}
                        requete={documents}
                        entetes={[t('Code'), t('Date'), t('Total TTC'), t('Statut')]}
                        sansCadre
                    >
                        {documents.data?.data.map((doc) => (
                            <tr
                                key={doc.id}
                                onClick={() => setApercu(doc.id)}
                                className={`cursor-pointer transition ${
                                    apercu === doc.id ? 'bg-emerald-50' : 'hover:bg-slate-50'
                                }`}
                            >
                                <td className="px-4 py-3">
                                    {/* Le vrai déclencheur, atteignable au clavier :
                                        c'est à lui que la surcouche rend le focus. */}
                                    <button
                                        type="button"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setApercu(doc.id);
                                        }}
                                        className="rounded font-mono text-xs font-medium text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                    >
                                        {doc.code}
                                    </button>
                                </td>
                                <td className="px-4 py-3 text-slate-600">{doc.date_document}</td>
                                <td className="px-4 py-3 text-end tabular-nums text-slate-900">
                                    {formatMAD(doc.total_ttc)}
                                </td>
                                <td className="px-4 py-3 text-end">
                                    <span className={`rounded px-1.5 py-0.5 text-xs ${statutClasses(doc.statut)}`}>
                                        {statutLabel(doc)}
                                    </span>
                                </td>
                            </tr>
                        ))}
                    </Tableau>
                    {documents.data && <Pagination meta={documents.data.meta} onPage={setPage} />}
                </div>
            )}

            {apercu !== null && (
                <ApercuVente id={apercu} onFermer={() => setApercu(null)} surcouche lienClient={false} />
            )}
        </div>
    );
}

/* ---------------------------------------------------------------------- */

/** Ce qu'on montre quand la fiche ne PEUT pas s'afficher — et pourquoi. */
function FicheIndisponible({
    erreur,
    onReessayer,
    retour,
}: {
    erreur: unknown;
    onReessayer: () => void;
    retour: { pathname: string; search: string };
}) {
    const t = useT();
    const statut = isAxiosError(erreur) ? erreur.response?.status : undefined;

    const [titre, detail] =
        statut === 404
            ? [t('Tiers introuvable'), t("Il a peut-être été supprimé, ou il n'appartient pas à cette entreprise.")]
            : statut === 403
              ? [t('Accès refusé'), t("Votre rôle ne donne pas accès aux fiches des tiers.")]
              : [t('Impossible de charger ce tiers.'), t('Vérifiez la connexion, puis réessayez.')];

    return (
        <div role="alert" className="rounded-xl bg-white p-8 text-center shadow-sm">
            <h1 className="font-medium text-slate-900">{titre}</h1>
            <p className="mt-1 text-sm text-slate-500">{detail}</p>
            <div className="mt-4 flex flex-wrap justify-center gap-2">
                {statut !== 404 && statut !== 403 && (
                    <button
                        type="button"
                        onClick={onReessayer}
                        className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                    >
                        {t('Réessayer')}
                    </button>
                )}
                <Link
                    to={retour}
                    className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 xl:hidden"
                >
                    {t('Tous les tiers')}
                </Link>
            </div>
        </div>
    );
}

function Kpi({
    libelle,
    valeur,
    detail,
    alerte,
}: {
    libelle: string;
    valeur: string;
    detail?: string;
    alerte?: boolean;
}) {
    return (
        <div className="min-w-0 rounded-lg bg-slate-50 px-4 py-3">
            <div className="text-xs uppercase tracking-wide text-slate-500">{libelle}</div>
            <div className={`mt-0.5 truncate text-lg font-semibold ${alerte ? 'text-amber-700' : 'text-slate-900'}`}>
                {valeur}
            </div>
            {detail && <div className="truncate text-xs text-slate-400">{detail}</div>}
        </div>
    );
}

function Etiquette({ couleur, children }: { couleur: string; children: React.ReactNode }) {
    const couleurs: Record<string, string> = {
        emerald: 'bg-emerald-100 text-emerald-700',
        sky: 'bg-sky-100 text-sky-700',
        violet: 'bg-violet-100 text-violet-700',
        slate: 'bg-slate-200 text-slate-600',
    };

    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${couleurs[couleur]}`}>{children}</span>;
}

/** Ce que Tableau lit d'une requête pour ne pas prendre un échec pour du vide. */
type EtatRequete = { isError: boolean; error: unknown; data: unknown; isFetching: boolean; refetch: () => unknown };

function Tableau({
    entetes,
    children,
    vide,
    montrerVide,
    requete,
    sansCadre,
}: {
    entetes: string[];
    children: React.ReactNode;
    vide: string;
    montrerVide: boolean;
    requete: EtatRequete;
    sansCadre?: boolean;
}) {
    const t = useT();

    // Un échec n'est PAS une liste vide : « Aucune pièce » sous un onglet
    // « Factures 12 » faisait croire à un compteur faux. Seulement sans donnée
    // à montrer : un rafraîchissement raté garde la page déjà lue.
    if (requete.isError && requete.data === undefined) {
        const refuse = isAxiosError(requete.error) && requete.error.response?.status === 403;

        return (
            <div className={sansCadre ? '' : 'rounded-xl bg-white shadow-sm'}>
                <div role="alert" className="px-4 py-8 text-center text-sm text-amber-800">
                    {refuse ? t('Votre rôle ne donne pas accès à ces pièces.') : t('Impossible de charger ces pièces.')}
                    {!refuse && (
                        <button
                            type="button"
                            onClick={() => requete.refetch()}
                            disabled={requete.isFetching}
                            className="ms-2 font-medium text-amber-900 underline disabled:opacity-50"
                        >
                            {t('Réessayer')}
                        </button>
                    )}
                </div>
            </div>
        );
    }

    return (
        <div className={sansCadre ? '' : 'overflow-x-auto rounded-xl bg-white shadow-sm'}>
            <table className="w-full text-start text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        {entetes.map((e, i) => (
                            <th key={e} className={`px-4 py-3 ${i >= 2 ? 'text-end' : 'text-start'}`}>
                                {e}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {montrerVide ? (
                        <tr>
                            <td colSpan={entetes.length} className="px-4 py-8 text-center text-slate-400">
                                {vide}
                            </td>
                        </tr>
                    ) : (
                        children
                    )}
                </tbody>
            </table>
        </div>
    );
}
