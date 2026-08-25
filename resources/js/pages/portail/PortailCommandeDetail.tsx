import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { formatMAD } from '@/lib/format';
import { portailApi } from '@/lib/portail-api';
import { useT } from '@/lib/langue';
import { ETAT_COMMANDE } from '@/pages/portail/PortailCommandes';

interface LigneCommande {
    designation: string;
    quantite: number;
    conditionnement: string | null;
    quantite_colis: number | null;
    prix_unitaire: string;
    montant_ttc: string;
    quantite_livree: number;
    reste_a_livrer: number;
}

interface CommandeDetail {
    id: number;
    code: string;
    date: string | null;
    etat: string;
    livraison: string;
    total_ht: string;
    total_ttc: string;
    notes: string | null;
    lignes: LigneCommande[];
}

export default function PortailCommandeDetail() {
    const { grossiste, id } = useParams();
    const t = useT();

    const { data, isLoading } = useQuery({
        queryKey: ['portail-commande', grossiste, id],
        queryFn: async () =>
            (await portailApi.get<{ data: CommandeDetail }>(`/grossistes/${grossiste}/commandes/${id}`)).data.data,
    });

    if (isLoading || !data) return <p className="text-sm text-slate-400">{t('Chargement…')}</p>;

    const etat = ETAT_COMMANDE[data.etat] ?? { libelle: data.etat, classe: 'bg-slate-200 text-slate-600' };
    const enCoursDeLivraison = data.lignes.some((l) => l.reste_a_livrer > 0 && l.quantite_livree > 0);

    return (
        <div className="space-y-4">
            <div>
                <Link to={`/portail/${grossiste}/commandes`} className="text-xs text-slate-500 hover:text-emerald-600">
                    {t('← Mes commandes')}
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    <h1 className="font-mono text-lg font-semibold text-slate-900">{data.code}</h1>
                    <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${etat.classe}`}>
                        {t(etat.libelle)}
                    </span>
                </div>
                <p className="mt-0.5 text-sm text-slate-500">{t('Passée le {date}', { date: data.date ?? '' })}</p>
            </div>

            {data.etat === 'en_attente_confirmation' && (
                <div className="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {t('Votre commande a bien été transmise. Le grossiste va la confirmer, et les montants définitifs vous seront communiqués à ce moment-là.')}
                </div>
            )}

            {enCoursDeLivraison && (
                <div className="rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    {t('Une partie de votre commande a été livrée. Le reste vous sera livré prochainement.')}
                </div>
            )}

            <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                <table className="w-full text-start text-sm">
                    <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th className="px-4 py-2.5">{t('Article')}</th>
                            <th className="px-4 py-2.5 text-end">{t('Commandé')}</th>
                            <th className="px-4 py-2.5 text-end">{t('Livré')}</th>
                            <th className="px-4 py-2.5 text-end">{t('Total')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {data.lignes.map((l, i) => (
                            <tr key={i}>
                                <td className="px-4 py-3">
                                    <span className="text-slate-800">{l.designation}</span>
                                    <span className="block text-xs text-slate-400">
                                        {t("{prix} l'unité", { prix: formatMAD(l.prix_unitaire) })}
                                        {l.conditionnement && ` · ${l.quantite_colis} × ${l.conditionnement}`}
                                    </span>
                                </td>
                                <td className="px-4 py-3 text-end tabular-nums text-slate-600">{l.quantite}</td>
                                <td className="px-4 py-3 text-end tabular-nums">
                                    <span className={l.reste_a_livrer > 0 ? 'text-amber-600' : 'text-emerald-600'}>
                                        {l.quantite_livree}
                                    </span>
                                    {l.reste_a_livrer > 0 && (
                                        <span className="block text-xs text-slate-400">
                                            {t('reste {n}', { n: l.reste_a_livrer })}
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 text-end font-medium tabular-nums text-slate-800">
                                    {formatMAD(l.montant_ttc)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t-2 border-slate-200 bg-slate-50">
                        <tr>
                            <td colSpan={3} className="px-4 py-3 text-end font-medium text-slate-700">{t('Total TTC')}</td>
                            <td className="px-4 py-3 text-end text-base font-semibold tabular-nums text-slate-900">
                                {formatMAD(data.total_ttc)}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            {data.notes && (
                <div className="rounded-xl bg-white p-4 shadow-sm">
                    <h2 className="text-sm font-medium text-slate-900">{t('Votre note')}</h2>
                    <p className="mt-1 whitespace-pre-wrap text-sm text-slate-600">{data.notes}</p>
                </div>
            )}
        </div>
    );
}
