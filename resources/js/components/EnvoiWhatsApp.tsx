import { Fragment, useEffect, useId, useRef, useState, type ReactNode, type RefObject } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient, type UseMutationResult } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useFeatures } from '@/lib/features';
import { LANGUES, useLangue } from '@/lib/langue';
import {
    affichageNumero,
    lienClickToChat,
    longueurMessage,
    MESSAGE_LONGUEUR_MAX,
    normaliserNumero,
    numeroPourServeur,
    type RaisonNumero,
} from '@/lib/whatsapp';
import { useTraduction, type Traduction } from '@/components/ComboboxRecherche';
import Surcouche from '@/components/Surcouche';
import type { EnvoiWhatsApp, LangueMessage, PreparationWhatsApp, TypeEnvoiWhatsApp } from '@/types';

/*
 * Envoi par WhatsApp « click-to-chat » : le serveur prépare (numéros, textes
 * FR et AR, lien signé du PDF), l'utilisateur choisit et retouche, puis
 * WhatsApp s'ouvre avec le message prêt — c'est LUI qui appuie sur Envoyer,
 * depuis son numéro. L'application ne voit pas l'envoi : le journal dit
 * « WhatsApp ouvert », jamais « envoyé ».
 *
 * TROIS RÈGLES QUI TIENNENT LE TOUT.
 *
 *  1. WhatsApp s'ouvre DANS le gestionnaire du clic, avant tout aller-retour
 *     réseau : un window.open placé après un `await` est bloqué comme fenêtre
 *     surgissante. Le lien se construit donc ici (lib/whatsapp), et le journal
 *     part ensuite, sans rien retenir.
 *  2. Un journal en échec ne coûte pas l'envoi : WhatsApp est déjà ouvert.
 *     L'utilisateur est prévenu et peut réessayer l'enregistrement seul.
 *  3. Un BROUILLON de message ne se perd pas sur un geste : Échap, le voile ou
 *     ✕ demandent confirmation dès que le texte a été retouché.
 *
 * `traduire` : comme les sélecteurs partagés, le panneau reste en français sur
 * un écran resté en français par décision (pièce de vente, relances) — y
 * compris les libellés que renvoie le serveur, demandés en français. Les
 * TEXTES envoyés au client, eux, existent toujours dans les deux langues.
 */

/** Ce que l'on envoie, et à propos de quoi. */
export interface CibleWhatsApp {
    type: TypeEnvoiWhatsApp;
    documentId: number;
    /** Code de la pièce, pour titrer le panneau avant la réponse du serveur. */
    code?: string;
    /** Confirmation de paiement : le paiement à confirmer. */
    paiementId?: number;
    /** Relance : le niveau proposé d'entrée ; à défaut, celui que suggère le serveur. */
    niveau?: number;
}

/** Le droit qui couvre chaque message — le même que la route qui le journalise. */
export function domaineWhatsApp(type: TypeEnvoiWhatsApp): 'ventes' | 'relances' {
    return type === 'relance' ? 'relances' : 'ventes';
}

function urlPreparation(cible: CibleWhatsApp): string {
    return cible.type === 'relance'
        ? `/relances/${cible.documentId}/whatsapp`
        : `/ventes/documents/${cible.documentId}/whatsapp`;
}

/* --- Mots ------------------------------------------------------------------
 * Chaque clé est écrite en toutes lettres dans son appel à t() : c'est ce que
 * relit le garde-fou scripts/i18n-manquantes.mjs, qui ne verrait pas une clé
 * passée en variable.
 */

function libelleBouton(t: Traduction, type: TypeEnvoiWhatsApp): string {
    if (type === 'relance') return t('Relancer par WhatsApp');
    if (type === 'paiement') return t('Confirmer par WhatsApp');

    return t('Envoyer par WhatsApp');
}

function titrePanneau(t: Traduction, type: TypeEnvoiWhatsApp): string {
    if (type === 'devis') return t('Envoyer le devis {code}');
    if (type === 'paiement') return t('Confirmer un paiement de la facture {code}');
    if (type === 'relance') return t('Relancer la facture {code}');

    return t('Envoyer la facture {code}');
}

/** Les niveaux de Relance::NIVEAUX, que le serveur renvoie en français. */
function libelleNiveau(t: Traduction, niveau: number): string {
    if (niveau === 1) return t('Rappel');
    if (niveau === 2) return t('Relance ferme');
    if (niveau === 3) return t('Mise en demeure');

    return String(niveau);
}

function libelleRaison(t: Traduction, raison: RaisonNumero): string {
    switch (raison) {
        case 'vide':
            return t('Aucun numéro saisi.');
        case 'texte':
            // « corrigez la fiche », la phrase du serveur, ne vaut pas pour une saisie libre.
            return t("Saisissez seulement des chiffres, et + devant l'indicatif d'un numéro étranger.");
        case 'longueur_maroc':
            return t('Un numéro marocain compte 10 chiffres (06 12 34 56 78).');
        case 'prefixe_maroc':
            return t('Un numéro marocain commence par 05, 06, 07 ou 08.');
        case 'indicatif':
            return t('Indicatif pays manquant : saisissez un numéro étranger au format international (+33…).');
        case 'longueur_internationale':
            return t('Numéro international invalide : de 8 à 15 chiffres, indicatif compris.');
    }
}

/** La nature du message — sans jamais dire « envoyé » : l'application ne le sait pas. */
function libelleEnvoi(t: Traduction, envoi: EnvoiWhatsApp): ReactNode {
    if (envoi.type === 'devis') return t('Message : devis');
    if (envoi.type === 'paiement') return t('Message : confirmation de paiement');
    if (envoi.type === 'relance') {
        return remplir(t('Message : relance — {niveau}'), { niveau: envoi.niveau ? libelleNiveau(t, envoi.niveau) : '—' });
    }

    return t('Message : facture');
}

/**
 * Une phrase traduite dont les trous reçoivent des ÉLÉMENTS (un numéro dans
 * <bdi>) : la place de chaque trou suit la langue, ce qu'une concaténation de
 * morceaux figerait dans l'ordre français.
 */
function remplir(modele: string, valeurs: Record<string, ReactNode>): ReactNode {
    return modele.split(/\{(\w+)\}/).map((morceau, i) =>
        i % 2 === 1 ? <Fragment key={i}>{valeurs[morceau] ?? `{${morceau}}`}</Fragment> : morceau,
    );
}

/** « 17/11/2026 » lu dans la chaîne ISO : passer par Date décalerait 23:59 UTC au lendemain, à l'heure du Maroc. */
function dateCourte(iso: string): string {
    return `${iso.slice(8, 10)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}`;
}

function messageErreur(t: Traduction, erreur: unknown): string {
    if (isAxiosError(erreur)) {
        const corps = erreur.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined;
        const statut = erreur.response?.status;

        if (corps?.errors) return Object.values(corps.errors).flat().join(' ');
        if (statut === undefined) return t('Vérifiez la connexion, puis réessayez.');
        if (statut === 429) return t('Trop de demandes : réessayez dans un instant.');
        // Le 404 de Laravel nomme la classe du modèle, en anglais ; le 500 dit
        // « Server Error ». Seul le refus (403) porte une phrase à montrer :
        // droit manquant, ou module Relances désactivé.
        if (statut === 404) return t('Pièce introuvable : elle a peut-être été supprimée.');
        if (statut === 403 && corps?.message) return corps.message;
    }

    return t("Le serveur n'a pas pu traiter la demande.");
}

/* --- La langue du message, retenue PAR CLIENT -------------------------------
 * C'est la langue du destinataire qui compte, pas celle de l'écran : un
 * commercial francophone écrit en arabe à tel client, et inversement. On
 * retient donc, sur ce poste, le dernier choix fait pour chaque client. Une
 * commodité : sans stockage (navigation privée), on retombe sur la langue de
 * l'écran.
 */
const CLE_LANGUES = 'whatsapp-langue-par-client';

function languesRetenues(): Record<string, LangueMessage> {
    try {
        const brut = localStorage.getItem(CLE_LANGUES);
        const lu: unknown = brut ? JSON.parse(brut) : {};

        return lu !== null && typeof lu === 'object' ? (lu as Record<string, LangueMessage>) : {};
    } catch {
        return {};
    }
}

function retenirLangue(tiersId: number | undefined, langue: LangueMessage): void {
    if (tiersId === undefined) return;
    try {
        localStorage.setItem(CLE_LANGUES, JSON.stringify({ ...languesRetenues(), [tiersId]: langue }));
    } catch {
        // Stockage refusé : la langue sera simplement redemandée la prochaine fois.
    }
}

/* --- La clé du geste --------------------------------------------------------
 * Tirée au clic et envoyée avec le journal : « Réessayer l'enregistrement »
 * renvoie la MÊME, et le serveur rend la ligne déjà écrite au lieu d'en ajouter
 * une. Sur téléphone, WhatsApp passe au premier plan pendant que le journal
 * part ; la réponse se perd alors qu'il a été écrit — sans cette clé, réessayer
 * compterait deux relances pour un seul message.
 *
 * crypto.randomUUID n'existe que sur une page sûre (https, localhost) : l'ERP
 * ouvert par l'adresse du réseau local retombe sur getRandomValues.
 */
function cleDuGeste(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();

    const octets = crypto.getRandomValues(new Uint8Array(16));
    octets[6] = (octets[6] & 0x0f) | 0x40; // version 4
    octets[8] = (octets[8] & 0x3f) | 0x80; // variante RFC 4122
    const h = [...octets].map((o) => o.toString(16).padStart(2, '0')).join('');

    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/* --- Les messages retouchés, que l'écran parent peut faire disparaître -------
 * Un panneau intégré (aperçu de la liste, fiche client) peut s'en aller sans
 * passer par son bouton « Retour » : un clic sur une autre ligne change de
 * pièce, Échap ferme la surcouche englobante. Ces gestes appartiennent à
 * l'écran parent ; pour qu'il demande confirmation, chaque panneau dont le
 * texte a été retouché s'inscrit ici, avec SA question, dans sa langue.
 */
const brouillons = new Set<() => boolean>();

/** Vrai si l'on peut continuer : aucun message retouché, ou l'utilisateur accepte de l'abandonner. */
export function abandonnerBrouillonsWhatsApp(): boolean {
    for (const demander of brouillons) {
        if (!demander()) return false;
    }

    return true;
}

/**
 * Ramène le haut du panneau en vue : le conteneur qui défile (feuille du bas,
 * surcouche, colonne de l'aperçu) gardait la position du bouton qui l'a ouvert.
 */
function remonterEnVue(element: HTMLElement): void {
    for (let parent = element.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
        const { overflowY } = getComputedStyle(parent);
        if (overflowY === 'auto' || overflowY === 'scroll') {
            parent.scrollTop = 0;
            break;
        }
    }

    if (element.getBoundingClientRect().top < 0) element.scrollIntoView({ block: 'start' });
}

/* --- Pièces visuelles ------------------------------------------------------- */

/** Bulle et combiné : le signe que tout le monde reconnaît. Décoratif — le libellé dit l'action. */
export function IconeWhatsApp({ className = 'h-4 w-4' }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" className={className}>
            <path d="M12 2.2a9.8 9.8 0 0 0-8.43 14.8L2.2 21.8l4.93-1.3A9.8 9.8 0 1 0 12 2.2Zm0 1.75a8.05 8.05 0 1 1-4.1 14.98l-.3-.18-2.9.76.78-2.83-.2-.3A8.05 8.05 0 0 1 12 3.95Z" />
            <path d="M8.93 7.25c-.18-.4-.37-.4-.54-.41h-.46a.9.9 0 0 0-.65.3 2.7 2.7 0 0 0-.85 2.02c0 1.19.87 2.34 1 2.5.12.16 1.68 2.68 4.15 3.66 2.05.81 2.47.65 2.92.6.45-.03 1.45-.59 1.65-1.15.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.47-.29l-1.64-.81c-.22-.08-.38-.12-.54.12-.16.25-.63.81-.77.97-.14.16-.29.18-.53.06a6.7 6.7 0 0 1-1.97-1.22 7.4 7.4 0 0 1-1.36-1.7c-.14-.24-.02-.37.1-.5.11-.1.25-.28.37-.42.12-.15.16-.25.24-.41.08-.17.04-.31-.02-.43-.06-.12-.53-1.3-.74-1.76Z" />
        </svg>
    );
}

/**
 * Le bouton « … par WhatsApp ». Il n'apparaît qu'avec le droit d'ÉCRIRE dans
 * le domaine du message (ventes ou relances), et, pour une relance, avec le
 * module Relances actif : sans cela le journal refuserait l'envoi, et l'on
 * aurait ouvert WhatsApp pour rien.
 *
 * `onOuvrir` : l'appelant affiche lui-même le panneau (l'aperçu d'une pièce le
 * pose à la place de son contenu, plutôt qu'une surcouche sur une surcouche).
 */
export function BoutonWhatsApp({
    cible,
    traduire = true,
    compact = false,
    onOuvrir,
    autoFocus = false,
}: {
    cible: CibleWhatsApp;
    traduire?: boolean;
    compact?: boolean;
    onOuvrir?: () => void;
    /** Rendre le focus au bouton quand il réapparaît (retour d'un panneau posé à sa place). */
    autoFocus?: boolean;
}) {
    const t = useTraduction(traduire);
    const { can } = useAuth();
    const { features } = useFeatures();
    const [ouvert, setOuvert] = useState(false);

    if (!can(domaineWhatsApp(cible.type), 'write') || (cible.type === 'relance' && !features.relances)) {
        return null;
    }

    const taille = compact ? 'px-2 py-1 text-xs' : 'px-3 py-1.5 text-sm';

    return (
        <>
            <button
                type="button"
                autoFocus={autoFocus}
                onClick={() => (onOuvrir ? onOuvrir() : setOuvert(true))}
                className={`inline-flex items-center gap-1.5 rounded-md border border-emerald-300 bg-white font-medium text-emerald-800 transition hover:bg-emerald-50 ${taille}`}
            >
                <IconeWhatsApp className={`${compact ? 'h-3.5 w-3.5' : 'h-4 w-4'} shrink-0 text-[#25D366]`} />
                <span>{libelleBouton(t, cible.type)}</span>
            </button>
            {ouvert && <PanneauWhatsApp cible={cible} traduire={traduire} onFermer={() => setOuvert(false)} />}
        </>
    );
}

/** Ce qui a été ouvert, figé au moment du clic : les retouches suivantes n'y changent rien. */
interface Ouverture {
    url: string;
    corps: Record<string, unknown>;
    destinataire: string | null;
    /** Le navigateur a refusé la fenêtre : WhatsApp n'est PAS ouvert, et rien n'est encore journalisé. */
    bloquee: boolean;
}

/**
 * Le panneau : destinataire, niveau (relance), langue, texte, puis WhatsApp.
 *
 * Dans sa propre surcouche par défaut ; `integre` le pose dans un panneau
 * existant, avec un bouton « Retour » au lieu de ✕.
 */
export function PanneauWhatsApp({
    cible,
    traduire = true,
    onFermer,
    integre = false,
    titreId,
}: {
    cible: CibleWhatsApp;
    traduire?: boolean;
    onFermer: () => void;
    integre?: boolean;
    /** Identifiant à donner au titre : celui qu'attend déjà le panneau englobant. */
    titreId?: string;
}) {
    const t = useTraduction(traduire);
    const { langue: langueEcran } = useLangue();
    const queryClient = useQueryClient();
    const idAuto = useId();
    const titre = titreId ?? `${idAuto}titre`;
    const resultat = useRef<HTMLDivElement>(null);
    const racine = useRef<HTMLDivElement>(null);
    const refTitre = useRef<HTMLHeadingElement>(null);
    const niveauxAffiches = useRef(false);

    // Sur un écran resté en français, les libellés du serveur (« · mobile »,
    // raisons de refus, erreurs) sont demandés en français eux aussi.
    const langueServeur: LangueMessage = traduire ? langueEcran : 'fr';
    const entetes = traduire ? undefined : { 'Accept-Language': 'fr' };

    const [niveau, setNiveau] = useState<number | undefined>(cible.niveau);
    const [choix, setChoix] = useState<string | null>(null);
    const [saisie, setSaisie] = useState('');
    const [langueChoisie, setLangueChoisie] = useState<LangueMessage | null>(null);
    const [brouillon, setBrouillon] = useState<{ cle: string; textes: Record<LangueMessage, string> } | null>(null);
    const [note, setNote] = useState('');
    const [ouverture, setOuverture] = useState<Ouverture | null>(null);

    const preparation = useQuery({
        queryKey: ['whatsapp-preparation', cible.type, cible.documentId, cible.paiementId ?? null, niveau ?? null, langueServeur],
        queryFn: async () => {
            const params = cible.type === 'relance' ? { niveau } : { type: cible.type, paiement_id: cible.paiementId };
            const { data } = await api.get<{ data: PreparationWhatsApp }>(urlPreparation(cible), {
                params,
                headers: entetes,
            });

            return data.data;
        },
        // Le texte affiché est celui que l'utilisateur retouche : une relecture
        // au retour sur l'onglet (après WhatsApp, justement) ne doit rien
        // remplacer. Chaque ouverture du panneau, elle, repart du serveur.
        staleTime: Infinity,
        gcTime: 0,
        refetchOnWindowFocus: false,
        // Changer de niveau garde la préparation précédente à l'écran le temps
        // de la suivante, au lieu de vider tout le panneau.
        placeholderData: keepPreviousData,
        // Un refus (brouillon, facture soldée…) ne changera pas au second essai.
        retry: (echecs, err) => (isAxiosError(err) && err.response !== undefined ? false : echecs < 1),
    });

    const journal = useMutation({
        mutationFn: async (corps: Record<string, unknown>) => {
            const { data } = await api.post<{ data: EnvoiWhatsApp }>(urlPreparation(cible), corps, { headers: entetes });

            return data.data;
        },
        // Dans les options, et non dans mutate() : l'historique se met à jour
        // même si l'utilisateur a fermé le panneau avant la réponse.
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['envois-whatsapp', cible.documentId] });
            if (cible.type === 'relance') {
                queryClient.invalidateQueries({ queryKey: ['relances-a-relancer'] });
            }
        },
    });

    // La vue « ouvert » remplace le formulaire : le focus, resté sur le bouton
    // disparu, retomberait sur <body>.
    useEffect(() => {
        if (ouverture) resultat.current?.focus();
    }, [ouverture]);

    // Intégré, le panneau prend la place du contenu de l'aperçu : le bouton qui
    // l'a ouvert disparaît avec lui. Sans ceci, le focus tombait sur <body>, le
    // lecteur d'écran n'annonçait rien, et le conteneur restait défilé jusqu'à
    // ce bouton — titre, « Retour » et destinataires hors de vue. (Dans sa
    // propre surcouche, c'est Surcouche qui pose le focus.)
    useEffect(() => {
        if (!integre) return;
        refTitre.current?.focus({ preventScroll: true });
        if (racine.current) remonterEnVue(racine.current);
    }, [integre]);

    const data = preparation.data;
    const enAttente = preparation.isFetching || preparation.isPlaceholderData;
    const code = data?.document.code ?? cible.code ?? '…';
    const tiersId = data?.document.tiers?.id;

    const langue: LangueMessage =
        langueChoisie ?? (tiersId !== undefined ? languesRetenues()[tiersId] : undefined) ?? langueEcran;

    // Les retouches valent pour UNE préparation : un autre niveau de relance
    // apporte un autre texte (ton gradué), et l'ancien brouillon s'efface.
    const cle = `${data?.type ?? ''}:${data?.niveau ?? ''}`;
    const textes = brouillon?.cle === cle ? brouillon.textes : data?.messages;
    const texte = textes?.[langue] ?? '';
    const modifie =
        data !== undefined && brouillon?.cle === cle && (brouillon.textes.fr !== data.messages.fr || brouillon.textes.ar !== data.messages.ar);

    // Voir `brouillons` : l'écran parent demande avant de faire disparaître un
    // texte retouché. Une fois WhatsApp ouvert, le texte est parti, plus rien
    // n'est à protéger.
    const aProteger = modifie && ouverture === null;
    const question = t('Abandonner le message modifié ?');
    useEffect(() => {
        if (!aProteger) return;
        const demander = () => window.confirm(question);
        brouillons.add(demander);

        return () => {
            brouillons.delete(demander);
        };
    }, [aProteger, question]);

    const choixEffectif = choix ?? (data ? (data.numero_selectionne ?? 'autre') : null);
    const analyse = choixEffectif === 'autre' ? normaliserNumero(saisie) : null;
    const destinataire = data?.destinataires.find((d) => d.numero === choixEffectif) ?? null;
    const numero = choixEffectif === 'aucun' ? null : choixEffectif === 'autre' ? (analyse?.numero ?? null) : (destinataire?.numero ?? null);
    const numeroValable = choixEffectif === 'aucun' || numero !== null;
    const affichage =
        destinataire?.affichage ?? (analyse?.numero ? affichageNumero(analyse) : null);

    // Le serveur retire les espaces de bord (TrimStrings) : le lien ouvert ici
    // doit être celui qu'il journalise, octet pour octet.
    const message = texte.trim();
    const longueur = longueurMessage(message);
    const tropLong = longueur > MESSAGE_LONGUEUR_MAX;
    const pret = data !== undefined && !enAttente && numeroValable && message !== '' && !tropLong;

    const lienPdfPresent = data !== undefined && message.includes(data.lien_document.url);

    // Appelé UNE fois par clic : la clé du geste y est tirée, puis figée avec
    // le reste dans `ouverture` — c'est ce corps-là que rejoue « Réessayer ».
    const corps = (): Record<string, unknown> => {
        const commun = { numero: numeroPourServeur(numero), langue, message, cle: cleDuGeste() };

        return cible.type === 'relance'
            ? { niveau: data?.niveau, note: note.trim() || null, ...commun }
            : { type: cible.type, paiement_id: cible.paiementId ?? null, ...commun };
    };

    const ouvrirWhatsApp = () => {
        if (!pret) return;

        const url = lienClickToChat(numero, message);
        // Ici, dans le geste même de l'utilisateur — voir la règle n° 1 en tête
        // de fichier. `opener` coupé aussitôt : la page de WhatsApp n'a pas à
        // pouvoir piloter l'onglet de l'ERP. Pas de `noopener` dans les
        // options : window.open rendrait alors toujours null, et l'on ne
        // saurait plus distinguer une fenêtre bloquée d'une fenêtre ouverte.
        const fenetre = window.open(url, '_blank');
        if (fenetre) fenetre.opener = null;

        const envoi = corps();
        setOuverture({ url, corps: envoi, destinataire: affichage, bloquee: fenetre === null });
        retenirLangue(tiersId, langue);

        // Fenêtre bloquée : rien n'est ouvert, rien ne se journalise — le lien
        // de secours le fera au clic (voir plus bas).
        if (fenetre) journal.mutate(envoi);
    };

    const fermer = () => {
        if (aProteger && !window.confirm(question)) return;
        onFermer();
    };

    const retoucher = (valeur: string) => {
        if (!data) return;
        setBrouillon({ cle, textes: { ...(textes ?? data.messages), [langue]: valeur } });
    };

    // Sans préparation (niveau refusé), les trois niveaux restent proposés : le serveur tranchera.
    const niveaux = data?.niveaux ?? [1, 2, 3].map((n) => ({ niveau: n, label: '', possible: true, suggere: false }));
    const niveauCoche = niveau ?? data?.niveau ?? undefined;
    const niveauSuggere = data?.niveaux?.find((n) => n.suggere)?.niveau;

    // Le choix du niveau n'est proposé, en cas de refus, que si c'est LE NIVEAU
    // qui est refusé : face à une traite en cours, une facture soldée ou un
    // brouillon, changer de niveau ne ferait que rejouer le même refus. Et il
    // reste en place pendant le nouvel essai — sans quoi le bouton qui a le
    // focus disparaîtrait sous le doigt, et le focus avec lui.
    const refusDuNiveau =
        preparation.isError &&
        isAxiosError(preparation.error) &&
        (preparation.error.response?.data as { errors?: Record<string, unknown> } | undefined)?.errors?.niveau !== undefined;
    const afficherNiveaux =
        cible.type === 'relance' && (data !== undefined || refusDuNiveau || (niveauxAffiches.current && preparation.isFetching));
    useEffect(() => {
        niveauxAffiches.current = afficherNiveaux;
    });

    // Intégré, le panneau vit souvent dans une colonne étroite (aperçu à côté
    // de la liste) : « Retour » prend sa propre ligne au lieu d'écraser le titre.
    const enTete = (
        <>
            {integre && (
                <button
                    type="button"
                    onClick={fermer}
                    className="-ms-2 mb-2 rounded-md px-2 py-1 text-sm text-emerald-700 transition hover:bg-emerald-50"
                >
                    {t('← Retour à la pièce')}
                </button>
            )}
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-emerald-700">
                        <IconeWhatsApp className="h-4 w-4 text-[#25D366]" />
                        WhatsApp
                    </div>
                    <h2 id={titre} ref={refTitre} tabIndex={-1} className="mt-0.5 font-semibold text-slate-900 focus:outline-none">
                        {remplir(titrePanneau(t, cible.type), { code: <bdi className="font-mono">{code}</bdi> })}
                    </h2>
                    {data?.document.tiers && <p className="truncate text-sm text-slate-500">{data.document.tiers.nom}</p>}
                </div>
                {!integre && (
                    <button
                        type="button"
                        onClick={fermer}
                        className="shrink-0 rounded-md px-2 py-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                        aria-label={t('Fermer')}
                    >
                        ✕
                    </button>
                )}
            </div>
        </>
    );

    const contenu = ouverture ? (
        <VueOuverte
            t={t}
            refFocus={resultat}
            ouverture={ouverture}
            journal={journal}
            relance={cible.type === 'relance'}
            onFermer={onFermer}
            libelleFermer={integre ? t('Retour') : t('Fermer')}
        />
    ) : (
        <div className="mt-4 space-y-4">
            {preparation.isPending && (
                <p role="status" className="py-6 text-center text-sm text-slate-400">
                    {t('Préparation du message…')}
                </p>
            )}

            {preparation.isError && (
                <div role="alert" className="rounded-lg bg-amber-50 px-3 py-3 text-sm text-amber-800">
                    {messageErreur(t, preparation.error)}
                    {!(isAxiosError(preparation.error) && preparation.error.response) && (
                        <button
                            type="button"
                            onClick={() => preparation.refetch()}
                            className="ms-2 font-medium text-amber-900 underline"
                        >
                            {t('Réessayer')}
                        </button>
                    )}
                </div>
            )}

            {/* Le niveau reste choisissable quand le serveur refuse celui
                demandé : c'est justement en changeant de niveau qu'on s'en sort. */}
            {afficherNiveaux && (
                <fieldset className="min-w-0">
                    <legend className="mb-1.5 text-sm font-medium text-slate-700">{t('Niveau de relance')}</legend>
                    <div className="grid grid-cols-3 gap-1.5">
                        {niveaux.map((n) => (
                            <label
                                key={n.niveau}
                                className={`relative flex cursor-pointer items-center justify-center rounded-md border px-2 py-1.5 text-center text-xs font-medium transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-emerald-500 ${
                                    niveauCoche === n.niveau
                                        ? 'border-emerald-600 bg-emerald-50 text-emerald-800'
                                        : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                                } ${n.possible ? '' : 'cursor-not-allowed opacity-50'}`}
                            >
                                <input
                                    type="radio"
                                    name={`${idAuto}niveau`}
                                    value={n.niveau}
                                    checked={niveauCoche === n.niveau}
                                    disabled={!n.possible}
                                    onChange={() => setNiveau(n.niveau)}
                                    className="sr-only"
                                />
                                {libelleNiveau(t, n.niveau)}
                                {n.suggere && <span className="sr-only"> — {t('suggéré')}</span>}
                            </label>
                        ))}
                    </div>
                    {data?.document.echue === false && (
                        <p className="mt-1 text-xs text-slate-500">
                            {t("Facture pas encore échue : seul le rappel courtois est possible.")}
                        </p>
                    )}
                    {data?.document.echue && data.document.jours_retard !== undefined && (
                        <p className="mt-1 text-xs text-slate-500">
                            {t('Échue depuis {jours} jours.', { jours: data.document.jours_retard })}
                            {niveauSuggere !== undefined &&
                                ` ${t('Niveau suggéré : {niveau}.', { niveau: libelleNiveau(t, niveauSuggere) })}`}
                        </p>
                    )}
                </fieldset>
            )}

            {data && (
                <>
                    <fieldset className="min-w-0">
                        <legend className="mb-1.5 text-sm font-medium text-slate-700">{t('Destinataire')}</legend>

                        {data.destinataires.length === 0 && (
                            <p className="mb-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                {t('Aucun numéro exploitable sur la fiche de ce client : saisissez-le ci-dessous, ou choisissez le contact dans WhatsApp.')}
                            </p>
                        )}

                        <div className="space-y-1.5">
                            {data.destinataires.map((d) => (
                                <Option
                                    key={d.numero}
                                    nom={`${idAuto}numero`}
                                    valeur={d.numero}
                                    coche={choixEffectif === d.numero}
                                    onChoisir={setChoix}
                                >
                                    <span className="block truncate text-slate-900">{d.libelle}</span>
                                    {/* Le bloc garde la direction de la carte (aligné sous
                                        le libellé, en arabe à droite) ; seul le numéro
                                        s'écrit de gauche à droite. Un <bdi dir="ltr"> en
                                        bloc partait, lui, au bord opposé. */}
                                    <span className="block font-mono text-xs text-slate-600">
                                        <bdi dir="ltr">{d.affichage}</bdi>
                                    </span>
                                    {d.avertissement && (
                                        <span className="mt-0.5 block text-xs text-amber-700">{d.avertissement}</span>
                                    )}
                                </Option>
                            ))}

                            <Option nom={`${idAuto}numero`} valeur="autre" coche={choixEffectif === 'autre'} onChoisir={setChoix}>
                                <span className="block text-slate-900">{t('Autre numéro')}</span>
                            </Option>
                            {choixEffectif === 'autre' && (
                                <div className="ps-7">
                                    <input
                                        type="tel"
                                        inputMode="tel"
                                        autoComplete="off"
                                        dir="ltr"
                                        // Quand on vient de cocher « Autre numéro », ou quand la
                                        // fiche n'a aucun numéro : saisir est alors la seule
                                        // chose à faire.
                                        autoFocus
                                        value={saisie}
                                        onChange={(e) => setSaisie(e.target.value)}
                                        aria-label={t('Numéro du client')}
                                        aria-invalid={saisie.trim() !== '' && analyse?.numero === null}
                                        aria-describedby={`${idAuto}aide-numero`}
                                        placeholder="06 12 34 56 78"
                                        className="w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-sm focus:border-emerald-500 focus:outline-none"
                                    />
                                    <p id={`${idAuto}aide-numero`} aria-live="polite" className="mt-1 text-xs">
                                        {analyse?.numero ? (
                                            <>
                                                <span className="text-emerald-700">
                                                    {remplir(t('Numéro retenu : {numero}'), {
                                                        numero: <bdi dir="ltr">{affichage}</bdi>,
                                                    })}
                                                </span>
                                                {analyse.nature === 'fixe' && (
                                                    <span className="ms-1 text-amber-700">· {t('fixe — WhatsApp peu probable')}</span>
                                                )}
                                            </>
                                        ) : saisie.trim() === '' || analyse === null || analyse.raison === null ? (
                                            <span className="text-slate-500">
                                                {t('Mobile marocain (06…, 07…) ou numéro étranger précédé de +.')}
                                            </span>
                                        ) : (
                                            <span className="text-red-600">{libelleRaison(t, analyse.raison)}</span>
                                        )}
                                    </p>
                                </div>
                            )}

                            <Option nom={`${idAuto}numero`} valeur="aucun" coche={choixEffectif === 'aucun'} onChoisir={setChoix}>
                                <span className="block text-slate-900">{t('Sans numéro : choisir le contact dans WhatsApp')}</span>
                            </Option>
                        </div>

                        {data.numeros_ignores.length > 0 && (
                            <details className="mt-2 text-xs text-slate-500">
                                <summary className="cursor-pointer">
                                    {data.numeros_ignores.length === 1
                                        ? t('1 numéro inutilisable sur la fiche')
                                        : t('{n} numéros inutilisables sur la fiche', { n: data.numeros_ignores.length })}
                                </summary>
                                <ul className="mt-1 space-y-1 ps-4">
                                    {data.numeros_ignores.map((i, n) => (
                                        <li key={n} className="[overflow-wrap:anywhere]">
                                            {remplir(t('{libelle} : {saisie} — {raison}'), {
                                                libelle: i.libelle,
                                                saisie: <bdi dir="ltr">{i.saisie}</bdi>,
                                                raison: i.raison,
                                            })}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                    </fieldset>

                    <fieldset className="min-w-0">
                        <legend className="mb-1.5 text-sm font-medium text-slate-700">{t('Langue du message')}</legend>
                        <div className="grid grid-cols-2 gap-1.5">
                            {LANGUES.map((l) => (
                                <label
                                    key={l.code}
                                    lang={l.code}
                                    className={`relative flex cursor-pointer items-center justify-center rounded-md border px-2 py-1.5 text-sm font-medium transition has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-emerald-500 ${
                                        langue === l.code
                                            ? 'border-emerald-600 bg-emerald-50 text-emerald-800'
                                            : 'border-slate-300 text-slate-700 hover:bg-slate-50'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name={`${idAuto}langue`}
                                        value={l.code}
                                        checked={langue === l.code}
                                        onChange={() => setLangueChoisie(l.code)}
                                        className="sr-only"
                                    />
                                    {l.libelle}
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div>
                        <label htmlFor={`${idAuto}message`} className="mb-1.5 block text-sm font-medium text-slate-700">
                            {t('Message')}
                        </label>
                        <textarea
                            id={`${idAuto}message`}
                            // La direction suit la langue DU MESSAGE, pas celle de l'écran.
                            dir={langue === 'ar' ? 'rtl' : 'ltr'}
                            lang={langue}
                            rows={9}
                            value={texte}
                            onChange={(e) => retoucher(e.target.value)}
                            disabled={enAttente}
                            aria-describedby={`${idAuto}compteur`}
                            aria-invalid={!enAttente && (tropLong || message === '')}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm leading-relaxed focus:border-emerald-500 focus:outline-none disabled:bg-slate-50 disabled:text-slate-400"
                        />
                        <div className="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs">
                            {modifie ? (
                                <button
                                    type="button"
                                    onClick={() => setBrouillon(null)}
                                    className="text-emerald-700 hover:underline"
                                >
                                    {t('Rétablir le texte proposé')}
                                </button>
                            ) : (
                                <span />
                            )}
                            <span
                                id={`${idAuto}compteur`}
                                className={`tabular-nums ${tropLong || message === '' ? 'font-medium text-red-600' : 'text-slate-500'}`}
                            >
                                {message === ''
                                    ? t('Le message est vide.')
                                    : tropLong
                                      ? t('{n} caractères sur {max} : raccourcissez le message.', { n: longueur, max: MESSAGE_LONGUEUR_MAX })
                                      : t('{n} / {max} caractères', { n: longueur, max: MESSAGE_LONGUEUR_MAX })}
                            </span>
                        </div>
                    </div>

                    {cible.type === 'relance' && (
                        <div>
                            <label htmlFor={`${idAuto}note`} className="mb-1.5 block text-sm font-medium text-slate-700">
                                {t("Note pour l'historique des relances (facultatif)")}
                            </label>
                            <input
                                id={`${idAuto}note`}
                                value={note}
                                maxLength={255}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder={t('Promet un virement vendredi…')}
                                className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                            />
                        </div>
                    )}

                    <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        {lienPdfPresent ? (
                            <>
                                {remplir(t("Le lien vers le PDF reste valable jusqu'au {date}."), {
                                    date: <bdi dir="ltr">{dateCourte(data.lien_document.expire_le)}</bdi>,
                                })}{' '}
                                <a
                                    href={data.lien_document.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="font-medium text-emerald-700 hover:underline"
                                >
                                    {t('Voir le PDF que recevra le client')}
                                </a>
                            </>
                        ) : (
                            t('Le lien vers le PDF a été retiré du message.')
                        )}
                    </div>

                    <p className="text-xs text-slate-500">
                        {t('WhatsApp s’ouvre avec le message prêt : c’est vous qui appuyez sur Envoyer, depuis votre numéro.')}
                    </p>

                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={ouvrirWhatsApp}
                            disabled={!pret}
                            className="inline-flex items-center gap-2 rounded-md bg-emerald-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <IconeWhatsApp className="h-4 w-4 shrink-0" />
                            {t('Ouvrir WhatsApp')}
                        </button>
                        <button
                            type="button"
                            onClick={fermer}
                            className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            {integre ? t('Retour') : t('Annuler')}
                        </button>
                    </div>
                </>
            )}
        </div>
    );

    // Resté en français, le panneau s'écrit de gauche à droite même quand
    // l'interface est en arabe : hérité du <html dir="rtl">, le titre
    // devenait « MDK26-00194 Envoyer la facture » et le compteur « caractères
    // 2000 / 368 ». (La zone de texte, elle, suit la langue du message.)
    const panneau = (
        <div ref={racine} dir={traduire ? undefined : 'ltr'} lang={traduire ? undefined : 'fr'}>
            {enTete}
            {contenu}
        </div>
    );

    return integre ? panneau : (
        <Surcouche onFermer={fermer} etiquettePar={titre}>
            {panneau}
        </Surcouche>
    );
}

/** Un choix de destinataire : une carte cliquable, bouton radio compris. */
function Option({
    nom,
    valeur,
    coche,
    onChoisir,
    children,
}: {
    nom: string;
    valeur: string;
    coche: boolean;
    onChoisir: (valeur: string) => void;
    children: ReactNode;
}) {
    return (
        <label
            className={`flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-sm transition ${
                coche ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200 hover:bg-slate-50'
            }`}
        >
            <input
                type="radio"
                name={nom}
                value={valeur}
                checked={coche}
                onChange={() => onChoisir(valeur)}
                className="mt-0.5 shrink-0 accent-emerald-600"
            />
            <span className="min-w-0 flex-1">{children}</span>
        </label>
    );
}

/**
 * Après le clic : ce qui a été ouvert, et où en est le journal.
 *
 * Le lien « Rouvrir » est un vrai <a> : il reste en place quand son libellé
 * change, ce qui garde sa navigation — un lien retiré de la page pendant son
 * propre clic n'ouvre rien.
 */
function VueOuverte({
    t,
    refFocus,
    ouverture,
    journal,
    relance,
    onFermer,
    libelleFermer,
}: {
    t: Traduction;
    refFocus: RefObject<HTMLDivElement | null>;
    ouverture: Ouverture;
    journal: UseMutationResult<EnvoiWhatsApp, Error, Record<string, unknown>>;
    relance: boolean;
    onFermer: () => void;
    libelleFermer: string;
}) {
    const bloqueeSansSecours = ouverture.bloquee && journal.isIdle;

    return (
        <div ref={refFocus} tabIndex={-1} className="mt-4 space-y-3 focus:outline-none">
            {bloqueeSansSecours ? (
                <div role="alert" className="rounded-lg bg-amber-50 px-3 py-3 text-sm text-amber-800">
                    {t("Le navigateur a bloqué l'ouverture de WhatsApp : utilisez le bouton ci-dessous.")}
                </div>
            ) : (
                <div className="rounded-lg bg-emerald-50 px-3 py-3 text-sm text-emerald-900">
                    <p className="font-medium">{t('WhatsApp est ouvert, message prêt.')}</p>
                    <p className="mt-1 text-emerald-800">
                        {t('Relisez-le dans WhatsApp puis appuyez sur Envoyer : l’envoi se fait hors de l’application, qui ne peut pas le confirmer.')}
                    </p>
                </div>
            )}

            <p className="text-sm text-slate-600">
                {ouverture.destinataire
                    ? remplir(t('Destinataire : {numero}'), { numero: <bdi dir="ltr">{ouverture.destinataire}</bdi> })
                    : t('Destinataire : contact à choisir dans WhatsApp')}
            </p>

            <div aria-live="polite" className="text-sm">
                {journal.isPending && <p className="text-slate-500">{t('Enregistrement dans le journal…')}</p>}
                {journal.isSuccess && (
                    <p className="text-emerald-700">
                        ✓ {t('Noté dans le journal des envois.')}
                        {relance && journal.data.relance && (
                            <>
                                {' '}
                                {t('Relance « {niveau} » ajoutée à l’historique.', {
                                    niveau: libelleNiveau(t, journal.data.relance.niveau),
                                })}
                            </>
                        )}
                    </p>
                )}
                {journal.isError && (
                    <div role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-red-700">
                        <p>
                            {t("WhatsApp a bien été ouvert, mais l'envoi n'a pas pu être noté dans le journal :")}{' '}
                            {messageErreur(t, journal.error)}
                        </p>
                        <button
                            type="button"
                            onClick={() => journal.mutate(ouverture.corps)}
                            className="mt-1 font-medium text-red-800 underline"
                        >
                            {t("Réessayer l'enregistrement")}
                        </button>
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2 pt-1">
                <a
                    href={ouverture.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    // Fenêtre bloquée au premier clic : c'est CE clic qui ouvre
                    // WhatsApp, c'est donc lui qui journalise. Sinon, rouvrir
                    // ne journalise pas une seconde fois le même message.
                    onClick={() => {
                        if (journal.isIdle) journal.mutate(ouverture.corps);
                    }}
                    className="inline-flex items-center gap-2 rounded-md border border-emerald-300 px-4 py-2 text-sm font-medium text-emerald-800 transition hover:bg-emerald-50"
                >
                    <IconeWhatsApp className="h-4 w-4 shrink-0 text-[#25D366]" />
                    {bloqueeSansSecours ? t('Ouvrir WhatsApp') : t('Rouvrir WhatsApp')}
                </a>
                <button
                    type="button"
                    onClick={onFermer}
                    className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                >
                    {libelleFermer}
                </button>
            </div>
        </div>
    );
}

/**
 * L'historique des envois d'une pièce. Rien tant qu'il n'y en a pas : une
 * rubrique vide sur chaque facture ne dirait rien à qui n'utilise pas WhatsApp.
 *
 * Chaque ligne dit « WhatsApp ouvert », jamais « envoyé » : l'application a
 * ouvert WhatsApp avec le message prêt, l'envoi s'est fait (ou non) ensuite.
 */
export function HistoriqueEnvoisWhatsApp({ documentId, traduire = true }: { documentId: number; traduire?: boolean }) {
    const t = useTraduction(traduire);
    const { langue } = useLangue();
    const langueServeur = traduire ? langue : 'fr';

    const { data, isError } = useQuery({
        queryKey: ['envois-whatsapp', documentId, langueServeur],
        queryFn: async () => {
            const { data } = await api.get<{ data: EnvoiWhatsApp[] }>(`/ventes/documents/${documentId}/envois`, {
                headers: traduire ? undefined : { 'Accept-Language': 'fr' },
            });

            return data.data;
        },
        retry: (echecs, err) => (isAxiosError(err) && err.response !== undefined ? false : echecs < 1),
    });

    if (isError) {
        return (
            <section
                dir={traduire ? undefined : 'ltr'}
                lang={traduire ? undefined : 'fr'}
                className="rounded-xl bg-white p-5 text-sm text-slate-500 shadow-sm"
            >
                {t("Impossible de charger l'historique pour l'instant.")}
            </section>
        );
    }

    if (!data || data.length === 0) return null;

    // L'instant du serveur (UTC) lu à l'heure du poste.
    const quand = new Intl.DateTimeFormat(langueServeur === 'ar' ? 'ar-MA' : 'fr', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return (
        // Même raison que le panneau : en français, de gauche à droite.
        <section
            dir={traduire ? undefined : 'ltr'}
            lang={traduire ? undefined : 'fr'}
            className="rounded-xl bg-white p-5 shadow-sm"
        >
            <h2 className="flex items-center gap-1.5 font-medium text-slate-900">
                <IconeWhatsApp className="h-4 w-4 text-[#25D366]" />
                {t('Messages WhatsApp')}
            </h2>
            <p className="mt-1 text-xs text-slate-500">
                {t('« WhatsApp ouvert » : le message était prêt ; l’envoi lui-même se fait dans WhatsApp et n’est pas confirmé ici.')}
            </p>
            <ul className="mt-3 divide-y divide-slate-100">
                {data.map((envoi) => (
                    <li key={envoi.id} className="py-2.5 text-sm">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                            <span className="font-medium text-slate-800">{libelleEnvoi(t, envoi)}</span>
                            <span className="text-xs text-slate-500">
                                {envoi.langue === 'ar' ? t('Message en arabe') : t('Message en français')}
                            </span>
                        </div>
                        <p className="mt-0.5 text-slate-600">
                            {envoi.destinataire_affichage
                                ? remplir(t('WhatsApp ouvert le {date} par {auteur} vers {numero}'), {
                                      date: quand.format(new Date(envoi.created_at)),
                                      auteur: envoi.auteur.nom ?? '—',
                                      numero: <bdi dir="ltr">{envoi.destinataire_affichage}</bdi>,
                                  })
                                : remplir(t('WhatsApp ouvert le {date} par {auteur}, contact choisi dans WhatsApp'), {
                                      date: quand.format(new Date(envoi.created_at)),
                                      auteur: envoi.auteur.nom ?? '—',
                                  })}
                        </p>
                        <details className="mt-1">
                            <summary className="cursor-pointer text-xs text-emerald-700">{t('Voir le message')}</summary>
                            <p
                                dir={envoi.langue === 'ar' ? 'rtl' : 'ltr'}
                                lang={envoi.langue}
                                className="mt-1 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-xs text-slate-700 [overflow-wrap:anywhere]"
                            >
                                {envoi.message}
                            </p>
                        </details>
                    </li>
                ))}
            </ul>
        </section>
    );
}
