import { useEffect, useRef, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useFormats } from '@/lib/formats-langue';
import { useT } from '@/lib/langue';
import { useDebounce } from '@/lib/useDebounce';
import Pagination from '@/components/Pagination';
import { sansParamsFiche, type EtatRetourListe } from '@/pages/tiers/params';
import type { Paginated, Tiers } from '@/types';

/**
 * Assez pour remplir la colonne d'un écran de bureau sans tourner la page à
 * chaque geste ; le solde de toute la page reste UN agrégat côté serveur.
 */
const PAR_PAGE = 50;

const TYPES = ['client', 'prospect', 'fournisseur'];

const CHAMP =
    'w-full min-w-0 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

/**
 * La liste des tiers, en colonne étroite à côté de la fiche — ou seule, sous
 * 1280 px.
 *
 * TOUT SON ÉTAT EST DANS L'URL (recherche, type, actifs, page). C'est ce qui
 * fait tenir le reste : la fiche renvoie vers la liste AVEC ses filtres, un
 * rechargement ne remet pas à zéro la page 7 des fournisseurs, et un lien
 * collé à un collègue montre la même chose. Les liens des lignes portent donc
 * la chaîne de requête courante, et les changements de filtre REMPLACENT
 * l'entrée d'historique : « Précédent » revient d'une fiche à la liste, pas
 * d'une lettre tapée à la précédente.
 *
 * Le montant dû est le solde du grand livre client, SIGNÉ : un client
 * créditeur s'affiche « crédit … », pas « 0,00 » — on le relancerait pour une
 * dette qu'il n'a pas, et on oublierait de le rembourser.
 */
export default function TiersList({ idActif }: { idActif: number | null }) {
    const t = useT();
    const { can } = useAuth();
    const peutEcrire = can('tiers', 'write');
    const location = useLocation();
    const [params, setParams] = useSearchParams();

    const q = params.get('q') ?? '';
    const type = TYPES.includes(params.get('type') ?? '') ? (params.get('type') as string) : '';
    const actif = params.get('actif') === '1' || params.get('actif') === '0' ? (params.get('actif') as string) : '';
    const page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
    const filtre = q !== '' || type !== '' || actif !== '';

    const majParams = (changements: Record<string, string | null>) => {
        setParams(
            (courants) => {
                const suivants = new URLSearchParams(courants);
                for (const [cle, valeur] of Object.entries(changements)) {
                    if (valeur === null || valeur === '') suivants.delete(cle);
                    else suivants.set(cle, valeur);
                }

                return suivants;
            },
            { replace: true },
        );
    };

    // Le champ répond à chaque frappe ; l'URL — donc la requête — attend une
    // pause de 250 ms. `ecrite` retient la dernière valeur que NOUS avons
    // posée dans l'URL : un changement qui n'en vient pas (Précédent, lien
    // partagé) doit réécrire le champ, et le nôtre surtout pas — il effacerait
    // les lettres tapées entre-temps.
    const [saisie, setSaisie] = useState(q);
    const saisieRetardee = useDebounce(saisie, 250);
    const ecrite = useRef(q);

    useEffect(() => {
        if (q !== ecrite.current) {
            ecrite.current = q;
            setSaisie(q);
        }
    }, [q]);

    useEffect(() => {
        const terme = saisieRetardee.trim();
        if (terme === ecrite.current) return;

        ecrite.current = terme;
        majParams({ q: terme, page: null });
        // majParams change à chaque rendu ; seul le terme retardé déclenche.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [saisieRetardee]);

    const { data, isLoading, isError, error, isPlaceholderData, refetch, isFetching } = useQuery({
        queryKey: ['tiers', 'liste', { q, type, actif, page }],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tiers>>('/tiers', {
                params: {
                    search: q || undefined,
                    type: type || undefined,
                    actif: actif || undefined,
                    page,
                    per_page: PAR_PAGE,
                    avec_solde: 1,
                },
            });
            return data;
        },
        placeholderData: keepPreviousData,
        // Un refus ne changera pas au second essai ; seule une panne réseau
        // mérite qu'on réessaie.
        retry: (echecs, err) => (isAxiosError(err) && err.response !== undefined ? false : echecs < 1),
    });

    const refuse = isError && isAxiosError(error) && error.response?.status === 403;
    // L'erreur ne masque la liste que s'il n'y a rien à montrer : un
    // rafraîchissement raté (après une suppression, au retour du réseau) garde
    // la page déjà lue et le signale par un bandeau.
    const erreurBloquante = isError && data === undefined;

    // Une page qui n'existe plus — la dernière s'est vidée par une suppression,
    // ou un lien ancien vise la page 20 de 9. Laravel répond une liste vide
    // AVEC le total : sans ce garde, « Aucun tiers » s'affichait au-dessus de
    // « 450 résultats », et la pagination ne menait qu'à d'autres pages vides.
    // On ramène l'URL à la dernière page réelle, en remplaçant l'entrée.
    const horsBornes =
        data !== undefined &&
        !isPlaceholderData &&
        data.data.length === 0 &&
        page > 1 &&
        data.meta.current_page > data.meta.last_page;
    const dernierePage = data?.meta.last_page ?? 1;
    useEffect(() => {
        if (horsBornes) majParams({ page: dernierePage > 1 ? String(dernierePage) : null });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [horsBornes, dernierePage]);

    // Ce qu'un lecteur d'écran entend une fois la liste rechargée : sans quoi
    // une recherche ne dit rien de son résultat tant qu'on ne quitte pas le
    // champ. Vide pendant le chargement, pour être relu à chaque recherche.
    const annonce =
        isFetching || !data || erreurBloquante || horsBornes
            ? ''
            : data.meta.total === 0
              ? filtre
                  ? t('Aucun tiers ne correspond à ces critères.')
                  : t('Aucun tiers.')
              : data.meta.total === 1
                ? t('1 tiers trouvé')
                : t('{n} tiers trouvés', { n: data.meta.total.toLocaleString('fr-MA') });

    // Changer de page ramène en haut de la liste. Deux défilements à remettre :
    // au bureau, celui de la zone des lignes — l'en-tête est HORS d'elle, le
    // viser seul la laissait en bas ; sur téléphone, celui de la page, que
    // scrollIntoView sur l'en-tête prend en charge.
    const tete = useRef<HTMLDivElement>(null);
    const zone = useRef<HTMLDivElement>(null);
    const pagePrecedente = useRef(page);
    useEffect(() => {
        if (page !== pagePrecedente.current) {
            pagePrecedente.current = page;
            zone.current?.scrollTo({ top: 0 });
            tete.current?.scrollIntoView({ block: 'nearest' });
        }
    }, [page]);

    // Un lien profond ouvre une fiche au milieu de la liste : on amène sa
    // ligne en vue, sans faire défiler plus que nécessaire. UNE fois par tiers,
    // dès que sa ligne existe — pas à chaque rafraîchissement des données, qui
    // ramènerait de force la liste qu'on est en train de parcourir.
    const liste = useRef<HTMLUListElement>(null);
    const amene = useRef<number | null>(null);
    useEffect(() => {
        if (idActif === null || amene.current === idActif) return;

        const ligne = liste.current?.querySelector('[aria-current="page"]');
        if (ligne) {
            ligne.scrollIntoView({ block: 'nearest' });
            amene.current = idActif;
        }
    }, [idActif, data]);

    // Retour d'une fiche par « Tous les tiers » (sous 1280 px, où la liste
    // était masquée) : le focus est tombé sur <body> avec elle. On le rend à
    // la ligne qu'on venait d'ouvrir — une fois par navigation, pas à chaque
    // rafraîchissement des données.
    const etat = location.state as EtatRetourListe | null;
    const depuis = etat?.depuis;
    const focusRendu = useRef<string | null>(null);
    useEffect(() => {
        if (depuis === undefined || focusRendu.current === location.key) return;

        const ligne = liste.current?.querySelector<HTMLElement>(`[data-tiers="${depuis}"]`);
        if (ligne) {
            focusRendu.current = location.key;
            ligne.focus({ preventScroll: true });
            ligne.scrollIntoView({ block: 'nearest' });
        }
    }, [depuis, location.key, data]);

    // Retour d'une fiche SUPPRIMÉE : elle a emporté le focus qu'elle tenait
    // (le bouton « Plus »), et rien ne disait que c'était fait. Le compte
    // rendu arrive par l'état de navigation ; le focus va au titre de la
    // liste, juste au-dessus — mais seulement s'il est PERDU : si la fiche
    // d'un autre tiers a été ouverte entre-temps, on n'y touche pas.
    const supprime = etat?.supprime;
    const titre = useRef<HTMLHeadingElement>(null);
    const suppressionDite = useRef<string | null>(null);
    useEffect(() => {
        if (supprime === undefined || suppressionDite.current === location.key) return;

        suppressionDite.current = location.key;
        if (document.activeElement === null || document.activeElement === document.body) {
            titre.current?.focus();
        }
    }, [supprime, location.key]);

    return (
        <div className="flex min-h-0 flex-1 flex-col rounded-xl bg-white shadow-sm">
            <div ref={tete} className="space-y-3 border-b border-slate-200 p-4">
                <div>
                    <div className="flex items-center justify-between gap-3">
                        <div className="min-w-0">
                            <h1 ref={titre} tabIndex={-1} className="text-xl font-semibold text-slate-900 focus:outline-none">
                                {t('Tiers')}
                            </h1>
                            <p className="truncate text-sm text-slate-500">{t('Clients et fournisseurs')}</p>
                        </div>
                        {/* Rien à proposer à un rôle en lecture : le formulaire se
                            remplirait, l'enregistrement finirait en refus. */}
                        {peutEcrire && (
                            <Link
                                to={{ pathname: '/tiers/nouveau', search: sansParamsFiche(location.search) }}
                                className="shrink-0 rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                            >
                                {t('+ Nouveau tiers')}
                            </Link>
                        )}
                    </div>

                    {/* La région polie existe AVANT le message — ajoutée avec lui,
                        elle ne serait pas lue. Le message vit le temps de cette
                        entrée d'historique : le premier filtre ou la première
                        fiche ouverte l'effacent. */}
                    <div role="status" aria-live="polite">
                        {supprime && (
                            <p className="mt-3 break-words rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                                {t('« {nom} » ({code}) a été supprimé.', { nom: supprime.nom, code: supprime.code })}
                            </p>
                        )}
                    </div>
                </div>

                <input
                    type="search"
                    value={saisie}
                    onChange={(e) => setSaisie(e.target.value)}
                    placeholder={t('Rechercher par nom, code ou ICE…')}
                    aria-label={t('Rechercher un tiers')}
                    className={CHAMP}
                />

                <div className="grid grid-cols-2 gap-2">
                    <select
                        value={type}
                        onChange={(e) => majParams({ type: e.target.value, page: null })}
                        aria-label={t('Type de tiers')}
                        className={CHAMP}
                    >
                        <option value="">{t('Tous')}</option>
                        <option value="client">{t('Clients')}</option>
                        <option value="prospect">{t('Prospects')}</option>
                        <option value="fournisseur">{t('Fournisseurs')}</option>
                    </select>
                    <select
                        value={actif}
                        onChange={(e) => majParams({ actif: e.target.value, page: null })}
                        aria-label={t('Statut')}
                        className={CHAMP}
                    >
                        <option value="">{t('Actifs et inactifs')}</option>
                        <option value="1">{t('Actifs')}</option>
                        <option value="0">{t('Inactifs')}</option>
                    </select>
                </div>
            </div>

            {/* La seule zone qui défile : en-tête et pagination restent en place.
                `relative` n'est pas décoratif : les libellés `sr-only` des
                montants sont en position absolue, et un conteneur qui défile
                sans être positionné ne les retient pas — ceux des lignes du
                bas allongeaient la PAGE jusqu'à 3 000 px, qui défilait alors
                sous la liste censée être seule à bouger. */}
            <div ref={zone} className="relative min-h-0 flex-1 xl:overflow-y-auto" aria-busy={isFetching}>
                {isLoading && <p className="px-4 py-8 text-center text-sm text-slate-400">{t('Chargement…')}</p>}

                {/* Une erreur n'est PAS une liste vide : « aucun tiers » ferait
                    croire à un carnet d'adresses effacé. */}
                {isError && (
                    <div role="alert" className="m-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        {refuse
                            ? t("Votre rôle ne donne pas accès à la liste des tiers.")
                            : erreurBloquante
                              ? t('Impossible de charger la liste des tiers.')
                              : t("La liste n'a pas pu être actualisée.")}
                        {!refuse && (
                            <button
                                type="button"
                                onClick={() => refetch()}
                                disabled={isFetching}
                                className="ms-2 font-medium text-amber-900 underline disabled:opacity-50"
                            >
                                {t('Réessayer')}
                            </button>
                        )}
                    </div>
                )}

                {!isLoading && !erreurBloquante && !horsBornes && data?.data.length === 0 && (
                    <div className="px-4 py-8 text-center text-sm text-slate-400">
                        {filtre ? (
                            <>
                                <p>{t('Aucun tiers ne correspond à ces critères.')}</p>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSaisie('');
                                        ecrite.current = '';
                                        majParams({ q: null, type: null, actif: null, page: null });
                                    }}
                                    className="mt-2 font-medium text-emerald-700 hover:underline"
                                >
                                    {t('Effacer les filtres')}
                                </button>
                            </>
                        ) : (
                            <p>{peutEcrire ? t('Aucun tiers. Créez le premier !') : t('Aucun tiers.')}</p>
                        )}
                    </div>
                )}

                {data && data.data.length > 0 && (
                    <ul
                        ref={liste}
                        className={`divide-y divide-slate-100 transition-opacity ${isPlaceholderData ? 'opacity-60' : ''}`}
                    >
                        {data.data.map((tiers) => {
                            const courant = tiers.id === idActif;

                            return (
                                <li key={tiers.id}>
                                    <Link
                                        to={{ pathname: `/tiers/${tiers.id}`, search: location.search }}
                                        data-tiers={tiers.id}
                                        aria-current={courant ? 'page' : undefined}
                                        className={`flex items-start justify-between gap-3 border-s-4 px-4 py-2.5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-500 ${
                                            courant
                                                ? 'border-emerald-600 bg-emerald-50'
                                                : 'border-transparent hover:bg-slate-50'
                                        }`}
                                    >
                                        <span className="min-w-0">
                                            {/* Le nom suit SA langue, pas celle de l'écran
                                                (`dir="auto"`). En arabe, un nom latin ouvert
                                                par un chiffre ou un signe se lisait à
                                                l'envers — « SEAS … 7 », « 212…+ » — et un nom
                                                trop long perdait son DÉBUT (« …TRANS
                                                INTERNATIONAL »). `w-fit` ramène la boîte à la
                                                largeur du nom : elle reste calée au début de
                                                la ligne, côté écran, et les points de
                                                suspension tombent à la fin du nom. */}
                                            <span
                                                dir="auto"
                                                className="block w-fit max-w-full truncate text-sm font-medium text-slate-900"
                                            >
                                                {tiers.name}
                                            </span>
                                            <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                                                <span className="font-mono">{tiers.code}</span>
                                                {tiers.is_prospect && (
                                                    <span className="rounded bg-amber-100 px-1.5 text-amber-700">
                                                        {t('Prospect')}
                                                    </span>
                                                )}
                                                {tiers.is_supplier && (
                                                    <span className="rounded bg-sky-100 px-1.5 text-sky-700">
                                                        {t('Fournisseur')}
                                                    </span>
                                                )}
                                                {!tiers.is_active && (
                                                    <span className="rounded bg-slate-200 px-1.5 text-slate-600">
                                                        {t('inactif')}
                                                    </span>
                                                )}
                                            </span>
                                        </span>
                                        <MontantDu solde={tiers.solde} />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>

            {data && !horsBornes && (
                <Pagination meta={data.meta} onPage={(p) => majParams({ page: p > 1 ? String(p) : null })} compacte />
            )}

            {/* HORS de la zone `aria-busy` : un lecteur d'écran y tairait tout. */}
            <div role="status" aria-live="polite" className="sr-only">
                {annonce}
            </div>
        </div>
    );
}

/**
 * Ce que le client doit, ou ce qu'on lui doit. Rien pour un fournisseur pur
 * ou un prospect qui n'a jamais rien acheté : « 0,00 » y laisserait croire
 * qu'on a vérifié un compte qui n'existe pas.
 */
function MontantDu({ solde }: { solde?: string | null }) {
    const t = useT();
    // Dans la langue de l'écran, comme la fiche à côté : le même solde ne
    // doit pas s'écrire « MAD » dans la liste et « د.م. » dans la fiche.
    const { montant: formater } = useFormats();

    if (solde === null || solde === undefined) return null;

    const montant = Number(solde);

    if (montant < 0) {
        return (
            <span className="shrink-0 text-end text-xs font-medium tabular-nums text-sky-700">
                {t('crédit {montant}', { montant: formater(-montant) })}
            </span>
        );
    }

    // Le zéro en slate-500, pas 400 : « à jour » est une information, et le
    // 400 tombait à 2,5:1 de contraste sur la ligne active.
    return (
        <span
            className={`shrink-0 text-end text-sm tabular-nums ${
                montant > 0 ? 'font-medium text-slate-900' : 'text-slate-500'
            }`}
        >
            <span className="sr-only">{t('Montant dû')} </span>
            {formater(montant)}
        </span>
    );
}
