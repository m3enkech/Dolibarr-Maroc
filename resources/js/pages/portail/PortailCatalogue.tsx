import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { formatMAD } from '@/lib/format';
import { messageErreur, portailApi } from '@/lib/portail-api';
import { useT } from '@/lib/langue';

interface Palier {
    quantite_min: number;
    prix: string;
}

interface ArticlePortail {
    id: number;
    code: string;
    name: string;
    unit: string | null;
    prix_ht: string;
    prix_ttc: string;
    tva_rate: string;
    paliers: Palier[];
    conditionnements: { id: number; nom: string; quantite_base: number }[];
    disponible: boolean;
}

interface LignePanier {
    produit: ArticlePortail;
    quantite: number;
    /** Colis choisi ; null = vente à l'unité. */
    conditionnementId: number | null;
}

/** Prix applicable à cette quantité, paliers dégressifs compris. */
function prixSelonQuantite(article: ArticlePortail, quantiteUnites: number): number {
    const atteint = article.paliers
        .filter((p) => quantiteUnites >= p.quantite_min)
        .sort((a, b) => b.quantite_min - a.quantite_min)[0];

    return atteint ? parseFloat(atteint.prix) : parseFloat(article.prix_ht);
}

export default function PortailCatalogue() {
    const { grossiste } = useParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const t = useT();

    const [recherche, setRecherche] = useState('');
    const [panier, setPanier] = useState<LignePanier[]>([]);
    const [erreur, setErreur] = useState<string | null>(null);
    const [note, setNote] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['portail-catalogue', grossiste, recherche],
        queryFn: async () =>
            (await portailApi.get<{ data: ArticlePortail[]; meta: { total: number } }>(
                `/grossistes/${grossiste}/catalogue`,
                { params: { search: recherche || undefined } },
            )).data,
    });

    const commander = useMutation({
        mutationFn: () =>
            portailApi.post(`/grossistes/${grossiste}/commandes`, {
                lignes: panier.map((l) =>
                    l.conditionnementId !== null
                        ? { produit_id: l.produit.id, conditionnement_id: l.conditionnementId, quantite_colis: l.quantite }
                        : { produit_id: l.produit.id, quantite: l.quantite },
                ),
                note: note || undefined,
            }),
        onSuccess: ({ data }) => {
            setPanier([]);
            setNote('');
            queryClient.invalidateQueries({ queryKey: ['portail-commandes', grossiste] });
            navigate(`/portail/${grossiste}/commandes/${data.data.id}`);
        },
        onError: (err) => setErreur(messageErreur(err, t('Commande impossible.'))),
    });

    /** Nombre d'unités que représente une ligne (colis convertis). */
    const unites = (l: LignePanier): number => {
        if (l.conditionnementId === null) return l.quantite;
        const c = l.produit.conditionnements.find((x) => x.id === l.conditionnementId);
        return l.quantite * (c?.quantite_base ?? 1);
    };

    const totaux = useMemo(() => {
        let ht = 0;
        let tva = 0;
        for (const l of panier) {
            const q = unites(l);
            const ligneHt = Math.round(q * prixSelonQuantite(l.produit, q) * 100) / 100;
            ht += ligneHt;
            tva += Math.round((ligneHt * parseFloat(l.produit.tva_rate)) / 100 * 100) / 100;
        }
        return { ht, tva, ttc: Math.round((ht + tva) * 100) / 100 };
    }, [panier]);

    const ajouter = (produit: ArticlePortail, conditionnementId: number | null) => {
        setErreur(null);
        setPanier((lignes) => {
            const existante = lignes.find(
                (l) => l.produit.id === produit.id && l.conditionnementId === conditionnementId,
            );
            if (existante) {
                return lignes.map((l) => (l === existante ? { ...l, quantite: l.quantite + 1 } : l));
            }
            return [...lignes, { produit, quantite: 1, conditionnementId }];
        });
    };

    const changerQuantite = (index: number, delta: number) =>
        setPanier((lignes) =>
            lignes
                .map((l, i) => (i === index ? { ...l, quantite: l.quantite + delta } : l))
                .filter((l) => l.quantite > 0),
        );

    const champ =
        'w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            {/* Catalogue */}
            <section className="lg:col-span-2">
                <input
                    value={recherche}
                    onChange={(e) => setRecherche(e.target.value)}
                    placeholder={t('Rechercher un article…')}
                    className={champ}
                />

                {isLoading && <p className="mt-4 text-sm text-slate-400">{t('Chargement du catalogue…')}</p>}

                <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    {(data?.data ?? []).map((article) => (
                        <article key={article.id} className="rounded-xl bg-white p-4 shadow-sm">
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <h3 className="truncate font-medium text-slate-900">{article.name}</h3>
                                    <p className="font-mono text-[11px] text-slate-400">{article.code}</p>
                                </div>
                                {!article.disponible && (
                                    <span className="shrink-0 rounded-full bg-slate-200 px-2 py-0.5 text-[11px] text-slate-600">
                                        {t('sur commande')}
                                    </span>
                                )}
                            </div>

                            <div className="mt-2 flex items-baseline gap-1.5">
                                <span className="text-lg font-semibold text-slate-900">{formatMAD(article.prix_ht)}</span>
                                <span className="text-xs text-slate-500">
                                    {t('HT / {unite}', { unite: article.unit ?? t('unité') })}
                                </span>
                            </div>

                            {article.paliers.length > 1 && (
                                <ul className="mt-1.5 space-y-0.5">
                                    {article.paliers.slice(1).map((p) => (
                                        <li key={p.quantite_min} className="text-xs text-emerald-700">
                                            {t('dès {q} → {prix} HT', { q: p.quantite_min, prix: formatMAD(p.prix) })}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <div className="mt-3 flex flex-wrap gap-2">
                                <button
                                    onClick={() => ajouter(article, null)}
                                    className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-emerald-700"
                                >
                                    {t("+ À l'unité")}
                                </button>
                                {article.conditionnements.map((c) => (
                                    <button
                                        key={c.id}
                                        onClick={() => ajouter(article, c.id)}
                                        className="rounded-lg border border-emerald-300 px-3 py-1.5 text-xs font-medium text-emerald-700 transition hover:bg-emerald-50"
                                    >
                                        + {c.nom}
                                    </button>
                                ))}
                            </div>
                        </article>
                    ))}
                </div>

                {!isLoading && (data?.data ?? []).length === 0 && (
                    <p className="mt-6 text-center text-sm text-slate-400">{t('Aucun article ne correspond.')}</p>
                )}
            </section>

            {/* Panier */}
            <aside className="lg:sticky lg:top-32 lg:self-start">
                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="font-medium text-slate-900">{t('Ma commande')}</h2>

                    {panier.length === 0 && (
                        <p className="mt-3 text-sm text-slate-400">
                            {t('Ajoutez des articles pour composer votre commande.')}
                        </p>
                    )}

                    <ul className="mt-3 space-y-3">
                        {panier.map((l, i) => {
                            const q = unites(l);
                            const pu = prixSelonQuantite(l.produit, q);
                            const colis = l.produit.conditionnements.find((c) => c.id === l.conditionnementId);

                            return (
                                <li key={`${l.produit.id}-${l.conditionnementId}`} className="border-b border-slate-100 pb-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="text-sm text-slate-800">{l.produit.name}</span>
                                        <span className="shrink-0 text-sm font-medium tabular-nums text-slate-900">
                                            {formatMAD(Math.round(q * pu * 100) / 100)}
                                        </span>
                                    </div>
                                    <div className="mt-1 flex items-center justify-between">
                                        <div className="flex items-center gap-1">
                                            <button
                                                onClick={() => changerQuantite(i, -1)}
                                                className="h-7 w-7 rounded border border-slate-300 text-slate-600"
                                            >
                                                −
                                            </button>
                                            <span className="w-10 text-center text-sm tabular-nums">{l.quantite}</span>
                                            <button
                                                onClick={() => changerQuantite(i, 1)}
                                                className="h-7 w-7 rounded border border-emerald-300 text-emerald-700"
                                            >
                                                +
                                            </button>
                                        </div>
                                        <span className="text-xs text-slate-500">
                                            {colis ? `${colis.nom} — ${q} ${l.produit.unit ?? 'u.'}` : `${formatMAD(pu)} / ${l.produit.unit ?? 'u.'}`}
                                        </span>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    {panier.length > 0 && (
                        <>
                            <div className="mt-4 space-y-1 border-t border-slate-200 pt-3 text-sm tabular-nums">
                                <div className="flex justify-between text-slate-500">
                                    <span>{t('Total HT')}</span>
                                    <span>{formatMAD(totaux.ht)}</span>
                                </div>
                                <div className="flex justify-between text-slate-500">
                                    <span>{t('TVA')}</span>
                                    <span>{formatMAD(totaux.tva)}</span>
                                </div>
                                <div className="flex justify-between pt-1 text-base font-semibold text-slate-900">
                                    <span>{t('Total TTC')}</span>
                                    <span>{formatMAD(totaux.ttc)}</span>
                                </div>
                            </div>

                            <textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                placeholder={t('Précision pour le grossiste (facultatif)')}
                                rows={2}
                                className={`mt-3 ${champ}`}
                            />

                            {erreur && (
                                <div className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{erreur}</div>
                            )}

                            <button
                                onClick={() => commander.mutate()}
                                disabled={commander.isPending}
                                className="mt-3 w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                            >
                                {commander.isPending ? t('Envoi…') : t('Envoyer la commande')}
                            </button>
                            <p className="mt-2 text-center text-xs text-slate-400">
                                {t('Le montant définitif est confirmé par le grossiste.')}
                            </p>
                        </>
                    )}
                </div>
            </aside>
        </div>
    );
}
