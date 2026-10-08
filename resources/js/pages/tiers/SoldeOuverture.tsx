import { useId, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import Surcouche from '@/components/Surcouche';
import { api } from '@/lib/api';
import { useT } from '@/lib/langue';
import { aujourdHui } from '@/pages/tiers/params';
import type { Tiers } from '@/types';

/** GET /tiers/{id}/solde-ouverture : ce que le compte porte déjà, compte par compte. */
type Contexte = {
    existant: SoldeOuvertureSaisi | null;
    comptes: {
        compte: CompteTiers;
        /** Lignes déjà au grand livre pour ce tiers sur ce compte, à-nouveaux exclus. */
        mouvements: number;
        premier: string | null;
        /** La veille du premier mouvement, jamais dans un exercice clôturé ni dans le futur. */
        date_proposee: string;
    }[];
};

/** Le compte d'un tiers que vise un solde d'ouverture ou un relevé. */
export type CompteTiers = 'client' | 'fournisseur';

/** Le solde d'ouverture déjà saisi, tel que le servent la vue d'ensemble et le relevé. */
export type SoldeOuvertureSaisi = {
    numero: string;
    date: string;
    montant: string;
    sens: 'debit' | 'credit';
    compte: CompteTiers;
};

/**
 * Saisir le solde d'ouverture d'un tiers — ce qu'il devait, ou ce qu'on lui
 * devait, le jour où l'on a commencé à tenir son compte ici.
 *
 * LE SENS EN MOTS, PAS EN « DÉBIT / CRÉDIT ». Celui qui saisit sait que le
 * client lui doit 12 000 DH ; qu'il faille pour cela débiter le compte 3421,
 * c'est l'affaire du serveur. Les deux choix sont donc écrits du point de vue
 * de l'entreprise, et changent avec le compte (client ou fournisseur).
 *
 * Ce qui part : UNE écriture au journal des opérations diverses, contre un
 * compte d'attente que le comptable soldera — on le dit, pour qu'il sache où
 * la retrouver. Une seule par tiers : le serveur refuse la seconde, et son
 * message s'affiche tel quel.
 *
 * CE QUE LE COMPTE PORTE DÉJÀ. Un client repris de Zoho a des années de
 * pièces au grand livre : son solde d'ouverture est ce qu'il devait AVANT la
 * première, et il s'y AJOUTE. La date proposée est donc la veille de ce
 * premier mouvement (le 1er janvier pour un tiers sans historique), et l'écran
 * prévient — avant l'envoi — qu'il y a déjà des mouvements, et quand la date
 * choisie les suit : taper le solde ACTUEL comme ouverture le doublait.
 */
export default function SoldeOuverture({
    tiers,
    comptes,
    onFermer,
    onSaisi,
}: {
    tiers: Tiers;
    /** Les comptes possibles pour CE tiers, le premier par défaut. */
    comptes: CompteTiers[];
    onFermer: () => void;
    /** `avertissement` : enregistré, mais quelque chose reste à faire (série OD à renuméroter). */
    onSaisi: (saisi: SoldeOuvertureSaisi, avertissement: string | null) => void;
}) {
    const t = useT();
    const queryClient = useQueryClient();
    const titre = useId();
    const idMontant = useId();
    const idDate = useId();
    const idAide = useId();
    const idMouvements = useId();

    const [compte, setCompte] = useState<CompteTiers>(comptes[0] ?? 'client');
    // Le sens « normal » du compte : le client nous doit, nous devons au fournisseur.
    const [sens, setSens] = useState<'debit' | 'credit'>(compte === 'fournisseur' ? 'credit' : 'debit');
    const [montant, setMontant] = useState('');
    const [erreur, setErreur] = useState<string | null>(null);

    const contexte = useQuery({
        queryKey: ['tiers-solde-ouverture', tiers.id],
        queryFn: async () => (await api.get<{ data: Contexte }>(`/tiers/${tiers.id}/solde-ouverture`)).data.data,
        // Toujours relu à l'ouverture : une pièce saisie entre-temps déplace le premier mouvement.
        staleTime: 0,
        retry: (echecs, err) => (isAxiosError(err) && err.response !== undefined ? false : echecs < 1),
    });
    const duCompte = contexte.data?.comptes.find((c) => c.compte === compte);

    // La date TAPÉE l'emporte ; tant qu'on n'y a pas touché, c'est celle que
    // propose le serveur pour le compte choisi — et, avant sa réponse ou s'il
    // ne répond pas, le 1er janvier de l'année.
    const [dateTapee, setDateTapee] = useState<string | null>(null);
    const date = dateTapee ?? duCompte?.date_proposee ?? `${aujourdHui().slice(0, 4)}-01-01`;
    const apresPremier = duCompte?.premier != null && date >= duCompte.premier;

    const choisirCompte = (c: CompteTiers) => {
        setCompte(c);
        setSens(c === 'fournisseur' ? 'credit' : 'debit');
    };

    const sensPossibles: { valeur: 'debit' | 'credit'; libelle: string }[] =
        compte === 'fournisseur'
            ? [
                  { valeur: 'credit', libelle: t('Nous devons au fournisseur') },
                  { valeur: 'debit', libelle: t('Le fournisseur nous doit (avoir, acompte versé)') },
              ]
            : [
                  { valeur: 'debit', libelle: t('Le client nous doit') },
                  { valeur: 'credit', libelle: t('Nous devons au client (avoir, acompte reçu)') },
              ];

    const saisie = useMutation({
        mutationFn: async () =>
            (
                await api.post<{ data: SoldeOuvertureSaisi; avertissement: string | null }>(
                    `/tiers/${tiers.id}/solde-ouverture`,
                    { montant: Number(montant), sens, date, compte },
                )
            ).data,
        onSuccess: ({ data: saisi, avertissement }) => {
            // Le solde a bougé partout où il se lit : la liste (?avec_solde),
            // la vue d'ensemble (compte client, crédit, carte du solde
            // d'ouverture) et le relevé, toutes périodes.
            queryClient.invalidateQueries({ queryKey: ['tiers', 'liste'] });
            queryClient.invalidateQueries({ queryKey: ['tiers-vue-ensemble', tiers.id] });
            queryClient.invalidateQueries({ queryKey: ['tiers-releve', tiers.id] });
            queryClient.invalidateQueries({ queryKey: ['tiers-solde-ouverture', tiers.id] });
            onSaisi(saisi, avertissement ?? null);
        },
        onError: (err) => {
            const statut = isAxiosError(err) ? err.response?.status : undefined;
            const message = isAxiosError(err) ? (err.response?.data as { message?: string } | undefined)?.message : undefined;
            // 403 et 422 sont écrits pour l'utilisateur, dans sa langue ; le
            // reste (500, réseau) garde notre texte.
            setErreur((statut === 403 || statut === 422) && message ? message : t("Le solde d'ouverture n'a pas pu être enregistré."));
        },
    });

    const envoyer = (e: FormEvent) => {
        e.preventDefault();
        setErreur(null);
        saisie.mutate();
    };

    const CHAMP =
        'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <Surcouche onFermer={onFermer} etiquettePar={titre}>
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id={titre} className="text-lg font-semibold text-slate-900">
                        {t("Solde d'ouverture")}
                    </h2>
                    <p className="mt-0.5 break-words text-sm text-slate-500">
                        <bdi>{tiers.name}</bdi>
                    </p>
                </div>
                <button
                    type="button"
                    onClick={onFermer}
                    aria-label={t('Fermer')}
                    className="-me-1 -mt-1 inline-flex size-10 shrink-0 items-center justify-center rounded-md text-2xl leading-none text-slate-500 transition hover:bg-slate-100 hover:text-slate-800"
                >
                    <span aria-hidden>×</span>
                </button>
            </div>

            <form onSubmit={envoyer} className="mt-4 space-y-4" aria-describedby={idAide}>
                {comptes.length > 1 && (
                    <fieldset className="min-w-0">
                        <legend className="mb-1 block text-sm font-medium text-slate-700">{t('Compte')}</legend>
                        <div className="flex flex-wrap gap-x-6 gap-y-2">
                            {comptes.map((c) => (
                                <label key={c} className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="compte"
                                        checked={compte === c}
                                        onChange={() => choisirCompte(c)}
                                        className="border-slate-300"
                                    />
                                    {c === 'client' ? t('Compte client') : t('Compte fournisseur')}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                )}

                <fieldset className="min-w-0">
                    <legend className="mb-1 block text-sm font-medium text-slate-700">{t("À la date d'ouverture")}</legend>
                    <div className="space-y-2">
                        {sensPossibles.map((s) => (
                            <label key={s.valeur} className="flex items-start gap-2 text-sm text-slate-700">
                                <input
                                    type="radio"
                                    name="sens"
                                    checked={sens === s.valeur}
                                    onChange={() => setSens(s.valeur)}
                                    className="mt-0.5 border-slate-300"
                                />
                                <span className="min-w-0">{s.libelle}</span>
                            </label>
                        ))}
                    </div>
                </fieldset>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="min-w-0">
                        <label htmlFor={idMontant} className="mb-1 block text-sm font-medium text-slate-700">
                            {t('Montant (DH)')}
                        </label>
                        <input
                            id={idMontant}
                            type="number"
                            inputMode="decimal"
                            required
                            min="0.01"
                            step="0.01"
                            value={montant}
                            onChange={(e) => setMontant(e.target.value)}
                            dir="ltr"
                            aria-describedby={duCompte && duCompte.mouvements > 0 ? idMouvements : undefined}
                            className={`${CHAMP} text-end tabular-nums`}
                        />
                    </div>
                    <div className="min-w-0">
                        <label htmlFor={idDate} className="mb-1 block text-sm font-medium text-slate-700">
                            {t("Date d'ouverture")}
                        </label>
                        <input
                            id={idDate}
                            type="date"
                            required
                            max={aujourdHui()}
                            value={date}
                            onChange={(e) => setDateTapee(e.target.value)}
                            aria-describedby={duCompte && duCompte.mouvements > 0 ? idMouvements : undefined}
                            className={CHAMP}
                        />
                    </div>
                </div>

                {/* Avant l'envoi, pas après : ce que le compte porte déjà, et
                    une date qui le suivrait. Relu à chaque changement de
                    compte ou de date. */}
                {duCompte && duCompte.mouvements > 0 && duCompte.premier && (
                    <div id={idMouvements} className="space-y-1 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-900">
                        <p>
                            {duCompte.mouvements === 1
                                ? t(
                                      "Ce tiers a déjà 1 mouvement à ce compte, le {date}. Le solde d'ouverture s'y ajoute : saisissez ce qu'il devait avant, pas son solde actuel.",
                                      { date: duCompte.premier },
                                  )
                                : t(
                                      "Ce tiers a déjà {n} mouvements à ce compte, le premier le {date}. Le solde d'ouverture s'y ajoute : saisissez ce qu'il devait avant le premier, pas son solde actuel.",
                                      { n: duCompte.mouvements, date: duCompte.premier },
                                  )}
                        </p>
                        {apresPremier && (
                            <p className="font-medium">
                                {t('La date choisie suit le premier mouvement ({date}) : les relevés antérieurs ne montreront pas ce solde.', {
                                    date: duCompte.premier,
                                })}
                            </p>
                        )}
                    </div>
                )}

                <p id={idAide} className="rounded-lg bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600">
                    {t(
                        "Une écriture au journal des opérations diverses, contre un compte d'attente (3497 ou 4497) que le comptable soldera. Un seul solde d'ouverture par tiers.",
                    )}
                </p>

                {erreur && (
                    <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                        {erreur}
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        disabled={saisie.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {saisie.isPending ? t('Enregistrement…') : t('Enregistrer')}
                    </button>
                    <button
                        type="button"
                        onClick={onFermer}
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        {t('Annuler')}
                    </button>
                </div>
            </form>
        </Surcouche>
    );
}
