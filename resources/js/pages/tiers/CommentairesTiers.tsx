import { useEffect, useId, useRef, useState } from 'react';
import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useFormats } from '@/lib/formats-langue';
import { useT } from '@/lib/langue';

type Commentaire = {
    id: number;
    contenu: string;
    /** `id` nul : l'auteur a quitté l'équipe ; `nom` est celui qu'il portait. */
    auteur: { id: number | null; nom: string };
    created_at: string;
    /** La règle du serveur (l'auteur ou l'administrateur, avec le droit d'écrire) : on ne propose que ce qui aboutira. */
    peut_supprimer: boolean;
};

type PageCommentaires = { data: Commentaire[]; meta: { next_cursor: string | null; per_page: number } };

/** La borne du serveur (Commentaire::LONGUEUR_MAX), comptée comme lui : en caractères. */
const LONGUEUR_MAX = 2000;

/** Le compteur ne s'affiche qu'à l'approche de la borne : sous elle, il n'apprend rien. */
const SEUIL_COMPTEUR = 1800;

/**
 * Le BROUILLON d'un commentaire survit au changement d'onglet et de tiers :
 * l'onglet démonte le fil, et une note de trois cents caractères disparaissait
 * sans un mot le temps d'aller vérifier un montant au relevé. Rangé dans le
 * stockage de l'onglet du navigateur, sous une clé par utilisateur ET par
 * tiers — jamais reporté sur un autre tiers, ni lu par le collègue qui se
 * connecte ensuite sur le même poste. Stockage indisponible (navigation
 * privée stricte) : le brouillon vit alors le temps de l'écran, comme avant.
 */
const cleBrouillon = (utilisateur: number | undefined, tiers: number) => `brouillon-commentaire:${utilisateur ?? 0}:${tiers}`;

function lireBrouillon(cle: string): string {
    try {
        return window.sessionStorage.getItem(cle) ?? '';
    } catch {
        return '';
    }
}

function ecrireBrouillon(cle: string, valeur: string): void {
    try {
        if (valeur === '') window.sessionStorage.removeItem(cle);
        else window.sessionStorage.setItem(cle, valeur);
    } catch {
        // Stockage plein ou refusé : le brouillon reste dans l'écran.
    }
}

/** Un refus ou une saisie invalide : le message du serveur, dans la langue de l'écran. Le reste : le nôtre. */
const messageServeur = (err: unknown, defaut: string) => {
    const statut = isAxiosError(err) ? err.response?.status : undefined;
    const message = isAxiosError(err) ? (err.response?.data as { message?: string } | undefined)?.message : undefined;

    return (statut === 403 || statut === 422) && message ? message : defaut;
};

/**
 * Le fil de commentaires d'un tiers : ce que l'équipe doit savoir et qui ne
 * tient dans aucun champ — « ne livre que le matin », « remise promise ».
 *
 * TEXTE BRUT, TOUJOURS. Le contenu est rendu comme un nœud texte de React,
 * jamais comme du HTML : un « <script> » collé dans un commentaire s'affiche
 * tel quel. Les retours à la ligne sont gardés (`whitespace-pre-wrap`), et
 * chaque commentaire suit le sens de SA langue (`dir="auto"`) : une note en
 * français reste lisible sur l'écran arabe, et l'inverse.
 *
 * Le plus récent en haut, la saisie au-dessus de lui ; les plus anciens se
 * chargent à la demande, au curseur du serveur.
 */
export default function CommentairesTiers({ tiersId }: { tiersId: number }) {
    const t = useT();
    const { can, user } = useAuth();
    const { dateHeure, ilYA } = useFormats();
    const queryClient = useQueryClient();
    const peutEcrire = can('tiers', 'write');

    const idTitre = useId();
    const idAide = useId();
    const titre = useRef<HTMLHeadingElement>(null);
    const zoneSaisie = useRef<HTMLTextAreaElement>(null);
    const liste = useRef<HTMLOListElement>(null);
    const boutonPlus = useRef<HTMLButtonElement>(null);

    const fil = useInfiniteQuery({
        queryKey: ['tiers-commentaires', tiersId],
        queryFn: async ({ pageParam }) =>
            (
                await api.get<PageCommentaires>(`/tiers/${tiersId}/commentaires`, {
                    params: { cursor: pageParam ?? undefined },
                })
            ).data,
        initialPageParam: null as string | null,
        getNextPageParam: (derniere) => derniere.meta.next_cursor ?? undefined,
        // Un refus ne changera pas au second essai : seule une panne réseau mérite qu'on réessaie.
        retry: (echecs, err) => (isAxiosError(err) && err.response !== undefined ? false : echecs < 1),
    });

    const commentaires = fil.data?.pages.flatMap((page) => page.data) ?? [];
    const indisponible = fil.isError && fil.data === undefined;

    // Toutes les dates relatives du fil partent du même instant, remis à jour
    // chaque minute : « à l'instant » ne doit pas le rester une heure durant.
    const [maintenant, setMaintenant] = useState(() => Date.now());
    useEffect(() => {
        const minuterie = window.setInterval(() => setMaintenant(Date.now()), 60_000);

        return () => window.clearInterval(minuterie);
    }, []);

    const cle = cleBrouillon(user?.id, tiersId);
    const [saisie, setSaisieEtat] = useState(() => lireBrouillon(cle));
    const setSaisie = (valeur: string) => {
        setSaisieEtat(valeur);
        ecrireBrouillon(cle, valeur);
    };
    const [erreur, setErreur] = useState<string | null>(null);
    const [annonce, setAnnonce] = useState('');
    const longueur = [...saisie].length;
    const vide = saisie.trim() === '';

    // Le fil et le compteur de l'onglet, servi par la synthèse.
    const rafraichir = () => {
        queryClient.invalidateQueries({ queryKey: ['tiers-commentaires', tiersId] });
        queryClient.invalidateQueries({ queryKey: ['tiers-synthese', String(tiersId)] });
    };

    const publication = useMutation({
        mutationFn: async (contenu: string) =>
            (await api.post<{ data: Commentaire }>(`/tiers/${tiersId}/commentaires`, { contenu })).data.data,
        onSuccess: () => {
            setSaisie('');
            setAnnonce(t('Commentaire publié.'));
            rafraichir();
            // Publié par le bouton, le focus y est resté : on le rend au champ,
            // prêt pour la note suivante.
            zoneSaisie.current?.focus();
        },
        onError: (err) => setErreur(messageServeur(err, t("Le commentaire n'a pas pu être publié."))),
    });

    const suppression = useMutation({
        mutationFn: (id: number) => api.delete(`/tiers/${tiersId}/commentaires/${id}`),
        onSuccess: () => {
            setAnnonce(t('Commentaire supprimé.'));
            rafraichir();
        },
        onError: (err) => {
            // Déjà retiré ailleurs (autre onglet, administrateur) : le fil se
            // relit, et il n'y a rien d'autre à dire.
            if (isAxiosError(err) && err.response?.status === 404) {
                rafraichir();

                return;
            }
            setErreur(messageServeur(err, t("Le commentaire n'a pas pu être supprimé.")));
        },
    });

    const publier = () => {
        if (vide || publication.isPending) return;
        setErreur(null);
        setAnnonce('');
        publication.mutate(saisie);
    };

    const supprimer = (c: Commentaire) => {
        setErreur(null);
        setAnnonce('');
        if (!window.confirm(t('Supprimer ce commentaire ?'))) return;

        // Le bouton disparaît avec son commentaire, et le focus avec lui : on
        // le pose sur le champ de saisie, sinon sur le titre du fil.
        (zoneSaisie.current ?? titre.current)?.focus();
        suppression.mutate(c.id);
    };

    // « Afficher les commentaires plus anciens » disparaît avec la dernière
    // page, et le focus qu'il tenait tombait sur <body>. Il va alors au
    // premier commentaire arrivé ; s'il reste des pages, au bouton (désactivé
    // pendant le chargement, il a pu le perdre). Seulement s'il est PERDU :
    // on ne reprend pas un focus que l'utilisateur a posé ailleurs entre-temps.
    const premierNouveau = useRef<number | null>(null);
    useEffect(() => {
        if (premierNouveau.current === null || fil.isFetchingNextPage) return;
        const index = premierNouveau.current;
        premierNouveau.current = null;
        if (document.activeElement !== null && document.activeElement !== document.body) return;

        if (fil.hasNextPage) boutonPlus.current?.focus();
        else (liste.current?.children[index] as HTMLElement | undefined)?.focus();
    }, [fil.isFetchingNextPage, fil.hasNextPage, commentaires.length]);

    // Ctrl + Entrée (⌘ + Entrée sur Mac) publie ; Entrée seule passe à la ligne,
    // comme dans tout champ de plusieurs lignes.
    const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.userAgent);

    return (
        // `relative` : l'annonce `sr-only` est en position absolue, et sans
        // ancêtre positionné elle allongeait la page.
        <section aria-labelledby={idTitre} className="relative space-y-4 rounded-xl bg-white p-5 shadow-sm">
            <h2 id={idTitre} ref={titre} tabIndex={-1} className="font-medium text-slate-900 focus:outline-none">
                {t('Commentaires')}
            </h2>

            {peutEcrire ? (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        publier();
                    }}
                    className="space-y-2"
                >
                    <textarea
                        ref={zoneSaisie}
                        value={saisie}
                        onChange={(e) => {
                            setSaisie(e.target.value);
                            if (erreur) setErreur(null);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                                e.preventDefault();
                                publier();
                            }
                        }}
                        rows={3}
                        maxLength={LONGUEUR_MAX}
                        dir="auto"
                        aria-label={t('Nouveau commentaire')}
                        aria-describedby={idAide}
                        aria-invalid={publication.isError && erreur !== null}
                        placeholder={t("Ce que l'équipe doit savoir sur ce tiers…")}
                        className="block w-full min-w-0 resize-y rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p id={idAide} className="min-w-0 text-xs text-slate-500">
                            {t('{touche} + Entrée pour publier.', { touche: mac ? '⌘' : 'Ctrl' })}
                            {longueur >= SEUIL_COMPTEUR && (
                                <span className={`ms-2 tabular-nums ${longueur >= LONGUEUR_MAX ? 'text-amber-700' : ''}`}>
                                    {t('{n} / {max} caractères', { n: longueur, max: LONGUEUR_MAX })}
                                </span>
                            )}
                        </p>
                        <button
                            type="submit"
                            disabled={vide || publication.isPending}
                            className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {publication.isPending ? t('Publication…') : t('Publier')}
                        </button>
                    </div>
                </form>
            ) : (
                <p className="text-sm text-slate-500">{t("Votre rôle permet de lire les commentaires, pas d'en ajouter.")}</p>
            )}

            {erreur && (
                <p role="alert" className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">
                    {erreur}
                </p>
            )}

            {/* La région polie existe AVANT le message : ajoutée avec lui, elle ne serait pas lue. */}
            <div role="status" aria-live="polite" className="sr-only">
                {annonce}
            </div>

            {fil.isLoading && (
                <p role="status" className="py-4 text-sm text-slate-400">
                    {t('Chargement…')}
                </p>
            )}

            {/* Une erreur n'est PAS un fil vide : « aucun commentaire » ferait
                croire que personne n'a rien noté sur ce client. */}
            {indisponible && (
                <div role="alert" className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    {t('Impossible de charger les commentaires.')}
                    <button
                        type="button"
                        onClick={() => fil.refetch()}
                        disabled={fil.isFetching}
                        className="ms-2 font-medium text-amber-900 underline disabled:opacity-50"
                    >
                        {t('Réessayer')}
                    </button>
                </div>
            )}

            {!fil.isLoading && !indisponible && commentaires.length === 0 && (
                <p className="text-sm text-slate-400">
                    {t("Aucun commentaire pour l'instant. Notez ici ce que l'équipe doit savoir : préférences de livraison, accords, points de vigilance.")}
                </p>
            )}

            {commentaires.length > 0 && (
                <ol ref={liste} className="space-y-3">
                    {commentaires.map((c) => {
                        const absolue = dateHeure(c.created_at);

                        return (
                            // Focalisable par script seulement (tabIndex -1) : la
                            // dernière page chargée y amène le focus.
                            <li
                                key={c.id}
                                tabIndex={-1}
                                className="min-w-0 rounded-lg border border-slate-200 p-3 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                    <div className="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        {/* <bdi> : un nom latin ouvert par un chiffre se
                                            retournait sur l'écran arabe. */}
                                        <span className="text-sm font-medium text-slate-900">
                                            <bdi>{c.auteur.nom}</bdi>
                                        </span>
                                        {c.auteur.id === null && (
                                            <span className="text-xs text-slate-500">{t("(a quitté l'équipe)")}</span>
                                        )}
                                        {/* La date relative se lit d'un coup d'œil ; l'absolue
                                            est là pour qui doit la citer. */}
                                        <time dateTime={c.created_at} title={absolue} className="text-xs text-slate-500">
                                            {ilYA(c.created_at, maintenant) ?? t("à l'instant")}
                                        </time>
                                        <span className="text-xs text-slate-400">
                                            <span aria-hidden>· </span>
                                            {absolue}
                                        </span>
                                    </div>
                                    {c.peut_supprimer && (
                                        <button
                                            type="button"
                                            onClick={() => supprimer(c)}
                                            disabled={suppression.isPending}
                                            aria-label={t('Supprimer le commentaire de {nom} du {date}', {
                                                nom: c.auteur.nom,
                                                date: absolue,
                                            })}
                                            className="shrink-0 text-xs text-slate-500 underline hover:text-red-600 disabled:opacity-50"
                                        >
                                            {t('Supprimer')}
                                        </button>
                                    )}
                                </div>
                                <p dir="auto" className="mt-1.5 whitespace-pre-wrap text-sm text-slate-700 [overflow-wrap:anywhere]">
                                    {c.contenu}
                                </p>
                            </li>
                        );
                    })}
                </ol>
            )}

            {fil.hasNextPage && (
                <button
                    ref={boutonPlus}
                    type="button"
                    onClick={() => {
                        premierNouveau.current = commentaires.length;
                        fil.fetchNextPage();
                    }}
                    disabled={fil.isFetchingNextPage}
                    className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                >
                    {fil.isFetchingNextPage ? t('Chargement…') : t('Afficher les commentaires plus anciens')}
                </button>
            )}

            {/* Une page de plus ratée garde le fil déjà lu, et le dit. */}
            {fil.isFetchNextPageError && (
                <p role="alert" className="text-sm text-amber-800">
                    {t('Impossible de charger les commentaires plus anciens.')}
                </p>
            )}
        </section>
    );
}
