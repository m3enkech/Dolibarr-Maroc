import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';

interface Etats {
    cpc: Record<string, string>;
    bilan: Record<string, string | boolean>;
}

export default function EtatsSynthese() {
    const { data, isLoading } = useQuery({
        queryKey: ['compta-etats-synthese'],
        queryFn: async () => {
            const { data } = await api.get<Etats>('/compta/etats-synthese');
            return data;
        },
    });

    if (isLoading || !data) {
        return <div className="py-8 text-center text-slate-400">Chargement…</div>;
    }

    const cpc = data.cpc;
    const bilan = data.bilan as Record<string, string>;
    const equilibre = data.bilan.equilibre === true;

    const ligne = (label: string, valeur: string, opts: { fort?: boolean; resultat?: boolean } = {}) => {
        const num = parseFloat(valeur);
        return (
            <div
                className={`flex items-center justify-between px-4 py-2 ${
                    opts.fort ? 'border-t border-slate-200 bg-slate-50 font-semibold' : ''
                }`}
            >
                <span className={opts.fort ? 'text-slate-900' : 'text-slate-600'}>{label}</span>
                <span
                    className={`tabular-nums ${
                        opts.resultat
                            ? num > 0
                                ? 'font-semibold text-emerald-700'
                                : num < 0
                                  ? 'font-semibold text-red-600'
                                  : 'text-slate-500'
                            : 'text-slate-900'
                    }`}
                >
                    {formatMAD(valeur)}
                </span>
            </div>
        );
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            {/* CPC */}
            <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                    Compte de Produits et Charges (CPC)
                </div>
                {ligne('Produits d\'exploitation', cpc.produits_exploitation)}
                {ligne('Charges d\'exploitation', cpc.charges_exploitation)}
                {ligne('Résultat d\'exploitation', cpc.resultat_exploitation, { fort: true, resultat: true })}
                {ligne('Produits financiers', cpc.produits_financiers)}
                {ligne('Charges financières', cpc.charges_financieres)}
                {ligne('Résultat financier', cpc.resultat_financier, { fort: true, resultat: true })}
                {ligne('Résultat courant', cpc.resultat_courant, { fort: true, resultat: true })}
                {ligne('Produits non courants', cpc.produits_non_courants)}
                {ligne('Charges non courantes', cpc.charges_non_courantes)}
                {ligne('Résultat non courant', cpc.resultat_non_courant, { fort: true, resultat: true })}
                {ligne('Résultat avant impôts', cpc.resultat_avant_impot, { fort: true, resultat: true })}
                {ligne('Impôts sur les résultats', cpc.impot_resultat)}
                <div className="flex items-center justify-between border-t-2 border-emerald-600 bg-emerald-50 px-4 py-3">
                    <span className="font-bold text-slate-900">Résultat net</span>
                    <span
                        className={`text-lg font-bold tabular-nums ${
                            parseFloat(cpc.resultat_net) >= 0 ? 'text-emerald-700' : 'text-red-600'
                        }`}
                    >
                        {formatMAD(cpc.resultat_net)}
                    </span>
                </div>
            </div>

            {/* Bilan */}
            <div className="space-y-4">
                <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                    <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                        Bilan — Actif
                    </div>
                    {ligne('Actif immobilisé (net)', bilan.actif_immobilise)}
                    {ligne('Actif circulant', bilan.actif_circulant)}
                    {ligne('Trésorerie — Actif', bilan.tresorerie_actif)}
                    {ligne('Total Actif', bilan.total_actif, { fort: true })}
                </div>
                <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                    <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                        Bilan — Passif
                    </div>
                    {ligne('Financement permanent', bilan.financement_permanent)}
                    <div className="px-4 py-1 text-xs text-slate-400">dont résultat net : {formatMAD(bilan.dont_resultat_net)}</div>
                    {ligne('Passif circulant', bilan.passif_circulant)}
                    {ligne('Trésorerie — Passif', bilan.tresorerie_passif)}
                    {ligne('Total Passif', bilan.total_passif, { fort: true })}
                </div>
                <div
                    className={`rounded-xl px-4 py-3 text-sm ${
                        equilibre ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'
                    }`}
                >
                    {equilibre
                        ? '✓ Bilan équilibré (Actif = Passif)'
                        : '⚠ Déséquilibre : Actif ≠ Passif — vérifiez les écritures.'}
                </div>
            </div>

            <p className="text-xs text-slate-500 lg:col-span-2">
                États de synthèse calculés sur la période ouverte (après clôture, les à-nouveaux portent
                l'historique). Version simplifiée par masses ; la liasse détaillée (ETIC, tableaux annexes)
                viendra ultérieurement.
            </p>
        </div>
    );
}
