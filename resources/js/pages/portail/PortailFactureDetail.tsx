import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { formatMAD, formatTva } from '@/lib/format';
import { portailApi } from '@/lib/portail-api';
import { etatFacture, type FactureResume } from '@/pages/portail/PortailFactures';

interface LigneFacture {
    designation: string;
    quantite: number;
    prix_unitaire: string;
    tva_rate: number;
    montant_ht: string;
    montant_ttc: string;
}

interface Reglement {
    date: string | null;
    montant: string;
    mode: string;
    reference: string | null;
}

interface FactureDetail extends FactureResume {
    notes: string | null;
    origine: { code: string; type: string } | null;
    total_ht: string;
    total_tva: string;
    lignes: LigneFacture[];
    paiements: Reglement[];
}

const MODE: Record<string, string> = {
    especes: 'Espèces',
    cheque: 'Chèque',
    virement: 'Virement',
    carte: 'Carte',
    autre: 'Autre',
};

export default function PortailFactureDetail() {
    const { grossiste, id } = useParams();
    const [erreurPdf, setErreurPdf] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['portail-facture', grossiste, id],
        queryFn: async () =>
            (await portailApi.get<{ data: FactureDetail }>(`/grossistes/${grossiste}/factures/${id}`)).data.data,
    });

    /**
     * Le PDF passe par le client authentifié, pas par un lien direct : la route
     * exige le jeton, qu'un <a href> n'enverrait pas.
     */
    const telecharger = async () => {
        try {
            setErreurPdf(null);
            const reponse = await portailApi.get(`/grossistes/${grossiste}/factures/${id}/pdf`, {
                responseType: 'blob',
            });
            const url = URL.createObjectURL(reponse.data as Blob);
            const lien = document.createElement('a');
            lien.href = url;
            lien.download = `${data?.code ?? 'facture'}.pdf`;
            lien.click();
            URL.revokeObjectURL(url);
        } catch {
            setErreurPdf('Le téléchargement a échoué. Réessayez dans un instant.');
        }
    };

    if (isLoading || !data) return <p className="text-sm text-slate-400">Chargement…</p>;

    const etat = etatFacture(data.etat);
    const avoir = data.type === 'avoir';
    const reste = parseFloat(data.reste_a_payer);
    // Sous traite, la somme reste inscrite sur la facture mais n'est plus
    // réclamée : l'afficher en rouge contredirait le bandeau juste au-dessus.
    const duMaintenant = reste > 0 && !avoir && data.etat !== 'traite_en_cours';

    return (
        <div className="space-y-4">
            <div>
                <Link to={`/portail/${grossiste}/factures`} className="text-xs text-slate-500 hover:text-emerald-600">
                    ← Mes factures
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    <h1 className="font-mono text-lg font-semibold text-slate-900">{data.code}</h1>
                    <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${etat.classe}`}>
                        {etat.libelle}
                    </span>
                </div>
                <p className="mt-0.5 text-sm text-slate-500">
                    {avoir ? 'Avoir émis le' : 'Facture du'} {data.date}
                    {!avoir && data.echeance && ` · à régler avant le ${data.echeance}`}
                    {data.origine && ` · suite à ${data.origine.code}`}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <button
                    onClick={telecharger}
                    className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-800"
                >
                    ⬇ Télécharger le PDF
                </button>
                {erreurPdf && <span className="text-sm text-red-600">{erreurPdf}</span>}
            </div>

            {data.etat === 'en_retard' && (
                <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                    Cette facture est échue depuis {data.jours_retard} jour{data.jours_retard > 1 ? 's' : ''}. Il reste{' '}
                    <strong>{formatMAD(data.reste_a_payer)}</strong> à régler.
                </div>
            )}

            {data.etat === 'traite_en_cours' && (
                <div className="rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                    Une traite couvre cette facture : rien à régler d'ici son échéance.
                </div>
            )}

            <section className="overflow-hidden rounded-xl bg-white shadow-sm">
                {/* Seul le TABLEAU défile : englober les totaux les enverrait
                    hors écran avec lui, alors qu'ils tiennent en largeur. */}
                <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-slate-100 text-left text-xs uppercase text-slate-400">
                            <th className="px-4 py-2">Désignation</th>
                            <th className="px-4 py-2 text-right">Qté</th>
                            <th className="px-4 py-2 text-right">P.U. HT</th>
                            <th className="px-4 py-2 text-right">TVA</th>
                            <th className="px-4 py-2 text-right">Total TTC</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-50">
                        {data.lignes.map((l, i) => (
                            <tr key={i}>
                                <td className="px-4 py-3 text-slate-800">{l.designation}</td>
                                <td className="px-4 py-3 text-right tabular-nums text-slate-600">{l.quantite}</td>
                                <td className="px-4 py-3 text-right tabular-nums text-slate-600">
                                    {formatMAD(l.prix_unitaire)}
                                </td>
                                <td className="px-4 py-3 text-right text-slate-500">{formatTva(l.tva_rate)}</td>
                                <td className="px-4 py-3 text-right font-medium tabular-nums text-slate-900">
                                    {formatMAD(l.montant_ttc)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                </div>

                <div className="space-y-1 border-t border-slate-100 bg-slate-50 px-4 py-3 text-sm">
                    <div className="flex justify-between text-slate-600">
                        <span>Total HT</span>
                        <span className="tabular-nums">{formatMAD(data.total_ht)}</span>
                    </div>
                    <div className="flex justify-between text-slate-600">
                        <span>TVA</span>
                        <span className="tabular-nums">{formatMAD(data.total_tva)}</span>
                    </div>
                    <div className="flex justify-between font-semibold text-slate-900">
                        <span>Total TTC</span>
                        <span className="tabular-nums">{formatMAD(data.total_ttc)}</span>
                    </div>
                </div>
            </section>

            <section className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="text-sm font-medium text-slate-900">
                    {avoir ? 'Remboursements' : 'Règlements'}
                </h2>

                {data.paiements.length === 0 ? (
                    <p className="mt-2 text-sm text-slate-500">
                        {avoir ? 'Cet avoir n\'a pas encore été remboursé.' : 'Aucun règlement enregistré à ce jour.'}
                    </p>
                ) : (
                    <ul className="mt-3 divide-y divide-slate-50">
                        {data.paiements.map((p, i) => (
                            <li key={i} className="flex items-center justify-between py-2 text-sm">
                                <span className="text-slate-600">
                                    {p.date} · {MODE[p.mode] ?? p.mode}
                                    {p.reference && ` · ${p.reference}`}
                                </span>
                                <span className="tabular-nums font-medium text-slate-800">
                                    {formatMAD(p.montant)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                <div className="mt-3 flex justify-between border-t border-slate-100 pt-3 text-sm font-semibold text-slate-900">
                    <span>
                        {avoir
                            ? 'Reste à vous rembourser'
                            : data.etat === 'traite_en_cours'
                              ? 'Couvert par la traite'
                              : 'Reste à payer'}
                    </span>
                    <span className={`tabular-nums ${duMaintenant ? 'text-red-600' : ''}`}>
                        {formatMAD(data.reste_a_payer)}
                    </span>
                </div>
            </section>

            {data.notes && (
                <section className="rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="text-sm font-medium text-slate-900">Mention du grossiste</h2>
                    <p className="mt-1 whitespace-pre-line text-sm text-slate-600">{data.notes}</p>
                </section>
            )}
        </div>
    );
}
