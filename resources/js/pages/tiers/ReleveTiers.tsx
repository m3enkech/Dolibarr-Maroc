import { useEffect, useId, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useFormats } from '@/lib/formats-langue';
import { useT } from '@/lib/langue';
import { useDebounce } from '@/lib/useDebounce';
import { lireDate } from '@/pages/tiers/params';
import type { CompteTiers, SoldeOuvertureSaisi } from '@/pages/tiers/SoldeOuverture';
import type { DocumentType, Tiers } from '@/types';

/** Réponse de GET /tiers/{id}/releve. Montants en chaînes à deux décimales, soldes SIGNÉS. */
type Releve = {
    compte: CompteTiers;
    comptes: string[];
    devise: string;
    du: string;
    au: string;
    /** La veille de « du » : le solde reporté est celui de ce soir-là. */
    report_au: string;
    solde_initial: string;
    total_debit: string;
    total_credit: string;
    solde_final: string;
    nb_lignes: number;
    /** Au-delà du plafond du serveur, les lignes ne sont pas servies — les soldes, si. */
    trop_de_lignes: boolean;
    lignes: {
        date: string;
        numero: string;
        journal: string;
        piece: string;
        document: { id: number; type: DocumentType } | null;
        libelle: string;
        debit: string;
        credit: string;
        solde: string;
    }[];
    solde_ouverture: SoldeOuvertureSaisi | null;
};

const CARTE = 'rounded-xl bg-white p-5 shadow-sm';

/**
 * L'onglet « Relevé » de la fiche : le compte du tiers au grand livre, sur une
 * période — solde reporté, chaque mouvement avec le solde après lui, solde
 * arrêté. Le même calcul que le PDF qu'on envoie au client, et le même solde
 * final que la liste et la vue d'ensemble (le serveur le garantit).
 *
 * LA PÉRIODE EST DANS L'URL, comme l'onglet : on passe d'un client à l'autre
 * sur la même période, et un lien partagé montre ce qu'on regardait. Les
 * dates sont envoyées EXPLICITEMENT au serveur, défaut compris : l'écran et le
 * relevé parlent toujours de la même période.
 *
 * Une période inversée ne part pas au serveur (il la refuserait) : on le dit
 * sous les dates, et le tableau précédent reste affiché, estompé — ou, s'il
 * n'y en a pas (lien partagé, autre tiers), rien qu'une invite à corriger.
 *
 * DEUX DATES, DEUX SOLDES. Le report est le solde au soir de la VEILLE de
 * « du » (`report_au`), le solde final celui au soir de « au » : écrire
 * « Solde au {du} » pour le premier donnait, sur une seule journée, deux
 * soldes différents à la même date.
 */
export default function ReleveTiers({
    tiers,
    comptes,
    compte,
    du,
    au,
    onPeriode,
    onCompte,
    onApercu,
    onSaisirOuverture,
}: {
    tiers: Tiers;
    /** Les comptes que CE rôle peut lire pour CE tiers (au moins un). */
    comptes: CompteTiers[];
    compte: CompteTiers;
    du: string;
    au: string;
    onPeriode: (periode: { du: string | null; au: string | null }) => void;
    onCompte: (compte: CompteTiers) => void;
    /** Ouvre l'aperçu d'une pièce de vente — seulement pour qui lit les ventes. */
    onApercu?: (documentId: number) => void;
    /** Proposé seulement à qui peut écrire en compta. */
    onSaisirOuverture?: () => void;
}) {
    const t = useT();
    const { can } = useAuth();
    const { montant } = useFormats();
    const idPeriode = useId();
    const titre = useId();
    const [erreurPdf, setErreurPdf] = useState<string | null>(null);
    const [pdfEnCours, setPdfEnCours] = useState(false);

    const inversee = du > au;

    const releve = useQuery({
        queryKey: ['tiers-releve', tiers.id, compte, du, au],
        queryFn: async () =>
            (await api.get<{ data: Releve }>(`/tiers/${tiers.id}/releve`, { params: { du, au, compte } })).data.data,
        enabled: !inversee,
        placeholderData: keepPreviousData,
        retry: (echecs, err) => (isAxiosError(err) && err.response !== undefined ? false : echecs < 1),
    });

    const donnees = releve.data;
    const fournisseur = compte === 'fournisseur';

    /**
     * Le PDF arrive en Blob, y compris quand le serveur le REFUSE (trop de
     * lignes) : son message JSON est alors dans le Blob, qu'il faut relire.
     */
    const telecharger = async () => {
        setErreurPdf(null);
        setPdfEnCours(true);
        try {
            const reponse = await api.get(`/tiers/${tiers.id}/releve`, {
                params: { du, au, compte, format: 'pdf' },
                responseType: 'blob',
            });
            const url = URL.createObjectURL(reponse.data);
            const lien = document.createElement('a');
            lien.href = url;
            lien.download = `releve-${tiers.code}-${du}-${au}.pdf`;
            lien.click();
            URL.revokeObjectURL(url);
        } catch (err) {
            let message: string | undefined;
            if (isAxiosError(err) && err.response?.data instanceof Blob && err.response.status === 422) {
                try {
                    message = (JSON.parse(await err.response.data.text()) as { message?: string }).message;
                } catch {
                    // Un corps illisible garde notre message.
                }
            }
            setErreurPdf(message ?? t("Le PDF n'a pas pu être généré."));
        } finally {
            setPdfEnCours(false);
        }
    };

    return (
        <section aria-labelledby={titre} className="space-y-4">
            <div className={CARTE}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        {/* Focalisable par script : la fiche y pose le focus après
                            un solde d'ouverture, dont le lien vient de disparaître. */}
                        <h2 id={titre} tabIndex={-1} data-retour-ouverture className="font-medium text-slate-900 focus:outline-none">
                            {fournisseur ? t('Relevé du compte fournisseur') : t('Relevé du compte client')}
                        </h2>
                        <p className="text-xs text-slate-500">
                            {fournisseur
                                ? t('Solde positif : nous devons au fournisseur ; négatif : il nous doit.')
                                : t('Solde positif : le client nous doit ; négatif : nous lui devons.')}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={telecharger}
                        disabled={pdfEnCours || inversee || !donnees || donnees.trop_de_lignes}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                    >
                        {pdfEnCours ? t('Génération…') : t('Télécharger le PDF')}
                    </button>
                </div>

                <div className="mt-4 flex flex-wrap items-end gap-3">
                    {comptes.length > 1 && (
                        <div role="group" aria-label={t('Compte')} className="flex gap-1 rounded-lg border border-slate-200 p-1">
                            {comptes.map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    onClick={() => onCompte(c)}
                                    aria-pressed={compte === c}
                                    className={`whitespace-nowrap rounded-md px-3 py-1 text-sm transition ${
                                        compte === c ? 'bg-emerald-600 font-medium text-white' : 'text-slate-600 hover:bg-slate-100'
                                    }`}
                                >
                                    {c === 'client' ? t('Compte client') : t('Compte fournisseur')}
                                </button>
                            ))}
                        </div>
                    )}
                    {/* Vider une date la retire de l'URL : on retombe sur le défaut. */}
                    <ChampDate
                        libelle={t('Du')}
                        valeur={du}
                        max={au}
                        decritPar={inversee ? idPeriode : undefined}
                        onValider={(d) => onPeriode({ du: d, au })}
                    />
                    <ChampDate
                        libelle={t('Au')}
                        valeur={au}
                        min={du}
                        decritPar={inversee ? idPeriode : undefined}
                        onValider={(a) => onPeriode({ du, au: a })}
                    />
                </div>
                {inversee && (
                    <p id={idPeriode} role="alert" className="mt-2 text-sm text-amber-800">
                        {t('La date de début doit précéder la date de fin.')}
                    </p>
                )}
                {erreurPdf && (
                    <p role="alert" className="mt-2 text-sm text-red-700">
                        {erreurPdf}
                    </p>
                )}

                {/* Le solde d'ouverture : proposé tant qu'il n'existe pas, à qui
                    peut écrire en compta ; rappelé une fois saisi. */}
                {donnees?.solde_ouverture === null && onSaisirOuverture && (
                    <p className="mt-3 text-sm text-slate-500">
                        {t("Aucun solde d'ouverture saisi pour ce tiers.")}
                        <button type="button" onClick={onSaisirOuverture} className="ms-2 font-medium text-emerald-700 hover:underline">
                            {t("Saisir le solde d'ouverture")}
                        </button>
                    </p>
                )}
            </div>

            {releve.isError && !donnees ? (
                <div role="alert" className={`${CARTE} text-center text-sm text-amber-800`}>
                    {isAxiosError(releve.error) && releve.error.response?.status === 403
                        ? t('Votre rôle ne donne pas accès à ce relevé.')
                        : t('Impossible de charger le relevé.')}
                    {!(isAxiosError(releve.error) && releve.error.response?.status === 403) && (
                        <button type="button" onClick={() => releve.refetch()} className="ms-2 font-medium text-amber-900 underline">
                            {t('Réessayer')}
                        </button>
                    )}
                </div>
            ) : !donnees && inversee ? (
                // La requête n'est pas partie et ne partira pas : « Chargement… »
                // sous « la date de début doit précéder… » disait à la fois
                // qu'on chargeait et qu'on ne chargerait pas.
                <p className={`${CARTE} py-8 text-center text-sm text-slate-500`}>
                    {t('Corrigez la période pour afficher le relevé.')}
                </p>
            ) : !donnees ? (
                <p role="status" className={`${CARTE} py-8 text-center text-sm text-slate-400`}>
                    {t('Chargement…')}
                </p>
            ) : (
                <div
                    aria-busy={releve.isPlaceholderData || inversee}
                    className={`space-y-4 transition-opacity ${releve.isPlaceholderData || inversee ? 'opacity-60' : ''}`}
                >
                    {/* Les quatre chiffres du relevé, lisibles sans le tableau. */}
                    <dl className="grid gap-3 rounded-xl bg-white p-5 shadow-sm @md:grid-cols-2 @3xl:grid-cols-4">
                        <Chiffre libelle={t('Solde au {date}', { date: donnees.report_au })} valeur={montant(donnees.solde_initial)} />
                        <Chiffre libelle={t('Débits de la période')} valeur={montant(donnees.total_debit)} />
                        <Chiffre libelle={t('Crédits de la période')} valeur={montant(donnees.total_credit)} />
                        <Chiffre libelle={t('Solde au {date}', { date: donnees.au })} valeur={montant(donnees.solde_final)} fort />
                    </dl>

                    {donnees.trop_de_lignes ? (
                        <p className={`${CARTE} text-center text-sm text-slate-500`}>
                            {t('{n} mouvements sur la période : trop pour les afficher. Réduisez la période.', {
                                n: donnees.nb_lignes,
                            })}
                        </p>
                    ) : (
                        // Le tableau défile seul en largeur sur téléphone : la
                        // page, elle, ne doit jamais déborder.
                        <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th scope="col" className="px-4 py-3 text-start font-medium">
                                            {t('Date')}
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-start font-medium">
                                            {t('Pièce')}
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-start font-medium">
                                            {t('Libellé')}
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-end font-medium">
                                            {t('Débit')}
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-end font-medium">
                                            {t('Crédit')}
                                        </th>
                                        <th scope="col" className="px-4 py-3 text-end font-medium">
                                            {t('Solde')}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    <tr className="bg-slate-50 font-medium text-slate-700">
                                        <td className="whitespace-nowrap px-4 py-2.5">{donnees.report_au}</td>
                                        <td className="px-4 py-2.5" />
                                        <td className="px-4 py-2.5">{t('Solde reporté')}</td>
                                        <td className="px-4 py-2.5" />
                                        <td className="px-4 py-2.5" />
                                        <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums">
                                            {montant(donnees.solde_initial)}
                                        </td>
                                    </tr>
                                    {donnees.lignes.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-4 py-8 text-center text-slate-400">
                                                {t('Aucun mouvement sur la période.')}
                                            </td>
                                        </tr>
                                    ) : (
                                        donnees.lignes.map((ligne, i) => (
                                            <tr key={`${ligne.numero}-${i}`} className="hover:bg-slate-50">
                                                <td className="whitespace-nowrap px-4 py-2.5 text-slate-600">{ligne.date}</td>
                                                <td className="whitespace-nowrap px-4 py-2.5">
                                                    {/* Une pièce de vente s'ouvre en aperçu, comme
                                                        dans les transactions — pour qui lit les ventes. */}
                                                    {ligne.document && onApercu && can('ventes') ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => onApercu(ligne.document!.id)}
                                                            className="rounded font-mono text-xs font-medium text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                                                        >
                                                            {ligne.piece}
                                                        </button>
                                                    ) : (
                                                        <span className="font-mono text-xs text-slate-600" title={ligne.numero}>
                                                            {ligne.piece}
                                                        </span>
                                                    )}
                                                </td>
                                                {/* Libellé comptable, en français par décision. */}
                                                <td className="min-w-[12rem] px-4 py-2.5 text-slate-700">
                                                    <bdi>{ligne.libelle}</bdi>
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-slate-900">
                                                    {parseFloat(ligne.debit) > 0 ? montant(ligne.debit) : ''}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-slate-900">
                                                    {parseFloat(ligne.credit) > 0 ? montant(ligne.credit) : ''}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums text-slate-700">
                                                    {montant(ligne.solde)}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                                {/* Deux lignes, comme le PDF : les colonnes Débit et
                                    Crédit portent les TOTAUX de la période, et
                                    « Solde au {au} » au-dessus d'eux les faisait
                                    passer pour des soldes. */}
                                <tfoot className="border-t-2 border-slate-300 text-slate-900">
                                    <tr className="font-medium">
                                        <td colSpan={3} className="px-4 py-2.5">
                                            {t('Totaux de la période')}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums">
                                            {montant(donnees.total_debit)}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-2.5 text-end tabular-nums">
                                            {montant(donnees.total_credit)}
                                        </td>
                                        <td className="px-4 py-2.5" />
                                    </tr>
                                    <tr className="font-semibold">
                                        <td colSpan={5} className="px-4 py-3">
                                            {t('Solde au {date}', { date: donnees.au })}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-end tabular-nums">
                                            {montant(donnees.solde_final)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * Un champ de date de la période, avec son BROUILLON.
 *
 * Contrôlé directement par l'URL, il était impossible d'y taper une année :
 * Chrome émet une valeur à chaque chiffre (0002-01-01, 0020-01-01,
 * 0202-01-01, 2025-01-01) ; la première, illisible, faisait retomber l'URL
 * sur le défaut et réécrivait le champ — et le routeur passant ses mises à
 * jour en transition, React remettait l'ancienne valeur après chaque frappe.
 * Seul le calendrier permettait encore de changer de période.
 *
 * Le champ vit donc sur une valeur locale, et la période ne change que sur
 * une date COMPLÈTE et plausible (année ≥ 1900, `lireDate`) : après une
 * courte pause de frappe, en quittant le champ, ou sur Entrée. Vidé, le
 * champ rend la période par défaut en le quittant ; resté incomplet, il
 * reprend la date en vigueur. Une période changée ailleurs (lien, autre
 * champ, Précédent) remplace le brouillon.
 */
function ChampDate({
    libelle,
    valeur,
    min,
    max,
    decritPar,
    onValider,
}: {
    libelle: string;
    valeur: string;
    min?: string;
    max?: string;
    decritPar?: string;
    onValider: (date: string | null) => void;
}) {
    const id = useId();
    const [brouillon, setBrouillon] = useState(valeur);
    // La valeur reçue au rendu précédent : quand elle change, le brouillon la
    // reprend — un état ajusté pendant le rendu, sans effet à synchroniser.
    const [recue, setRecue] = useState(valeur);
    if (valeur !== recue) {
        setRecue(valeur);
        setBrouillon(valeur);
    }

    const valider = (date: string) => {
        if (date === '') {
            // Le défaut peut être la date déjà en vigueur : rien ne change
            // alors côté période, et le champ resterait vide sans ceci.
            setBrouillon(valeur);
            onValider(null);
        } else if (lireDate(date) === null) setBrouillon(valeur);
        else if (date !== valeur) onValider(date);
    };

    // Le calendrier, lui, ne déclenche ni sortie ni Entrée : la date choisie
    // part après la pause. Une saisie au clavier en cours ne part pas tant
    // qu'elle n'est pas plausible.
    const retardee = useDebounce(brouillon, 600);
    useEffect(() => {
        if (retardee !== valeur && lireDate(retardee) !== null) onValider(retardee);
        // Seule la valeur retardée déclenche ; valeur et rappel changent à chaque rendu.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [retardee]);

    return (
        <div className="min-w-0">
            <label htmlFor={id} className="mb-1 block text-xs uppercase tracking-wide text-slate-500">
                {libelle}
            </label>
            <input
                id={id}
                type="date"
                value={brouillon}
                min={min}
                max={max}
                onChange={(e) => setBrouillon(e.target.value)}
                onBlur={() => valider(brouillon)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        valider(brouillon);
                    }
                }}
                aria-describedby={decritPar}
                className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-700 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
            />
        </div>
    );
}

function Chiffre({ libelle, valeur, fort }: { libelle: string; valeur: string; fort?: boolean }) {
    return (
        <div className="min-w-0 rounded-lg bg-slate-50 px-4 py-3">
            <dt className="text-xs uppercase tracking-wide text-slate-500">{libelle}</dt>
            <dd title={valeur} className={`mt-0.5 truncate tabular-nums ${fort ? 'text-lg font-semibold text-slate-900' : 'text-slate-900'}`}>
                {valeur}
            </dd>
        </div>
    );
}
