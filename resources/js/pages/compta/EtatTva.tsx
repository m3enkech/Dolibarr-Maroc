import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';

interface TvaResponse {
    mois: string;
    tva_facturee: string;
    tva_recuperable: string;
    tva_due: string;
    credit_tva: string;
}

export default function EtatTva() {
    const [mois, setMois] = useState(() => new Date().toISOString().slice(0, 7));
    const [exporting, setExporting] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['compta-tva', mois],
        queryFn: async () => {
            const { data } = await api.get<TvaResponse>('/compta/tva', { params: { mois } });
            return data;
        },
    });

    const enCredit = data ? parseFloat(data.credit_tva) > 0 : false;

    const exporter = async () => {
        setExporting(true);
        try {
            const response = await api.get('/compta/tva/export', {
                params: { mois },
                responseType: 'blob',
            });
            const [annee, moisNum] = mois.split('-');
            const url = URL.createObjectURL(response.data);
            const link = document.createElement('a');
            link.href = url;
            link.download = `TVA ${moisNum}${annee}.xlsx`;
            link.click();
            URL.revokeObjectURL(url);
        } finally {
            setExporting(false);
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex items-center gap-3">
                <label className="text-sm text-slate-600">Mois</label>
                <input
                    type="month"
                    value={mois}
                    onChange={(e) => setMois(e.target.value)}
                    className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                />
                <button
                    onClick={exporter}
                    disabled={exporting}
                    className="ml-auto rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                >
                    {exporting ? 'Génération…' : '⬇ Export SIMPL-TVA (Excel)'}
                </button>
            </div>

            {isLoading || !data ? (
                <div className="py-8 text-center text-slate-400">Chargement…</div>
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <div className="text-sm text-slate-500">TVA facturée (4441)</div>
                            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
                                {formatMAD(data.tva_facturee)}
                            </div>
                            <div className="mt-1 text-xs text-slate-400">Collectée sur vos ventes</div>
                        </div>
                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <div className="text-sm text-slate-500">TVA récupérable (3441 + 3442)</div>
                            <div className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
                                {formatMAD(data.tva_recuperable)}
                            </div>
                            <div className="mt-1 text-xs text-slate-400">Déductible sur vos achats</div>
                        </div>
                        <div className={`rounded-xl p-5 shadow-sm ${enCredit ? 'bg-sky-50' : 'bg-emerald-50'}`}>
                            <div className={`text-sm ${enCredit ? 'text-sky-700' : 'text-emerald-700'}`}>
                                {enCredit ? 'Crédit de TVA' : 'TVA due (4442)'}
                            </div>
                            <div className={`mt-1 text-2xl font-bold tabular-nums ${enCredit ? 'text-sky-900' : 'text-emerald-900'}`}>
                                {formatMAD(enCredit ? data.credit_tva : data.tva_due)}
                            </div>
                            <div className={`mt-1 text-xs ${enCredit ? 'text-sky-600' : 'text-emerald-600'}`}>
                                {enCredit ? 'Reportable sur le mois suivant' : 'À déclarer et régler'}
                            </div>
                        </div>
                    </div>

                    <p className="text-xs text-slate-500">
                        Déclaration mensuelle à télédéclarer sur SIMPL-TVA avant le 20 du mois suivant
                        (régime de l'encaissement). L'export Excel génère deux feuilles au format DGI :
                        le <strong>relevé de déductions</strong> (achats fournisseurs réglés dans le mois,
                        modèle ADC082F-15I) et le <strong>chiffre d'affaires</strong> (factures de vente
                        du mois).
                    </p>
                </>
            )}
        </div>
    );
}
