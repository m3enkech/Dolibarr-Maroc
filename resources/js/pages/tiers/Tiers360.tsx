import { useEffect, useRef, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useFeatures } from '@/lib/features';
import { formatMAD } from '@/lib/format';
import { useFormats } from '@/lib/formats-langue';
import { useT } from '@/lib/langue';
import MenuDeroulant, { type ElementMenu } from '@/components/MenuDeroulant';
import Pagination from '@/components/Pagination';
import { achatStatutClasses, achatStatutLabel } from '@/pages/achats/common';
import ApercuVente from '@/pages/ventes/ApercuVente';
import { statutClasses, statutLabel } from '@/pages/ventes/common';
import CommentairesTiers from '@/pages/tiers/CommentairesTiers';
import ContactsTiers from '@/pages/tiers/ContactsTiers';
import { aujourdHui, lireDate, lirePeriode, PERIODE_DEFAUT, sansParamsFiche, type EtatRetourListe } from '@/pages/tiers/params';
import ReleveTiers from '@/pages/tiers/ReleveTiers';
import SoldeOuverture, { type CompteTiers } from '@/pages/tiers/SoldeOuverture';
import TiersTimeline from '@/pages/tiers/TiersTimeline';
import VueEnsembleTiers, { type VueEnsembleReponse } from '@/pages/tiers/VueEnsembleTiers';
import type { DocumentAchat, DocumentType, DocumentVente, Paginated, Tiers } from '@/types';

type Synthese = {
    ventes: { devis: number; commandes: number; bons_livraison: number; factures: number; avoirs: number };
    achats: number;
    contacts: number;
    /** Le compteur de l'onglet Commentaires ; le fil se charge à l'ouverture. */
    commentaires: number;
    // Absents — pas nuls — sans le droit ventes (chiffre d'affaires) ou
    // achats (achats facturés) : le serveur les omet bloc par bloc.
    ca_ttc?: string;
    /** Les mêmes douze mois entiers que le graphique « 12 derniers mois ». */
    ca_12_mois?: string;
    // `impaye` existe aussi dans la réponse, calculé sur les PIÈCES : il
    // n'est volontairement pas lu. L'impayé affiché est celui du grand
    // livre, servi par la vue d'ensemble — le même que la liste.
    achats_ttc?: string;
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

/** Les onglets, tels qu'ils s'écrivent dans l'URL (`?onglet=`). */
type Onglet = 'apercu' | 'commentaires' | 'transactions' | 'articles' | 'releve' | 'contacts' | 'historique';

/** Le sous-filtre des transactions (`?type_piece=`) : un type de pièce de vente, toutes, ou les achats. */
type TypePiece = 'toutes' | DocumentType | 'achats';

const TYPES_VENTE: TypePiece[] = ['toutes', 'devis', 'commande', 'bon_livraison', 'facture', 'avoir'];

/** Un refus (403, 404) ne changera pas au second essai : seule une panne réseau mérite qu'on réessaie. */
const reessayerSiPanne = (echecs: number, err: unknown) =>
    isAxiosError(err) && err.response !== undefined ? false : echecs < 1;

/**
 * Le message du serveur pour un REFUS (403) ou une demande invalide (422) :
 * ceux-là sont écrits pour l'utilisateur, passés par `__()`, donc dans sa
 * langue — « ce tiers a des pièces… ». Tout le reste garde notre texte : un
 * 404 recopie « No query results for model [...] », un 500 « Server Error »,
 * en anglais jusque sur l'écran arabe.
 */
const messageServeur = (err: unknown, defaut: string) => {
    const statut = isAxiosError(err) ? err.response?.status : undefined;
    const message = isAxiosError(err) ? (err.response?.data as { message?: string } | undefined)?.message : undefined;

    return (statut === 403 || statut === 422) && message ? message : defaut;
};

/**
 * La fiche d'un tiers, en CONSULTATION — colonne de droite de l'espace tiers
 * au bureau, page entière sur téléphone.
 *
 * Elle répond aux questions qu'on se pose devant un client avant de l'appeler :
 * combien il pèse, ce qu'il doit, ce qu'il prend d'habitude, à qui parler. Il
 * fallait jusqu'ici ouvrir quatre écrans et recouper de tête.
 *
 * CINQ PARTIS PRIS.
 *
 * 1. CONSULTATION PAR DÉFAUT, ÉDITION SUR DEMANDE. `/tiers/:id` ouvrait le
 *    FORMULAIRE : on venait regarder l'historique d'un client, on se retrouvait
 *    à pouvoir modifier son ICE. L'édition vit maintenant sur
 *    `/tiers/:id/modifier`, derrière un bouton.
 *
 * 2. UN EN-TÊTE QUI AGIT, DES ONGLETS QUI MONTRENT — façon Zoho. En haut, le
 *    nom et trois portes : Modifier, « Nouvelle transaction » (les pièces que
 *    ce tiers peut recevoir) et « Plus » (ce qui change son statut). Dessous,
 *    cinq onglets au lieu de neuf : les pièces de vente et d'achat sont
 *    regroupées sous « Transactions », avec un sous-filtre par type.
 *
 * 3. L'ONGLET EST DANS L'URL. Il survit au rechargement, et les liens de la
 *    liste le portent : on passe d'un client à l'autre en restant sur ses
 *    factures, au lieu de rouvrir l'onglet à chaque ligne.
 *
 * 4. LES ONGLETS CHARGENT CE QU'ON OUVRE, ET RIEN D'AUTRE. Les compteurs
 *    viennent d'une synthèse d'agrégats ; les pièces, de l'endpoint de liste
 *    déjà paginé et filtré. Charger les quatre cents factures d'un gros client
 *    pour n'en afficher que le nombre, c'est payer la page pour un chiffre.
 *
 * 5. CHANGER DE TIERS REMET LA FICHE À ZÉRO, SANS LA FAIRE CLIGNOTER. La
 *    fiche est remontée à chaque tiers (page, aperçu et messages du précédent
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

    const retour = { pathname: '/tiers', search: sansParamsFiche(location.search) };

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
    const { pathname, search } = useLocation();
    const [params, setParams] = useSearchParams();
    // Tout part du tiers AFFICHÉ, jamais de l'URL : les deux divergent le temps
    // d'une transition, et c'est sur celui qu'on voit qu'on agit.
    const id = String(tiers.id);

    // Ce que l'URL désigne À L'INSTANT, lu après coup par la suppression : on
    // a pu ouvrir un autre tiers pendant qu'elle partait.
    const { id: idRoute } = useParams<{ id: string }>();
    const routeCourante = useRef({ id: idRoute, retour, ici: { pathname, search } });
    routeCourante.current = { id: idRoute, retour, ici: { pathname, search } };

    // Un FOURNISSEUR PUR n'a pas de compte client : chiffre d'affaires, impayé
    // et pièces de vente n'y ont pas de sens, et « 0,00 » laisserait croire
    // qu'on a vérifié. Un tiers à la fois client et fournisseur, lui, garde
    // tout ce qu'a un client. Un prospect est un client à venir.
    const fournisseurPur = tiers.is_supplier && !tiers.is_client && !tiers.is_prospect;

    // L'historique est servi par le module CRM : sa route répond 403 quand le
    // module est désactivé (défaut) ou que le rôle n'y a pas accès (comptable,
    // caissier). Proposer un onglet qui ne peut que refuser, c'est montrer une
    // liste vide qui fait croire à un client sans histoire. Même double test
    // que le menu : le module, puis le droit. Les pièces suivent la même
    // règle : un caissier (ventes fermées) voyait « Factures 12 » au-dessus de
    // « Aucune pièce », le compteur venant de la synthèse, la liste d'un refus.
    const historiqueVisible = features.crm && can('crm');
    const achatsVisibles = tiers.is_supplier && can('achats');
    const totalVentes = synthese
        ? synthese.ventes.devis +
          synthese.ventes.commandes +
          synthese.ventes.bons_livraison +
          synthese.ventes.factures +
          synthese.ventes.avoirs
        : undefined;
    // Chez un fournisseur pur, les ventes ne sont listées que s'il en EXISTE :
    // rien n'interdit de facturer un fournisseur, et cacher ces pièces-là
    // ferait disparaître une dette.
    const ventesListees = can('ventes') && (!fournisseurPur || (totalVentes ?? 0) > 0);
    const transactionsVisibles = ventesListees || achatsVisibles;

    // Le RELEVÉ, compte par compte, avec les règles du serveur. Le compte
    // CLIENT se lit avec les tiers — ce que doit le client, comme la carte du
    // compte et la liste ; un fournisseur pur n'en a pas, sauf s'il a reçu
    // des factures (même règle que ses ventes ci-dessus). Le compte
    // FOURNISSEUR dit ce qu'on lui achète : il faut le droit achats, comme
    // pour le résumé des achats. Un fournisseur pur ouvre sur le sien.
    const ventesFacturees = (synthese?.ventes.factures ?? 0) + (synthese?.ventes.avoirs ?? 0) > 0;
    const comptesReleve: CompteTiers[] = fournisseurPur
        ? [...(achatsVisibles ? (['fournisseur'] as const) : []), ...(ventesFacturees ? (['client'] as const) : [])]
        : ['client', ...(achatsVisibles ? (['fournisseur'] as const) : [])];
    const releveVisible = comptesReleve.length > 0;

    // Le solde d'ouverture est une ÉCRITURE : la garde de la compta, celle de
    // sa route. Les comptes proposés sont ceux que le tiers A — pas ceux
    // qu'on peut lire : le comptable sans droit achats saisit quand même la
    // dette d'ouverture d'un fournisseur.
    const peutSaisirOuverture = can('compta', 'write');
    const comptesOuverture: CompteTiers[] = fournisseurPur
        ? ['fournisseur']
        : tiers.is_supplier
          ? ['client', 'fournisseur']
          : ['client'];
    // Le compte par lequel la saisie est ouverte passe en premier (défaut).
    const [saisieOuverture, setSaisieOuverture] = useState<CompteTiers | null>(null);

    // Après une saisie, la surcouche rend le focus au lien « Saisir le solde
    // d'ouverture »… que la relecture fait disparaître dès que le solde
    // arrive : le focus tombait sur <body>. On le pose sur le titre de la
    // carte ou du relevé d'où l'on venait (`data-retour-ouverture`), APRÈS le
    // nettoyage de la surcouche — un effet du parent passe après lui.
    const focusApresOuverture = useRef(false);
    useEffect(() => {
        if (saisieOuverture !== null || !focusApresOuverture.current) return;
        focusApresOuverture.current = false;
        const cible = document.querySelector<HTMLElement>('[data-retour-ouverture]') ?? titre.current;
        cible?.focus();
    }, [saisieOuverture]);

    // Qu'il en existe, on ne le sait qu'à l'arrivée de la synthèse. D'ici là,
    // une URL qui demande une pièce de VENTE à un fournisseur pur (venue d'un
    // client, où l'on lisait ses factures) ATTEND au lieu de trancher :
    // retombée sur les achats, la fiche lançait leur requête et les
    // affichait, puis basculait sur les factures sous les yeux. Même attente
    // pour un rôle sans achats, qui n'a d'onglet Transactions que si ce
    // fournisseur a des ventes — il retombait sur la vue d'ensemble.
    const ventesInconnues = fournisseurPur && can('ventes') && totalVentes === undefined && !syntheseIndisponible;
    const typeDemande = params.get('type_piece');
    const attenteVentes =
        ventesInconnues &&
        params.get('onglet') === 'transactions' &&
        (!achatsVisibles || TYPES_VENTE.some((type) => type === typeDemande));

    // La même attente pour le RELEVÉ, dont les comptes d'un fournisseur pur
    // dépendent aussi de ses ventes (`ventesFacturees`). Sans elle, un rôle
    // sans achats retombait sur la vue d'ensemble (et la chargeait) avant
    // que l'onglet Relevé n'apparaisse et ne s'active sous ses yeux ; un
    // comptable venu avec ?compte=client voyait le relevé FOURNISSEUR se
    // charger, puis basculer sur le relevé client.
    const releveInconnu = fournisseurPur && synthese === undefined && !syntheseIndisponible;
    const attenteReleve =
        releveInconnu && params.get('onglet') === 'releve' && (!achatsVisibles || params.get('compte') === 'client');

    // Onglet et sous-filtre LUS dans l'URL. Une valeur qui ne vaut pas pour CE
    // tiers (les achats d'un client, l'historique sans le CRM) retombe sur le
    // défaut SANS réécrire l'URL : le fournisseur suivant retrouvera ses achats.
    // Les commentaires juste après la vue d'ensemble, comme chez Zoho, et pour
    // TOUS : leur garde est celle de la fiche (`tiers`), pas le CRM.
    const ongletsOuverts: Onglet[] = [
        'apercu',
        'commentaires',
        ...(transactionsVisibles ? (['transactions'] as const) : []),
        'articles',
        ...(releveVisible ? (['releve'] as const) : []),
        'contacts',
        ...(historiqueVisible ? (['historique'] as const) : []),
    ];

    // Compte et période du relevé, LUS dans l'URL. Absents ou invalides : le
    // premier compte offert, et du 1er janvier de l'année de « au » à « au »,
    // « au » valant aujourd'hui — les défauts du serveur.
    const compteReleve = comptesReleve.find((c) => c === params.get('compte')) ?? comptesReleve[0] ?? 'client';
    const auReleve = lireDate(params.get('au')) ?? aujourdHui();
    const duReleve = lireDate(params.get('du')) ?? `${auReleve.slice(0, 4)}-01-01`;
    // Une date égale à son défaut ne s'écrit pas dans l'URL : un lien gardé
    // reste « jusqu'à aujourd'hui » au lieu de se figer sur le jour du clic.
    const majPeriode = ({ du, au }: { du: string | null; au: string | null }) => {
        const auRetenu = au ?? aujourdHui();
        majParams({
            au: au === null || au === aujourdHui() ? null : au,
            du: du === null || du === `${auRetenu.slice(0, 4)}-01-01` ? null : du,
        });
    };
    const onglet: Onglet = attenteVentes
        ? 'transactions'
        : attenteReleve
          ? 'releve'
          : (ongletsOuverts.find((o) => o === params.get('onglet')) ?? 'apercu');

    const typesOuverts: TypePiece[] = fournisseurPur
        ? [...(achatsVisibles ? (['achats'] as const) : []), ...(ventesListees ? TYPES_VENTE : [])]
        : [...(ventesListees ? TYPES_VENTE : []), ...(achatsVisibles ? (['achats'] as const) : [])];
    const typePiece = typesOuverts.find((x) => x === params.get('type_piece')) ?? typesOuverts[0] ?? 'toutes';

    // La page des pièces appartient au couple onglet + sous-filtre qui l'a vue
    // naître : en changer — par un clic ou par l'URL — repart de la première,
    // sans effet à synchroniser.
    const vue = `${onglet}|${typePiece}`;
    const [pagination, setPagination] = useState({ vue, page: 1 });
    const page = pagination.vue === vue ? pagination.page : 1;
    const setPage = (p: number) => setPagination({ vue, page: p });
    const [apercu, setApercu] = useState<number | null>(null);

    /**
     * Les changements d'onglet REMPLACENT l'entrée d'historique, comme les
     * filtres de la liste : « Précédent » ramène au tiers d'avant, pas à
     * l'onglet d'avant. Les filtres de la liste restent intacts.
     */
    const majParams = (changements: Record<string, string | null>) => {
        setApercu(null);
        setParams(
            (courants) => {
                const suivants = new URLSearchParams(courants);
                for (const [cle, valeur] of Object.entries(changements)) {
                    if (valeur === null) suivants.delete(cle);
                    else suivants.set(cle, valeur);
                }

                return suivants;
            },
            { replace: true },
        );
    };

    const documentsOuverts = onglet === 'transactions' && !attenteVentes && typePiece !== 'achats' && ventesListees;
    const documents = useQuery({
        queryKey: ['tiers-documents', id, typePiece, page],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentVente>>('/ventes/documents', {
                params: { tiers_id: id, type: typePiece === 'toutes' ? undefined : typePiece, page, per_page: 15 },
            });
            return data;
        },
        enabled: documentsOuverts,
        placeholderData: keepPreviousData,
        retry: reessayerSiPanne,
    });

    const achatsOuverts = onglet === 'transactions' && !attenteVentes && typePiece === 'achats';
    const achats = useQuery({
        queryKey: ['tiers-achats', id, page],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentAchat>>('/achats/documents', {
                params: { tiers_id: id, page, per_page: 15 },
            });
            return data;
        },
        enabled: achatsOuverts,
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
        enabled: onglet === 'articles',
        retry: reessayerSiPanne,
    });

    // La vue d'ensemble entière en UN appel : contact principal, portail,
    // conditions, crédit, compte client et revenus de la période. La période
    // est dans l'URL ; une valeur inconnue y retombe sur le défaut AVANT
    // l'appel. En changer garde le graphique précédent, estompé, le temps
    // que l'autre arrive — le reste de la vue ne dépend pas de la période.
    const periode = lirePeriode(params.get('periode'));
    const vueEnsemble = useQuery({
        queryKey: ['tiers-vue-ensemble', tiers.id, periode],
        queryFn: async () => {
            const { data } = await api.get<VueEnsembleReponse>(`/tiers/${tiers.id}/vue-ensemble`, { params: { periode } });
            return data;
        },
        enabled: onglet === 'apercu',
        placeholderData: keepPreviousData,
        retry: reessayerSiPanne,
    });
    const vueIndisponible = vueEnsemble.isError && vueEnsemble.data === undefined;
    const { montant } = useFormats();

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

    /* ------------------------------------------------------------------ */
    /* Actions qui écrivent : convertir, désactiver / réactiver, supprimer */
    /* ------------------------------------------------------------------ */

    // Le retour visible de la dernière action. Sans lui, « Désactiver » ne
    // changeait qu'une étiquette grise : on ne savait pas si c'était fait.
    const [compteRendu, setCompteRendu] = useState<{ ok: boolean; texte: string } | null>(null);

    /**
     * Ce qu'une écriture sur le tiers rend périmé : sa fiche et la liste
     * (solde « 0,00 » d'un client confirmé, étiquettes, filtre actif), le
     * formulaire de modification, et les sélecteurs des pièces — le prospect
     * converti doit apparaître parmi les clients d'un nouveau devis.
     */
    const rafraichir = (saufLaFiche = false) => {
        queryClient.invalidateQueries({
            queryKey: ['tiers'],
            // Après une suppression : relire sa fiche ne rapporterait qu'un 404.
            predicate: saufLaFiche ? (requete) => requete.queryKey[1] !== id : undefined,
        });
        queryClient.invalidateQueries({ queryKey: ['tiers-detail', id] });
        // Convertir un prospect ou toucher au tiers peut changer ce qu'il est
        // (client ou non) : la vue d'ensemble se relit, toutes périodes.
        queryClient.invalidateQueries({ queryKey: ['tiers-vue-ensemble', tiers.id] });
        queryClient.invalidateQueries({ queryKey: ['selecteur-tiers'] });
        queryClient.invalidateQueries({ queryKey: ['selecteur-tiers-fiche', tiers.id] });
        queryClient.invalidateQueries({ queryKey: ['tiers-count'] });
    };

    /** Le tiers que renvoie le serveur s'affiche AUSSITÔT : badge et menus suivent sans attendre la relecture. */
    const appliquer = (maj: Tiers) => {
        queryClient.setQueryData<Tiers>(['tiers', id], (avant) => (avant ? { ...avant, ...maj } : maj));
        rafraichir();
    };

    /**
     * Un échec, dit sur la fiche — sauf le 404 : le tiers a été supprimé
     * ailleurs (autre onglet, collègue). Une alerte sur une fiche restée
     * affichée la laisserait croire vivante ; on la relit DE ZÉRO (relue
     * simplement, elle garderait ses données en échec), et elle bascule sur
     * « Tiers introuvable ». La liste, elle, perd sa ligne.
     */
    const echec = (err: unknown, defaut: string) => {
        if (isAxiosError(err) && err.response?.status === 404) {
            rafraichir(true);
            queryClient.resetQueries({ queryKey: ['tiers', id], exact: true });

            return;
        }

        setCompteRendu({ ok: false, texte: messageServeur(err, defaut) });
    };

    const conversion = useMutation({
        mutationFn: async () => (await api.post<{ data: Tiers }>(`/tiers/${id}/convertir`)).data.data,
        onSuccess: (maj) => {
            appliquer(maj);
            // Les statistiques CRM comptent les conversions de la période.
            queryClient.invalidateQueries({ queryKey: ['crm-stats'] });
            setCompteRendu({ ok: true, texte: t('« {nom} » est désormais client.', { nom: maj.name }) });
        },
        onError: (err) => echec(err, t('La conversion a échoué.')),
    });

    // Désactiver passe par la mise à jour existante (PUT, `is_active` seul) :
    // un tiers désactivé garde son code, ses pièces et ses impayés ; il sort
    // seulement des listes filtrées sur les actifs.
    const activation = useMutation({
        mutationFn: async (actif: boolean) => (await api.put<{ data: Tiers }>(`/tiers/${id}`, { is_active: actif })).data.data,
        onSuccess: (maj) => {
            appliquer(maj);
            setCompteRendu({
                ok: true,
                texte: maj.is_active
                    ? t('Tiers réactivé.')
                    : t('Tiers désactivé : sa fiche et ses pièces restent consultables.'),
            });
        },
        onError: (err) => echec(err, t('La mise à jour a échoué.')),
    });

    // Un tiers qui a des pièces est refusé par le serveur (422) : son message,
    // « désactivez-le plutôt », s'affiche ici tel quel.
    const suppression = useMutation({
        mutationFn: () => api.delete(`/tiers/${id}`),
        onSuccess: () => rafraichir(true),
        onError: (err) => echec(err, t('La suppression a échoué.')),
    });

    const ecritureEnCours = conversion.isPending || activation.isPending || suppression.isPending;

    const supprimer = () => {
        setCompteRendu(null);
        if (window.confirm(t('Supprimer « {nom} » ({code}) ?', { nom: tiers.name, code: tiers.code }))) {
            // Le retour à la liste vit ICI et non dans useMutation : ce rappel-ci
            // n'est pas appelé si la fiche a été démontée entre-temps. Et même
            // montée, on ne ferme que si l'URL désigne ENCORE ce tiers — sinon
            // on fermait la fiche ouverte depuis. `replace` : la fiche d'un tiers
            // supprimé ne doit pas revenir par « Précédent », en « introuvable ».
            //
            // Le compte rendu voyage dans l'état de navigation : la fiche, qui
            // le portait pour les autres actions, disparaît avec le tiers ; la
            // liste, elle, reste — c'est elle qui dit « supprimé ». Fiche d'un
            // autre tiers ouverte entre-temps : on reste dessus, mais la liste
            // le dit quand même.
            suppression.mutate(undefined, {
                onSuccess: () => {
                    const { id: idCourant, retour: versLaListe, ici } = routeCourante.current;
                    const etat: EtatRetourListe = { supprime: { nom: tiers.name, code: tiers.code } };

                    navigate(idCourant === id ? versLaListe : ici, { replace: true, state: etat });
                },
            });
        }
    };

    // Chaque entrée n'est proposée qu'à qui peut la mener au bout : sinon le
    // formulaire se remplit, et l'enregistrement se solde par un refus. Les
    // pièces de vente ne sont pas proposées à un fournisseur pur ; un avoir ne
    // se crée que depuis sa facture, il n'a pas sa place ici.
    const vente = (type: DocumentType) => `/ventes/nouveau?type=${type}&tiers_id=${id}`;
    const achat = (type: string) => `/achats/nouveau?type=${type}&tiers_id=${id}`;
    const nouvelles: ElementMenu[] = [
        ...(can('ventes', 'write') && !fournisseurPur
            ? ([
                  { genre: 'lien', cle: 'devis', libelle: t('Devis'), vers: vente('devis') },
                  { genre: 'lien', cle: 'commande', libelle: t('Commande'), vers: vente('commande') },
                  { genre: 'lien', cle: 'bon_livraison', libelle: t('Bon de livraison'), vers: vente('bon_livraison') },
                  { genre: 'lien', cle: 'facture', libelle: t('Facture'), vers: vente('facture') },
              ] as ElementMenu[])
            : []),
        { genre: 'separateur', cle: 'achats' },
        // Le formulaire d'achat lit lui aussi ?type= et ?tiers_id= ; il ne
        // propose que des fournisseurs, d'où la condition sur le tiers.
        ...(tiers.is_supplier && can('achats', 'write')
            ? ([
                  { genre: 'lien', cle: 'achat-commande', libelle: t('Commande fournisseur'), vers: achat('commande') },
                  { genre: 'lien', cle: 'achat-reception', libelle: t('Réception fournisseur'), vers: achat('reception') },
                  { genre: 'lien', cle: 'achat-facture', libelle: t('Facture fournisseur'), vers: achat('facture') },
              ] as ElementMenu[])
            : []),
    ];

    const plus: ElementMenu[] = can('tiers', 'write')
        ? [
              ...(tiers.is_prospect
                  ? ([
                        {
                            genre: 'action',
                            cle: 'convertir',
                            libelle: t('Convertir en client'),
                            desactive: ecritureEnCours,
                            onChoisir: () => {
                                setCompteRendu(null);
                                conversion.mutate();
                            },
                        },
                    ] as ElementMenu[])
                  : []),
              {
                  genre: 'action',
                  cle: 'activation',
                  libelle: tiers.is_active ? t('Désactiver') : t('Réactiver'),
                  desactive: ecritureEnCours,
                  onChoisir: () => {
                      setCompteRendu(null);
                      activation.mutate(!tiers.is_active);
                  },
              },
              { genre: 'separateur', cle: 'danger' },
              { genre: 'action', cle: 'supprimer', libelle: t('Supprimer'), danger: true, desactive: ecritureEnCours, onChoisir: supprimer },
          ]
        : [];

    // Libellés traduits ICI, en littéraux : passés plus bas en t(o.label), ils
    // échappaient au relevé des traductions manquantes — « Articles » restait
    // ainsi en français dans la fiche en arabe.
    const ONGLETS: { cle: Onglet; label: string; compte?: number }[] = [
        { cle: 'apercu', label: t("Vue d'ensemble") },
        { cle: 'commentaires', label: t('Commentaires'), compte: synthese?.commentaires },
        ...(transactionsVisibles
            ? [
                  {
                      cle: 'transactions' as Onglet,
                      label: t('Transactions'),
                      compte:
                          synthese === undefined
                              ? undefined
                              : (ventesListees ? (totalVentes ?? 0) : 0) + (achatsVisibles ? synthese.achats : 0),
                  },
              ]
            : []),
        { cle: 'articles', label: t('Articles') },
        ...(releveVisible || attenteReleve ? [{ cle: 'releve' as Onglet, label: t('Relevé') }] : []),
        { cle: 'contacts', label: t('Contacts'), compte: synthese?.contacts },
        ...(historiqueVisible ? [{ cle: 'historique' as Onglet, label: t('Historique') }] : []),
    ];

    const LIBELLES_TYPES: Record<TypePiece, string> = {
        // « Toutes » à côté de « Achats » laisserait croire qu'il les inclut.
        toutes: achatsVisibles ? t('Toutes les ventes') : t('Toutes'),
        devis: t('Devis'),
        commande: t('Commandes'),
        bon_livraison: t('Bons de livraison'),
        facture: t('Factures'),
        avoir: t('Avoirs'),
        achats: t('Achats'),
    };
    const COMPTES_TYPES: Record<TypePiece, number | undefined> = {
        toutes: totalVentes,
        devis: synthese?.ventes.devis,
        commande: synthese?.ventes.commandes,
        bon_livraison: synthese?.ventes.bons_livraison,
        facture: synthese?.ventes.factures,
        avoir: synthese?.ventes.avoirs,
        achats: synthese?.achats,
    };
    // La colonne « Type » des listes mêlées : au singulier, une ligne = une pièce.
    const TYPE_VENTE: Record<DocumentType, string> = {
        devis: t('Devis'),
        commande: t('Commande'),
        bon_livraison: t('Bon de livraison'),
        facture: t('Facture'),
        avoir: t('Avoir'),
    };
    const TYPE_ACHAT: Record<DocumentAchat['type'], string> = {
        commande: t('Commande'),
        reception: t('Réception'),
        facture: t('Facture'),
    };

    // Les cases chiffrées : « … » tant que la synthèse est attendue, « — »
    // quand elle a échoué — « … » à vie ne disait pas qu'il fallait réessayer.
    const kpi = (valeur: (s: Synthese) => string) => (synthese ? valeur(synthese) : syntheseIndisponible ? '—' : '…');

    // Le bandeau des chiffres clients : un CLIENT, et un rôle qui voit les
    // ventes — le caissier voit ce que doit le client (compte et crédit, plus
    // bas, comme à la caisse), pas ce qu'il rapporte. Client au sens du
    // serveur dès sa réponse ; avant, sa règle supposée — coché client ou
    // prospect. Un tiers sans case, supposé client par l'écran et démenti par
    // le serveur, gardait un « Créances impayées : — » à vie.
    const clientSuppose = tiers.is_client || tiers.is_prospect;
    const estClient = vueEnsemble.data ? vueEnsemble.data.data.client : clientSuppose;
    const bandeClient = estClient && can('ventes');
    const compte = vueEnsemble.data?.data.compte;

    const chiffresIndisponibles = syntheseIndisponible && (
        <p role="alert" className="mt-3 text-sm text-amber-800">
            {t('Chiffres indisponibles.')}
            <button type="button" onClick={onRechargerSynthese} className="ms-2 font-medium text-amber-900 underline">
                {t('Réessayer')}
            </button>
        </p>
    );

    const BOUTON = 'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50';

    return (
        // `@container` : la fiche se dispose selon SA largeur, pas celle de
        // l'écran — à 1280 px elle n'a que 640 px à côté de la liste, quand un
        // téléphone de 390 px l'a pour lui seul en pleine page.
        //
        // `inert` en transition : la fiche estompée est celle du tiers QUITTÉ.
        // Ni clic ni focus ne doivent l'atteindre — « Nouvelle transaction »
        // ou « Supprimer » y viseraient l'ancien tiers, la liste désignant déjà
        // le nouveau.
        <div
            className={`@container space-y-4 transition-opacity ${enTransition ? 'opacity-60' : ''}`}
            aria-busy={enTransition}
            inert={enTransition}
        >
            {/* ---------------------------- en-tête ----------------------------- */}
            <div className="rounded-xl bg-white p-4 shadow-sm @md:p-5">
                <div className="flex items-start gap-2">
                    <div className="flex min-w-0 flex-1 flex-wrap items-start justify-between gap-x-4 gap-y-3">
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
                                {/* Un prospect est aussi `is_client` côté serveur :
                                    « Client » et « Prospect » côte à côte se
                                    contrediraient. */}
                                {tiers.is_client && !tiers.is_prospect && (
                                    <Etiquette couleur="emerald">{t('Client')}</Etiquette>
                                )}
                                {tiers.is_prospect && <Etiquette couleur="violet">{t('Prospect')}</Etiquette>}
                                {tiers.is_supplier && <Etiquette couleur="sky">{t('Fournisseur')}</Etiquette>}
                                {!tiers.is_active && <Etiquette couleur="slate">{t('Inactif')}</Etiquette>}
                            </div>
                        </div>

                        {/* Sur téléphone, « Nouvelle transaction » se réduit à
                            « + » : le nom accessible, lui, reste entier. */}
                        <div className="flex flex-wrap items-center gap-2">
                            {can('tiers', 'write') && (
                                // La chaîne de requête ENTIÈRE suit jusqu'au formulaire,
                                // qui la rend au retour : on retrouve ses filtres ET
                                // l'onglet d'où l'on est parti.
                                <Link to={{ pathname: `/tiers/${id}/modifier`, search }} className={BOUTON}>
                                    {t('Modifier')}
                                </Link>
                            )}
                            <MenuDeroulant
                                etiquette={t('Nouvelle transaction')}
                                libelle={
                                    <>
                                        <span aria-hidden className="text-base leading-none @sm:hidden">
                                            +
                                        </span>
                                        <span className="hidden @sm:inline">{t('Nouvelle transaction')}</span>
                                    </>
                                }
                                elements={nouvelles}
                                classeBouton="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                            />
                            <MenuDeroulant
                                etiquette={t("Plus d'actions")}
                                libelle={t('Plus')}
                                elements={plus}
                                classeBouton={BOUTON}
                            />
                        </div>
                    </div>

                    {/* Fermer, c'est revenir à la liste TELLE QU'ON L'A LAISSÉE
                        (recherche, filtres, page) ; le focus y retrouve la ligne
                        de ce tiers. */}
                    <Link
                        to={retour}
                        state={{ depuis: tiers.id }}
                        aria-label={t('Fermer la fiche')}
                        title={t('Fermer la fiche')}
                        className="-me-1 -mt-1 inline-flex size-10 shrink-0 items-center justify-center rounded-md text-2xl leading-none text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
                    >
                        <span aria-hidden>×</span>
                    </Link>
                </div>

                {/* La région polie existe AVANT le message : ajoutée avec lui,
                    elle ne serait pas lue. Les échecs, eux, s'imposent. */}
                <div role="status" aria-live="polite">
                    {compteRendu?.ok && (
                        <p className="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{compteRendu.texte}</p>
                    )}
                </div>
                {compteRendu && !compteRendu.ok && (
                    <p role="alert" className="mt-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {compteRendu.texte}
                    </p>
                )}
            </div>

            {/* ---------------------------- onglets ----------------------------- */}
            <div className="flex max-w-full gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1">
                {ONGLETS.map((o) => (
                    <button
                        key={o.cle}
                        type="button"
                        onClick={() => majParams({ onglet: o.cle === 'apercu' ? null : o.cle })}
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
            {onglet === 'apercu' && (
                <div className="space-y-4">
                    {/* Les chiffres CLIENTS — réservés à un client ET à qui
                        voit les ventes : sans ce droit, la synthèse omet le
                        chiffre d'affaires, et le compte client reste lisible
                        plus bas. Les cases sont posées même sans données : leur
                        arrivée ne décale rien, et « … » ne prétend aucun montant.

                        DEUX DÉFINITIONS, DEUX LIBELLÉS. Le facturé vient des
                        pièces, TTC, avoirs non déduits ; les revenus du
                        graphique sont hors taxes, avoirs déduits. Écrire
                        « chiffre d'affaires » sur les deux, c'était afficher
                        deux montants contradictoires sous le même nom. L'impayé,
                        lui, est le compte client du GRAND LIVRE, celui de la
                        liste et du tableau « Compte client » : calculé sur les
                        pièces, il divergeait au premier effet ou au premier avoir. */}
                    {bandeClient && (
                        <section aria-label={t('Synthèse')} className="rounded-xl bg-white p-5 shadow-sm">
                            <div className="grid gap-3 @md:grid-cols-2 @3xl:grid-cols-4">
                                <Kpi
                                    libelle={t('Facturé TTC')}
                                    valeur={kpi((s) => montant(s.ca_ttc))}
                                    detail={t('avoirs non déduits')}
                                />
                                {/* Les douze mois ENTIERS du graphique « 12 derniers
                                    mois », pas un an glissant au jour près. */}
                                <Kpi
                                    libelle={t('Facturé TTC sur 12 mois')}
                                    valeur={kpi((s) => montant(s.ca_12_mois))}
                                    detail={t('avoirs non déduits')}
                                />
                                <Kpi
                                    libelle={t('Créances impayées')}
                                    valeur={
                                        vueEnsemble.data
                                            ? compte
                                                ? montant(compte.creances)
                                                : '—'
                                            : vueIndisponible
                                              ? '—'
                                              : '…'
                                    }
                                    alerte={Number(compte?.creances ?? 0) > 0}
                                    // Le montant EN TÊTE : coupé en fin de case, c'est
                                    // lui qu'on perdait. Et pas « crédit » : la carte
                                    // Conditions parle de LIMITE de crédit.
                                    detail={
                                        Number(compte?.credits ?? 0) > 0
                                            ? t('{montant} en faveur du client', { montant: montant(compte?.credits) })
                                            : undefined
                                    }
                                />
                                <Kpi
                                    libelle={t('Relation depuis')}
                                    valeur={kpi((s) => s.premier_document ?? '—')}
                                    detail={
                                        synthese?.dernier_document
                                            ? `${t('dernière pièce')} ${synthese.dernier_document}`
                                            : undefined
                                    }
                                />
                            </div>
                            {chiffresIndisponibles}
                        </section>
                    )}

                    {/* Le résumé des achats vient de la MÊME synthèse — aucune
                        requête de plus — et seulement pour qui a accès aux achats. */}
                    {achatsVisibles && (
                        <section aria-label={t('Achats')} className="rounded-xl bg-white p-5 shadow-sm">
                            <div className="grid gap-3 @md:grid-cols-2">
                                <Kpi libelle={t('Achats facturés')} valeur={kpi((s) => montant(s.achats_ttc))} />
                                <Kpi libelle={t("Pièces d'achat")} valeur={kpi((s) => String(s.achats))} />
                            </div>
                            {!bandeClient && chiffresIndisponibles}
                        </section>
                    )}

                    <VueEnsembleTiers
                        tiers={tiers}
                        reponse={vueEnsemble.data}
                        indisponible={vueIndisponible}
                        onReessayer={() => vueEnsemble.refetch()}
                        periode={periode}
                        periodeEnCours={vueEnsemble.isPlaceholderData}
                        onPeriode={(p) => majParams({ periode: p === PERIODE_DEFAUT ? null : p })}
                        onVoirContacts={() => majParams({ onglet: 'contacts' })}
                        lienModifier={{ pathname: `/tiers/${id}/modifier`, search }}
                        clientSuppose={clientSuppose}
                        onSaisirOuverture={peutSaisirOuverture ? () => setSaisieOuverture('client') : undefined}
                    />
                </div>
            )}

            {/* Ni onglet ni compte tranchés avant la synthèse : rien ne se charge. */}
            {onglet === 'releve' && attenteReleve && (
                <p role="status" className="rounded-xl bg-white p-5 py-8 text-center text-sm text-slate-400 shadow-sm">
                    {t('Chargement…')}
                </p>
            )}

            {onglet === 'releve' && releveVisible && !attenteReleve && (
                <ReleveTiers
                    tiers={tiers}
                    comptes={comptesReleve}
                    compte={compteReleve}
                    du={duReleve}
                    au={auReleve}
                    onPeriode={majPeriode}
                    onCompte={(c) => majParams({ compte: c === comptesReleve[0] ? null : c })}
                    onApercu={can('ventes') ? (documentId) => setApercu(documentId) : undefined}
                    onSaisirOuverture={peutSaisirOuverture ? () => setSaisieOuverture(compteReleve) : undefined}
                />
            )}

            {onglet === 'commentaires' && <CommentairesTiers tiersId={tiers.id} />}

            {onglet === 'historique' && historiqueVisible && <TiersTimeline tiersId={String(tiers.id)} />}

            {onglet === 'contacts' && <ContactsTiers tiersId={tiers.id} />}

            {onglet === 'articles' && (
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

            {onglet === 'transactions' && (
                <div className="space-y-3">
                    {attenteVentes && (
                        <p role="status" className="rounded-xl bg-white px-4 py-8 text-center text-sm text-slate-400 shadow-sm">
                            {t('Chargement…')}
                        </p>
                    )}

                    {/* Un seul type proposé (fournisseur pur sans ventes) : un
                        filtre à une seule case ne filtre rien. */}
                    {!attenteVentes && typesOuverts.length > 1 && (
                        <div role="group" aria-label={t('Type de pièce')} className="flex max-w-full gap-1.5 overflow-x-auto pb-1">
                            {typesOuverts.map((type) => (
                                <button
                                    key={type}
                                    type="button"
                                    onClick={() => majParams({ type_piece: type })}
                                    aria-pressed={typePiece === type}
                                    className={`shrink-0 whitespace-nowrap rounded-full border px-3 py-1 text-sm transition ${
                                        typePiece === type
                                            ? 'border-emerald-600 bg-emerald-50 font-medium text-emerald-800'
                                            : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    {LIBELLES_TYPES[type]}
                                    {COMPTES_TYPES[type] !== undefined && (
                                        <span className="ms-1.5 tabular-nums text-slate-400">{COMPTES_TYPES[type]}</span>
                                    )}
                                </button>
                            ))}
                        </div>
                    )}

                    {achatsOuverts && (
                        <div
                            className={`overflow-x-auto rounded-xl bg-white shadow-sm transition-opacity ${
                                achats.isPlaceholderData ? 'opacity-60' : ''
                            }`}
                        >
                            <Tableau
                                vide={achats.isLoading ? t('Chargement…') : t('Aucun achat auprès de ce fournisseur.')}
                                montrerVide={!achats.data || achats.data.data.length === 0}
                                requete={achats}
                                entetes={[t('Code'), t('Type'), t('Date'), t('Total TTC'), t('Statut')]}
                                colonnesDebut={3}
                                sansCadre
                            >
                                {achats.data?.data.map((doc) => (
                                    <tr key={doc.id} className="hover:bg-slate-50">
                                        <td className="whitespace-nowrap px-4 py-3">
                                            <Link to={`/achats/${doc.id}`} className="font-mono text-xs text-emerald-700 hover:underline">
                                                {doc.code}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">{TYPE_ACHAT[doc.type]}</td>
                                        <td className="whitespace-nowrap px-4 py-3 text-slate-600">{doc.date_document}</td>
                                        <td className="px-4 py-3 text-end tabular-nums text-slate-900">{formatMAD(doc.total_ttc)}</td>
                                        <td className="px-4 py-3 text-end">
                                            <span className={`rounded px-1.5 py-0.5 text-xs ${achatStatutClasses(doc.statut)}`}>
                                                {achatStatutLabel(doc)}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Tableau>
                            {achats.data && <Pagination meta={achats.data.meta} onPage={setPage} />}
                        </div>
                    )}

                    {documentsOuverts && (
                        <div
                            className={`overflow-x-auto rounded-xl bg-white shadow-sm transition-opacity ${
                                documents.isPlaceholderData ? 'opacity-60' : ''
                            }`}
                        >
                            <Tableau
                                vide={
                                    documents.isLoading
                                        ? t('Chargement…')
                                        : typePiece === 'toutes'
                                          ? t('Aucune pièce de vente pour ce tiers.')
                                          : t('Aucune pièce de ce type pour ce tiers.')
                                }
                                montrerVide={!documents.data || documents.data.data.length === 0}
                                requete={documents}
                                entetes={
                                    typePiece === 'toutes'
                                        ? [t('Code'), t('Type'), t('Date'), t('Total TTC'), t('Statut')]
                                        : [t('Code'), t('Date'), t('Total TTC'), t('Statut')]
                                }
                                colonnesDebut={typePiece === 'toutes' ? 3 : 2}
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
                                        <td className="whitespace-nowrap px-4 py-3">
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
                                        {typePiece === 'toutes' && (
                                            <td className="whitespace-nowrap px-4 py-3 text-slate-600">{TYPE_VENTE[doc.type]}</td>
                                        )}
                                        <td className="whitespace-nowrap px-4 py-3 text-slate-600">{doc.date_document}</td>
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
                </div>
            )}

            {apercu !== null && (
                <ApercuVente id={apercu} onFermer={() => setApercu(null)} surcouche lienClient={false} />
            )}

            {saisieOuverture !== null && (
                <SoldeOuverture
                    tiers={tiers}
                    comptes={[saisieOuverture, ...comptesOuverture.filter((c) => c !== saisieOuverture)].filter((c) =>
                        comptesOuverture.includes(c),
                    )}
                    onFermer={() => setSaisieOuverture(null)}
                    onSaisi={(_saisi, avertissement) => {
                        focusApresOuverture.current = true;
                        setSaisieOuverture(null);
                        // Enregistré quoi qu'il arrive ; l'avertissement du
                        // serveur (série OD à renuméroter) suit dans le même
                        // compte rendu, pour qu'il soit lu avec lui.
                        setCompteRendu({
                            ok: true,
                            texte: avertissement
                                ? `${t("Solde d'ouverture enregistré.")} ${avertissement}`
                                : t("Solde d'ouverture enregistré."),
                        });
                    }}
                />
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
    // Le détail PASSE À LA LIGNE au lieu d'être coupé : dans une case de
    // 150 px (quatre colonnes à 1440 px), l'ellipse mangeait le montant, seule
    // information utile de la ligne.
    return (
        <div className="min-w-0 rounded-lg bg-slate-50 px-4 py-3">
            <div className="text-xs uppercase tracking-wide text-slate-500">{libelle}</div>
            <div
                title={valeur}
                className={`mt-0.5 truncate text-lg font-semibold ${alerte ? 'text-amber-700' : 'text-slate-900'}`}
            >
                {valeur}
            </div>
            {detail && <div className="break-words text-xs text-slate-400">{detail}</div>}
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
    colonnesDebut = 2,
}: {
    entetes: string[];
    children: React.ReactNode;
    vide: string;
    montrerVide: boolean;
    requete: EtatRequete;
    sansCadre?: boolean;
    /** Colonnes de texte, calées au début ; les suivantes (montants, statut) le sont à la fin. */
    colonnesDebut?: number;
}) {
    const t = useT();

    // Un échec n'est PAS une liste vide : « Aucune pièce » sous un compteur
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
                            <th key={e} className={`px-4 py-3 ${i >= colonnesDebut ? 'text-end' : 'text-start'}`}>
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
