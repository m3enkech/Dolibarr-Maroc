import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { Entrepot, PreRemplissageAchat, ReapproHypotheses, ReapproOrigine, StockAlerte } from '@/types';
import { useT } from '@/lib/langue';

/**
 * D'où sort la quantité conseillée. C'est l'étiquette qui rend le chiffre
 * défendable : sans elle, un grossiste ne peut pas savoir s'il lit une mesure
 * ou un paramètre qu'il a saisi lui-même il y a six mois.
 */
const ORIGINE: Record<ReapproOrigine, { libelle: string; classe: string; aide: string }> = {
    ventes: {
        libelle: 'ventes',
        classe: 'bg-emerald-50 text-emerald-700',
        aide: 'Calculé sur les ventes observées.',
    },
    ventes_court: {
        libelle: 'peu d’historique',
        classe: 'bg-amber-50 text-amber-700',
        aide: 'Calculé sur les ventes, mais sur une période courte : à regarder de près.',
    },
    seuil: {
        libelle: 'seuil',
        classe: 'bg-slate-100 text-slate-600',
        aide: 'Pas assez de ventes pour calculer : on retombe sur le seuil que vous avez saisi.',
    },
    sans_historique: {
        libelle: 'sans historique',
        classe: 'bg-slate-100 text-slate-500',
        aide: 'Ni ventes ni seuil : aucun chiffre n’est proposé.',
    },
};

export default function Alertes({ entrepots }: { entrepots: Entrepot[] }) {
    const t = useT();
    const navigate = useNavigate();
    const { can } = useAuth();
    const [entrepotId, setEntrepotId] = useState('');
    const [selection, setSelection] = useState<number[]>([]);

    const { data, isLoading } = useQuery({
        queryKey: ['stock-alertes', { entrepotId }],
        queryFn: async () => {
            const { data } = await api.get<{ data: StockAlerte[]; hypotheses: ReapproHypotheses }>(
                '/stock/alertes',
                { params: { entrepot_id: entrepotId || undefined } },
            );
            return data;
        },
    });

    const lignes = data?.data ?? [];
    const h = data?.hypotheses;

    // Sans droit d'écriture sur les achats, la colonne de sélection n'a pas
    // lieu d'être : elle ne mènerait qu'à un formulaire interdit.
    const peutCommander = can('achats', 'write');
    const commandable = (a: StockAlerte) => a.suggestion !== null && parseFloat(a.suggestion) > 0;
    const selectionnees = lignes.filter((a) => selection.includes(a.produit_id) && commandable(a));

    const basculer = (produitId: number) =>
        setSelection((s) => (s.includes(produitId) ? s.filter((i) => i !== produitId) : [...s, produitId]));

    /**
     * Une commande par FOURNISSEUR : mélanger deux fournisseurs sur une même
     * pièce n'aurait aucun sens, et laisser l'utilisateur le découvrir au
     * moment d'enregistrer serait pire. Les articles dont on ne connaît pas le
     * fournisseur forment leur propre groupe, à attribuer à la main.
     */
    const groupes = selectionnees.reduce<{ id: number | null; nom: string | null; lignes: StockAlerte[] }[]>(
        (acc, ligne) => {
            const groupe = acc.find((g) => g.id === ligne.fournisseur_id);
            if (groupe) {
                groupe.lignes.push(ligne);
            } else {
                acc.push({ id: ligne.fournisseur_id, nom: ligne.fournisseur_nom, lignes: [ligne] });
            }
            return acc;
        },
        [],
    );

    // Le formulaire d'achat est PRÉ-REMPLI, rien n'est écrit : l'acheteur relit,
    // ajuste les quantités et les prix, puis enregistre lui-même.
    const preparerCommande = (groupe: { id: number | null; lignes: StockAlerte[] }) => {
        const etat: PreRemplissageAchat = {
            fournisseur_id: groupe.id,
            entrepot_id: entrepotId ? Number(entrepotId) : null,
            lignes: groupe.lignes.map((a) => ({
                produit_id: a.produit_id,
                designation: a.name,
                quantite: parseFloat(a.suggestion as string),
                prix_unitaire: a.dernier_prix_achat,
            })),
        };

        navigate('/achats/nouveau?type=commande', { state: etat });
    };

    const input =
        'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <select value={entrepotId} onChange={(e) => setEntrepotId(e.target.value)} className={input}>
                    <option value="">{t('Tous les entrepôts')}</option>
                    {entrepots.map((entrepot) => (
                        <option key={entrepot.id} value={entrepot.id}>
                            {entrepot.name}
                        </option>
                    ))}
                </select>
                {lignes.length > 0 && (
                    <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-700">
                        {lignes.length} {t('produit(s) à réapprovisionner')}
                    </span>
                )}
            </div>

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table className="w-full text-left text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            {peutCommander && <th className="w-10 ps-4 py-3" />}
                            <th className="px-4 py-3">{t('Code')}</th>
                            <th className="px-4 py-3">{t('Produit')}</th>
                            <th className="px-4 py-3 text-right">{t('Stock actuel')}</th>
                            <th className="px-4 py-3 text-right">{t('Ventes / jour')}</th>
                            <th className="px-4 py-3 text-right">{t('Couverture')}</th>
                            <th className="px-4 py-3 text-right">{t('En commande')}</th>
                            <th className="px-4 py-3 text-right">{t('À commander')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {isLoading && (
                            <tr>
                                <td colSpan={peutCommander ? 8 : 7} className="px-4 py-8 text-center text-slate-400">{t('Chargement…')}</td>
                            </tr>
                        )}
                        {!isLoading && lignes.length === 0 && (
                            <tr>
                                <td colSpan={peutCommander ? 8 : 7} className="px-4 py-10 text-center text-slate-400">
                                    {t('✓ Rien à réapprovisionner pour le moment.')}
                                </td>
                            </tr>
                        )}
                        {lignes.map((alerte) => {
                            const suggestion = alerte.suggestion !== null ? parseFloat(alerte.suggestion) : null;
                            const origine = ORIGINE[alerte.origine];
                            const conso = parseFloat(alerte.conso_jour);

                            return (
                                <tr key={alerte.produit_id} className="hover:bg-slate-50">
                                    {peutCommander && (
                                        <td className="ps-4 py-3">
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded border-slate-300 accent-emerald-600 disabled:opacity-30"
                                                checked={selection.includes(alerte.produit_id)}
                                                disabled={!commandable(alerte)}
                                                onChange={() => basculer(alerte.produit_id)}
                                                aria-label={t('Sélectionner {produit}', { produit: alerte.name })}
                                            />
                                        </td>
                                    )}
                                    <td className="px-4 py-3 font-mono text-xs text-slate-600">{alerte.code}</td>
                                    <td className="px-4 py-3">
                                        <div className="font-medium text-slate-900">
                                            {alerte.name}
                                            {alerte.unit && (
                                                <span className="ms-1 text-xs text-slate-400">/ {alerte.unit}</span>
                                            )}
                                        </div>
                                        <span
                                            className={`mt-0.5 inline-block rounded px-1.5 py-0.5 text-[11px] ${origine.classe}`}
                                            title={t(origine.aide)}
                                        >
                                            {t(origine.libelle)}
                                        </span>
                                        {alerte.fournisseur_nom && (
                                            <span className="ms-1 inline-block rounded bg-sky-50 px-1.5 py-0.5 text-[11px] text-sky-700">
                                                {alerte.fournisseur_nom}
                                            </span>
                                        )}
                                        {alerte.jours_rupture > 0 && (
                                            <span
                                                className="ms-1 inline-block rounded bg-red-50 px-1.5 py-0.5 text-[11px] text-red-700"
                                                title={t('Jours de rupture sur la période : ils sont exclus du calcul de la moyenne.')}
                                            >
                                                {t('{n} j de rupture', { n: alerte.jours_rupture })}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right font-semibold tabular-nums text-amber-700">
                                        {parseFloat(alerte.quantite)}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                        {conso > 0 ? conso.toFixed(2) : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums">
                                        {alerte.couverture_restante !== null ? (
                                            <span
                                                className={
                                                    alerte.couverture_restante < 7
                                                        ? 'font-semibold text-red-600'
                                                        : 'text-slate-600'
                                                }
                                            >
                                                {t('{n} j', { n: alerte.couverture_restante })}
                                            </span>
                                        ) : (
                                            <span className="text-slate-400">—</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right tabular-nums text-slate-500">
                                        {parseFloat(alerte.en_commande) > 0 ? (
                                            <span className="rounded bg-sky-50 px-1.5 py-0.5 text-sky-700">
                                                +{parseFloat(alerte.en_commande)}
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                        {suggestion !== null && suggestion > 0 ? (
                                            <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-700">
                                                {suggestion}
                                            </span>
                                        ) : (
                                            <span className="text-slate-400">{t('couvert')}</span>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {/* Le pont vers les achats. Un bouton par fournisseur : c'est ce
                découpage qui évite de fabriquer une commande mélangeant deux
                fournisseurs, erreur qu'on ne verrait qu'au moment d'enregistrer. */}
            {peutCommander && selectionnees.length > 0 && (
                <div className="sticky bottom-4 z-20 rounded-xl border border-emerald-200 bg-white p-4 shadow-lg">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <span className="text-sm text-slate-600">
                            {t('{n} article(s) sélectionné(s)', { n: selectionnees.length })}
                        </span>
                        <button
                            onClick={() => setSelection([])}
                            className="text-xs text-slate-500 hover:text-slate-800 hover:underline"
                        >
                            {t('Tout désélectionner')}
                        </button>
                    </div>

                    <div className="mt-3 flex flex-wrap gap-2">
                        {groupes.map((groupe) => (
                            <button
                                key={groupe.id ?? 'sans-fournisseur'}
                                onClick={() => preparerCommande(groupe)}
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                            >
                                {groupe.nom
                                    ? t('Commander chez {fournisseur} ({n})', {
                                          fournisseur: groupe.nom,
                                          n: groupe.lignes.length,
                                      })
                                    : t('Fournisseur à choisir ({n})', { n: groupe.lignes.length })}
                            </button>
                        ))}
                    </div>

                    <p className="mt-2 text-xs text-slate-500">
                        {t('Le formulaire s’ouvre pré-rempli : rien n’est commandé tant que vous n’avez pas enregistré.')}
                    </p>
                </div>
            )}

            {/* Les hypothèses, à l'écran : un chiffre dont on ignore les
                hypothèses ne se discute pas, donc ne s'utilise pas. */}
            {h && (
                <p className="text-xs text-slate-500">
                    {t(
                        'Quantité conseillée = ventes par jour vendable × {horizon} jours (délai fournisseur {delai} + couverture {couverture} + sécurité {securite}), moins le stock et ce qui est déjà commandé. Les ventes sont observées sur {fenetre} jours, et les jours de rupture sont exclus de la moyenne.',
                        {
                            horizon: h.delai_appro_jours + h.couverture_jours + h.securite_jours,
                            delai: h.delai_appro_jours,
                            couverture: h.couverture_jours,
                            securite: h.securite_jours,
                            fenetre: h.fenetre_jours,
                        },
                    )}
                </p>
            )}
        </div>
    );
}
