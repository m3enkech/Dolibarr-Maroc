import { useId } from 'react';
import { Link } from 'react-router-dom';
import GraphiqueBarres from '@/components/GraphiqueBarres';
import { useAuth } from '@/lib/auth';
import { useFormats } from '@/lib/formats-langue';
import { useLangue, useT } from '@/lib/langue';
import { PERIODES, type Periode } from '@/pages/tiers/params';
import type { AdhesionStatut, Tiers } from '@/types';

/**
 * Réponse de GET /tiers/{id}/vue-ensemble. `credit` et `compte` sont ABSENTS
 * — pas nuls — pour un tiers qui n'est pas client ; `revenus` l'est en plus
 * sans le droit ventes (le caissier voit ce que le client doit, comme à la
 * caisse, pas ce qu'il rapporte) ; `portail` sans le droit d'en gérer les
 * accès. `credit: null` veut dire « aucun plafond » ; `delai_paiement_jours:
 * null`, « non renseigné » — pas zéro.
 */
export type VueEnsemble = {
    client: boolean;
    contact_principal: {
        id: number;
        nom: string;
        fonction: string | null;
        email: string | null;
        phone: string | null;
        mobile: string | null;
    } | null;
    delai_paiement_jours: number | null;
    portail?: {
        statut: AdhesionStatut;
        acheteur: { name: string | null; email: string | null };
        demande_at: string | null;
        approuve_at: string | null;
    }[];
    credit?: { plafond: string; encours: string; disponible: string } | null;
    compte?: { devise: string; creances: string; credits: string };
    revenus?: {
        periode: Periode;
        du: string;
        au: string;
        serie: { mois: string; ca: string }[];
        total: string;
        /** Factures et avoirs de la période : un total nul ne dit pas s'il n'y a rien eu. */
        nb_pieces: number;
    };
};

export type VueEnsembleReponse = { data: VueEnsemble; capacites: { ventes: boolean; portail: boolean } };

const CARTE = 'rounded-xl bg-white p-5 shadow-sm';
const LIEN = 'text-sm font-medium text-emerald-700 hover:underline';

/**
 * La vue d'ensemble d'un tiers, façon Zoho Books : qui appeler et à quelles
 * conditions il achète à gauche ; ce qu'il doit, ce qu'il peut encore prendre
 * à crédit et ce qu'il nous rapporte à droite.
 *
 * DEUX COLONNES SELON LA LARGEUR DE LA FICHE, PAS DE L'ÉCRAN. Au bureau, la
 * fiche n'a que 800 px à côté de la liste (1440 px d'écran), 640 à 1280 px :
 * c'est `@3xl` (768 px) de la FICHE qui ouvre la seconde colonne, une
 * requête d'écran l'aurait ouverte dans 640 px. Chaque colonne est à son tour
 * un conteneur : ses cartes se règlent sur leur propre largeur.
 *
 * L'ÉCRAN AFFICHE CE QUE LE SERVEUR ENVOIE. Client ou non, droit sur les
 * ventes ou non : il ne le redécide pas, il montre les blocs reçus. La seule
 * supposition est celle d'AVANT la réponse, avec la règle du serveur (client
 * ou prospect) — un fournisseur pur n'aura pas de colonne chiffrée, inutile
 * d'y poser un « Chargement… » qui disparaîtrait à la réponse.
 *
 * EN ÉCHEC, ON LE DIT AU-DESSUS DE TOUT. L'appel porte aussi le contact et le
 * portail : une alerte rangée dans la colonne chiffrée n'apparaissait pas
 * chez un fournisseur, et la carte du contact affirmait « Aucun contact
 * principal » — en invitant à créer un doublon.
 */
export default function VueEnsembleTiers({
    tiers,
    reponse,
    indisponible,
    onReessayer,
    periode,
    periodeEnCours,
    onPeriode,
    onVoirContacts,
    lienModifier,
    clientSuppose,
}: {
    tiers: Tiers;
    reponse?: VueEnsembleReponse;
    /** Échec sans donnée à montrer. */
    indisponible: boolean;
    onReessayer: () => void;
    /** La période DEMANDÉE (celle de l'URL), que montre le choix. */
    periode: Periode;
    /** Une autre période est demandée : le graphique affiché est encore l'ancien. */
    periodeEnCours: boolean;
    onPeriode: (periode: Periode) => void;
    onVoirContacts: () => void;
    lienModifier: { pathname: string; search: string };
    /** Ce qu'on suppose AVANT la réponse : client ou prospect, la règle du serveur. */
    clientSuppose: boolean;
}) {
    const t = useT();
    const vue = reponse?.data;

    // Avant la réponse, on suppose ; après, le serveur tranche. En échec, il
    // n'y a rien à mettre dans la colonne : la gauche prend toute la largeur.
    const colonneChiffree = !indisponible && (vue ? vue.client : clientSuppose);

    return (
        <div className="space-y-4">
            {indisponible && (
                <p role="alert" className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {t("Vue d'ensemble indisponible.")}
                    <button type="button" onClick={onReessayer} className="ms-2 font-medium text-amber-900 underline">
                        {t('Réessayer')}
                    </button>
                </p>
            )}

            <div className="grid gap-4 @3xl:grid-cols-2">
                <div className={`@container min-w-0 space-y-4 ${colonneChiffree ? '' : '@3xl:col-span-2'}`}>
                    <CarteContact tiers={tiers} vue={vue} indisponible={indisponible} onVoirContacts={onVoirContacts} />
                    <CarteAdresse tiers={tiers} lienModifier={lienModifier} />
                    <CarteIdentite tiers={tiers} />
                </div>

                {colonneChiffree && (
                    <div className="@container min-w-0 space-y-4">
                        {!vue ? (
                            <p role="status" className={`${CARTE} py-8 text-center text-sm text-slate-400`}>
                                {t('Chargement…')}
                            </p>
                        ) : (
                            <>
                                <CarteConditions vue={vue} lienModifier={lienModifier} />
                                {vue.compte && <CarteCompte compte={vue.compte} />}
                                {vue.revenus && (
                                    <CarteRevenus
                                        revenus={vue.revenus}
                                        periode={periode}
                                        enCours={periodeEnCours}
                                        onPeriode={onPeriode}
                                    />
                                )}
                            </>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------------- */
/* Colonne de gauche                                                       */
/* ---------------------------------------------------------------------- */

const ETATS_PORTAIL: Record<AdhesionStatut, string> = {
    en_attente: 'bg-amber-100 text-amber-700',
    approuve: 'bg-emerald-100 text-emerald-700',
    refuse: 'bg-red-100 text-red-700',
    revoque: 'bg-slate-200 text-slate-600',
};

/**
 * La personne à appeler, et ce qu'elle peut faire seule sur le portail. Pas
 * de « Réinviter » : aucun courriel ne part encore de l'application, le
 * bouton ne ferait rien. L'état se lit ici, il se gère sur l'écran des
 * adhésions.
 */
function CarteContact({
    tiers,
    vue,
    indisponible,
    onVoirContacts,
}: {
    tiers: Tiers;
    vue?: VueEnsemble;
    indisponible: boolean;
    onVoirContacts: () => void;
}) {
    const t = useT();
    const { can } = useAuth();
    const titre = useId();
    const titrePortail = useId();

    // Libellés des états en littéraux : les mêmes clés que l'écran des
    // adhésions, donc les mêmes traductions.
    const LIBELLES_PORTAIL: Record<AdhesionStatut, string> = {
        en_attente: t('En attente'),
        approuve: t('Accès actif'),
        refuse: t('Refusée'),
        revoque: t('Accès fermé'),
    };

    const principal = vue?.contact_principal ?? null;
    // Téléphone et e-mail s'écrivent de gauche à droite en toute langue :
    // `dir="ltr"` garde le « + » de +212 devant sur l'écran arabe.
    const coordonnees = principal
        ? [
              principal.email && { cle: 'email', lien: `mailto:${principal.email}`, texte: principal.email },
              principal.mobile && { cle: 'mobile', lien: `tel:${principal.mobile}`, texte: principal.mobile },
              principal.phone && { cle: 'phone', lien: `tel:${principal.phone}`, texte: principal.phone },
          ].filter((c): c is { cle: string; lien: string; texte: string } => Boolean(c))
        : [];

    return (
        <section aria-labelledby={titre} className={CARTE}>
            <h2 id={titre} className="font-medium text-slate-900">
                {t('Contact principal')}
            </h2>

            {principal ? (
                <div className="mt-3">
                    <p className="font-medium text-slate-900">
                        <bdi>{principal.nom}</bdi>
                    </p>
                    {principal.fonction && <p className="text-sm text-slate-500">{principal.fonction}</p>}
                    {coordonnees.length > 0 && (
                        <ul className="mt-2 space-y-1 text-sm">
                            {coordonnees.map((c) => (
                                <li key={c.cle} className="break-words">
                                    <a href={c.lien} dir="ltr" className="text-emerald-700 hover:underline">
                                        {c.texte}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            ) : !vue && !indisponible ? (
                <p className="mt-3 text-sm text-slate-400">{t('Chargement…')}</p>
            ) : tiers.contact_name ? (
                // Les anciens tiers et les reprises n'ont qu'un nom saisi sur
                // la fiche — lu sur le tiers lui-même, il tient même en échec.
                <p className="mt-3 text-sm text-slate-900">
                    <bdi>{tiers.contact_name}</bdi>
                </p>
            ) : indisponible ? (
                // En échec, on ne SAIT pas s'il y a un contact principal :
                // ni « aucun », ni bouton pour en ajouter un qui existe peut-être.
                <p className="mt-3 text-sm text-slate-400">{t('Contact indisponible.')}</p>
            ) : (
                <div className="mt-3 text-sm text-slate-500">
                    <p>{t('Aucun contact principal.')}</p>
                    {can('tiers', 'write') && (
                        <button type="button" onClick={onVoirContacts} className={`mt-1 ${LIEN}`}>
                            {t('Ajouter un contact')}
                        </button>
                    )}
                </div>
            )}

            {/* Le portail est celui des ACHETEURS : chez un fournisseur pur,
                « aucun compte acheteur » ne dirait rien d'utile. Sauf s'il
                en a un, rattaché quand même — alors on le montre. */}
            {vue?.portail && (vue.client || vue.portail.length > 0) && (
                <div className="mt-4 border-t border-slate-100 pt-4">
                    <h3 id={titrePortail} className="text-xs uppercase tracking-wide text-slate-500">
                        {t('Portail client')}
                    </h3>
                    {vue.portail.length === 0 ? (
                        <p className="mt-1 text-sm text-slate-500">{t('Aucun compte acheteur rattaché à ce client.')}</p>
                    ) : (
                        <ul aria-labelledby={titrePortail} className="mt-2 space-y-2">
                            {vue.portail.map((acces, i) => (
                                <li key={`${acces.acheteur.email ?? ''}-${i}`} className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                    <span className="min-w-0 break-words text-slate-900">
                                        <bdi>{acces.acheteur.name ?? acces.acheteur.email ?? '—'}</bdi>
                                    </span>
                                    {acces.acheteur.email && acces.acheteur.name && (
                                        <span dir="ltr" className="min-w-0 break-words text-xs text-slate-500">
                                            {acces.acheteur.email}
                                        </span>
                                    )}
                                    <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${ETATS_PORTAIL[acces.statut]}`}>
                                        {LIBELLES_PORTAIL[acces.statut]}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link to="/adhesions" className={`mt-2 inline-block ${LIEN}`}>
                        {t('Gérer les accès au portail')}
                    </Link>
                </div>
            )}
        </section>
    );
}

/** Où livrer et quoi écrire sur l'enveloppe. Le pays seul ne fait pas une adresse. */
function CarteAdresse({ tiers, lienModifier }: { tiers: Tiers; lienModifier: { pathname: string; search: string } }) {
    const t = useT();
    const { can } = useAuth();
    const { langue } = useLangue();
    const titre = useId();

    const ville = [tiers.postal_code, tiers.city].filter(Boolean).join(' ');
    const renseignee = Boolean(tiers.address || ville);

    // Le pays est un code (« MA ») : son nom vient d'Intl, dans la langue de l'écran.
    let pays = tiers.country;
    try {
        pays = new Intl.DisplayNames([langue === 'ar' ? 'ar-MA' : 'fr'], { type: 'region' }).of(tiers.country) ?? tiers.country;
    } catch {
        // Un code saisi à la main et inconnu d'Intl s'affiche tel quel.
    }

    return (
        <section aria-labelledby={titre} className={CARTE}>
            <h2 id={titre} className="font-medium text-slate-900">
                {t('Adresse')}
            </h2>
            {renseignee ? (
                <address className="mt-3 text-sm not-italic text-slate-900">
                    {tiers.address && <bdi className="block whitespace-pre-line">{tiers.address}</bdi>}
                    {ville && <bdi className="block">{ville}</bdi>}
                    {pays && <span className="block text-slate-500">{pays}</span>}
                </address>
            ) : (
                <div className="mt-3 text-sm text-slate-500">
                    <p>{t('Aucune adresse renseignée.')}</p>
                    {can('tiers', 'write') && (
                        <Link to={lienModifier} className={`mt-1 inline-block ${LIEN}`}>
                            {t('Ajouter une adresse')}
                        </Link>
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * Les identifiants légaux et les coordonnées de l'ENTREPRISE — celles de la
 * personne sont sur la carte du contact. Rien n'est affiché vide : une ligne
 * « RC : — » par champ non saisi noierait les trois qui le sont.
 */
function CarteIdentite({ tiers }: { tiers: Tiers }) {
    const t = useT();
    const titre = useId();

    const lignes: { cle: string; libelle: string; valeur: React.ReactNode }[] = [
        { cle: 'ice', libelle: t('ICE'), valeur: tiers.ice && <bdi>{tiers.ice}</bdi> },
        { cle: 'if', libelle: t('IF'), valeur: tiers.if_number && <bdi>{tiers.if_number}</bdi> },
        { cle: 'rc', libelle: t('RC'), valeur: tiers.rc && <bdi>{tiers.rc}</bdi> },
        { cle: 'patente', libelle: t('Patente'), valeur: tiers.patente && <bdi>{tiers.patente}</bdi> },
        { cle: 'cnss', libelle: t('CNSS'), valeur: tiers.cnss && <bdi>{tiers.cnss}</bdi> },
        {
            cle: 'telephone',
            libelle: t('Téléphone'),
            valeur: tiers.phone && (
                <a href={`tel:${tiers.phone}`} dir="ltr" className="text-emerald-700 hover:underline">
                    {tiers.phone}
                </a>
            ),
        },
        {
            cle: 'email',
            libelle: t('Email'),
            valeur: tiers.email && (
                <a href={`mailto:${tiers.email}`} dir="ltr" className="text-emerald-700 hover:underline">
                    {tiers.email}
                </a>
            ),
        },
        {
            cle: 'site',
            libelle: t('Site web'),
            valeur: tiers.website && (
                <a href={tiers.website} target="_blank" rel="noopener noreferrer" dir="ltr" className="text-emerald-700 hover:underline">
                    {tiers.website}
                </a>
            ),
        },
    ].filter((ligne) => Boolean(ligne.valeur));

    return (
        <section aria-labelledby={titre} className={CARTE}>
            <h2 id={titre} className="font-medium text-slate-900">
                {t('Identité')}
            </h2>
            {lignes.length === 0 ? (
                <p className="mt-2 text-sm text-slate-400">{t('Aucune information renseignée.')}</p>
            ) : (
                <dl className="mt-3 grid gap-x-6 gap-y-3 @md:grid-cols-2">
                    {lignes.map((ligne) => (
                        <div key={ligne.cle} className="min-w-0">
                            <dt className="text-xs uppercase tracking-wide text-slate-500">{ligne.libelle}</dt>
                            <dd className="mt-0.5 break-words text-sm text-slate-900">{ligne.valeur}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}

/* ---------------------------------------------------------------------- */
/* Colonne de droite                                                       */
/* ---------------------------------------------------------------------- */

/**
 * Délai et plafond. Zéro jour : « Payable à réception ». AUCUN délai saisi :
 * « Non renseigné », pas « à réception » — c'est le cas de tous les tiers
 * repris de Zoho, dont les factures sont pourtant à 30 ou 60 jours ; écrire
 * « à réception » inventait une condition que leurs propres pièces démentent.
 * Sans plafond : « Non limité », et la jauge seulement quand il y a une
 * limite à consommer.
 */
function CarteConditions({ vue, lienModifier }: { vue: VueEnsemble; lienModifier: { pathname: string; search: string } }) {
    const t = useT();
    const { can } = useAuth();

    const delai = vue.delai_paiement_jours;
    const credit = vue.credit;

    return (
        <section aria-label={t('Conditions commerciales')} className={CARTE}>
            <dl className="space-y-4">
                <div>
                    <dt className="text-xs uppercase tracking-wide text-slate-500">{t('Conditions de paiement')}</dt>
                    {delai === null ? (
                        <dd className="mt-0.5 text-sm text-slate-500">
                            {t('Non renseigné')}
                            {can('tiers', 'write') && (
                                <Link to={lienModifier} className={`ms-2 ${LIEN}`}>
                                    {t('Renseigner')}
                                </Link>
                            )}
                        </dd>
                    ) : (
                        <dd className="mt-0.5 text-sm font-medium text-slate-900">
                            {delai === 0 ? t('Payable à réception') : t('{n} jours', { n: delai })}
                        </dd>
                    )}
                </div>

                {/* Absent pour un tiers qui n'est pas client : la ligne entière disparaît. */}
                {credit !== undefined && (
                    <div>
                        <dt className="text-xs uppercase tracking-wide text-slate-500">{t('Limite de crédit')}</dt>
                        <dd className="mt-0.5 text-sm text-slate-900">
                            {credit === null ? <span className="font-medium">{t('Non limité')}</span> : <Jauge credit={credit} />}
                        </dd>
                    </div>
                )}
            </dl>
        </section>
    );
}

/**
 * Encours rapporté au plafond, sur le modèle de l'écran « Mon compte » du
 * portail. Le mot est « encours », pas « crédit utilisé » : à côté, le compte
 * client parle de ce qu'on doit AU client, et « crédit » pour les deux
 * mettait sous le même nom trois chiffres qui ne s'additionnent pas.
 */
function Jauge({ credit }: { credit: { plafond: string; encours: string; disponible: string } }) {
    const t = useT();
    const { montant } = useFormats();

    const encours = parseFloat(credit.encours);
    const plafond = parseFloat(credit.plafond);
    // Un plafond à zéro est un « pas de crédit » : le moindre encours le remplit.
    const part = plafond > 0 ? Math.min(100, Math.round((encours / plafond) * 100)) : encours > 0 ? 100 : 0;
    const couleur = part >= 90 ? 'bg-red-500' : part >= 70 ? 'bg-amber-500' : 'bg-emerald-500';

    return (
        <>
            <p>
                <span className="font-medium tabular-nums">{montant(credit.plafond)}</span>
                <span className="text-slate-500"> · {t('Encours : {encours}', { encours: montant(credit.encours) })}</span>
            </p>
            <div
                role="meter"
                aria-label={t('Encours sur la limite de crédit')}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={part}
                aria-valuetext={t('Encours de {encours} sur {plafond}', {
                    encours: montant(credit.encours),
                    plafond: montant(credit.plafond),
                })}
                className="mt-2 h-2 w-full rounded-full bg-slate-100"
            >
                <div className={`h-2 rounded-full ${couleur}`} style={{ width: `${Math.max(2, part)}%` }} />
            </div>
            <p className="mt-1.5 text-slate-600">
                {t('Disponible')} : <strong className="tabular-nums">{montant(credit.disponible)}</strong>
            </p>
        </>
    );
}

/**
 * Le compte client du grand livre — la MÊME définition que la liste : ce
 * qu'il doit (créances) ou ce qu'on lui doit (avoir non remboursé, trop-perçu).
 * Les deux ne sont jamais non nuls ensemble : c'est un solde, coupé en deux.
 *
 * Zoho écrit « crédits inutilisés » ; ici « en faveur du client », parce que
 * la carte voisine parle de LIMITE de crédit : un avoir qu'on doit au client
 * n'a rien à voir avec ce qu'il peut encore acheter à terme.
 */
function CarteCompte({ compte }: { compte: NonNullable<VueEnsemble['compte']> }) {
    const t = useT();
    const { montant } = useFormats();
    const titre = useId();
    const doit = parseFloat(compte.creances) > 0;

    return (
        <section aria-labelledby={titre} className={CARTE}>
            <h2 id={titre} className="font-medium text-slate-900">
                {t('Compte client')}
            </h2>
            <div className="mt-3 overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" className="py-2 pe-3 text-start font-normal">
                                {t('Devise')}
                            </th>
                            <th scope="col" className="px-3 py-2 text-end font-normal">
                                {t('Créances impayées')}
                            </th>
                            <th scope="col" className="py-2 ps-3 text-end font-normal">
                                {t('En faveur du client')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td className="py-2 pe-3 text-slate-600">
                                <bdi>{compte.devise}</bdi>
                            </td>
                            <td
                                className={`whitespace-nowrap px-3 py-2 text-end font-medium tabular-nums ${
                                    doit ? 'text-amber-700' : 'text-slate-900'
                                }`}
                            >
                                {montant(compte.creances)}
                            </td>
                            <td className="whitespace-nowrap py-2 ps-3 text-end tabular-nums text-slate-900">
                                {montant(compte.credits)}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    );
}

/**
 * Ce que le client nous rapporte, mois par mois : HORS TAXES et avoirs
 * déduits, la définition du tableau de bord. La période vit dans l'URL,
 * comme l'onglet : elle survit au rechargement et suit d'un client à l'autre.
 */
function CarteRevenus({
    revenus,
    periode,
    enCours,
    onPeriode,
}: {
    revenus: NonNullable<VueEnsemble['revenus']>;
    periode: Periode;
    enCours: boolean;
    onPeriode: (periode: Periode) => void;
}) {
    const t = useT();
    const { montant } = useFormats();
    const titre = useId();
    const choix = useId();

    // Libellés en littéraux, pour le relevé des traductions manquantes.
    const LIBELLES: Record<Periode, string> = {
        '6m': t('6 derniers mois'),
        '12m': t('12 derniers mois'),
        annee: t('Année en cours'),
    };
    const TOTAUX: Record<Periode, string> = {
        '6m': t('Revenu total (6 derniers mois)'),
        '12m': t('Revenu total (12 derniers mois)'),
        annee: t('Revenu total (année en cours)'),
    };

    // Deux vides qui ne disent pas la même chose. Sans pièce, « aucune
    // vente » ; avec des pièces mais des mois tous nuls, les avoirs ont
    // annulé les factures — on a bien vendu, et le KPI « Facturé TTC » à côté
    // le montre : le dire, plutôt qu'un graphique plat ou une absence fausse.
    const aucunePiece = revenus.nb_pieces === 0;
    const toutAnnule = !aucunePiece && revenus.serie.every((m) => parseFloat(m.ca) === 0);

    return (
        <section aria-labelledby={titre} className={CARTE}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                    <h2 id={titre} className="font-medium text-slate-900">
                        {t('Revenus')}
                    </h2>
                    <p className="text-xs text-slate-500">{t('Hors taxes, avoirs déduits')}</p>
                </div>
                <label htmlFor={choix} className="sr-only">
                    {t('Période')}
                </label>
                {/* Le choix montre la période DEMANDÉE, aussitôt ; le total et
                    les barres, celle des données reçues — estompées le temps
                    que l'autre arrive, plutôt qu'un choix qui revient en
                    arrière sous le doigt. */}
                <select
                    id={choix}
                    value={periode}
                    onChange={(e) => onPeriode(e.target.value as Periode)}
                    className="rounded-md border border-slate-300 bg-white py-1.5 ps-2 pe-8 text-sm text-slate-700"
                >
                    {PERIODES.map((p) => (
                        <option key={p} value={p}>
                            {LIBELLES[p]}
                        </option>
                    ))}
                </select>
            </div>

            <div aria-busy={enCours} className={`mt-4 transition-opacity ${enCours ? 'opacity-60' : ''}`}>
                <p className="text-xs uppercase tracking-wide text-slate-500">{TOTAUX[revenus.periode]}</p>
                <p className="mt-0.5 text-lg font-semibold tabular-nums text-slate-900">{montant(revenus.total)}</p>

                {aucunePiece || toutAnnule ? (
                    <p className="mt-3 rounded-lg bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                        {aucunePiece
                            ? t('Aucune vente sur la période.')
                            : t('Aucun revenu net : les avoirs de la période annulent ses factures.')}
                    </p>
                ) : (
                    <div className="mt-3">
                        <GraphiqueBarres
                            points={revenus.serie.map((m) => ({ mois: m.mois, valeur: parseFloat(m.ca) }))}
                            titre={`${t('Revenus')} — ${LIBELLES[revenus.periode]}`}
                            description={t('Hors taxes, avoirs déduits')}
                            libelleValeur={t('Revenus')}
                        />
                    </div>
                )}
            </div>
        </section>
    );
}
