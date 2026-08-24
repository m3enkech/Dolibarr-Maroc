import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { formatMAD } from '@/lib/format';
import { portailApi } from '@/lib/portail-api';

export interface FactureResume {
    id: number;
    code: string;
    type: 'facture' | 'avoir';
    date: string | null;
    echeance: string | null;
    total_ttc: string;
    montant_paye: string;
    reste_a_payer: string;
    etat: string;
    jours_retard: number;
    nb_lignes: number | null;
}

interface Situation {
    a_payer: string;
    en_retard: string;
    sous_traite: string;
    avoirs_a_valoir: string;
    nb_factures_ouvertes: number;
}

export const ETAT_FACTURE: Record<string, { libelle: string; classe: string }> = {
    a_payer: { libelle: 'À payer', classe: 'bg-sky-100 text-sky-700' },
    en_retard: { libelle: 'En retard', classe: 'bg-red-100 text-red-700' },
    traite_en_cours: { libelle: 'Traite en cours', classe: 'bg-indigo-100 text-indigo-700' },
    payee: { libelle: 'Payée', classe: 'bg-emerald-100 text-emerald-700' },
    avoir_a_valoir: { libelle: 'Avoir à valoir', classe: 'bg-amber-100 text-amber-700' },
    avoir_rembourse: { libelle: 'Avoir remboursé', classe: 'bg-slate-200 text-slate-600' },
};

export function etatFacture(etat: string) {
    return ETAT_FACTURE[etat] ?? { libelle: etat, classe: 'bg-slate-200 text-slate-600' };
}

export default function PortailFactures() {
    const { grossiste } = useParams();

    const { data, isLoading } = useQuery({
        queryKey: ['portail-factures', grossiste],
        queryFn: async () =>
            (await portailApi.get<{ data: FactureResume[]; meta: { total: number }; situation: Situation }>(
                `/grossistes/${grossiste}/factures`,
            )).data,
    });

    if (isLoading || !data) return <p className="text-sm text-slate-400">Chargement…</p>;

    const s = data.situation;
    const enRetard = parseFloat(s.en_retard) > 0;
    const sousTraite = parseFloat(s.sous_traite) > 0;
    const avoirs = parseFloat(s.avoirs_a_valoir) > 0;

    return (
        <div className="space-y-4">
            <h1 className="text-xl font-semibold text-slate-900">Mes factures</h1>

            <section className={`rounded-xl p-5 shadow-sm ${enRetard ? 'bg-red-50' : 'bg-white'}`}>
                <h2 className="text-sm font-medium text-slate-600">À payer</h2>
                <div className="mt-1 flex flex-wrap items-baseline gap-2">
                    <span className="text-3xl font-semibold tabular-nums text-slate-900">
                        {formatMAD(s.a_payer)}
                    </span>
                    <span className="text-sm text-slate-500">
                        sur {s.nb_factures_ouvertes} facture{s.nb_factures_ouvertes > 1 ? 's' : ''} non soldée
                        {s.nb_factures_ouvertes > 1 ? 's' : ''}
                    </span>
                </div>

                {enRetard && (
                    <p className="mt-2 text-sm font-medium text-red-700">
                        Dont {formatMAD(s.en_retard)} déjà échus.
                    </p>
                )}

                {/* Ces deux sommes ne sont PAS dues : les afficher évite qu'un
                    acheteur cherche où est passé le montant de sa traite. */}
                {(sousTraite || avoirs) && (
                    <div className="mt-3 space-y-1 border-t border-slate-200/70 pt-3 text-sm text-slate-600">
                        {sousTraite && (
                            <p>
                                {formatMAD(s.sous_traite)} sont couverts par une traite en cours — rien à régler
                                d'ici son échéance.
                            </p>
                        )}
                        {avoirs && <p>{formatMAD(s.avoirs_a_valoir)} d'avoirs restent à votre crédit.</p>}
                    </div>
                )}
            </section>

            {data.data.length === 0 && (
                <div className="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">
                    Aucune facture pour l'instant. Elles apparaîtront ici dès que votre grossiste les aura émises.
                </div>
            )}

            <div className="space-y-2">
                {data.data.map((f) => {
                    const etat = etatFacture(f.etat);
                    const partiel = parseFloat(f.montant_paye) > 0 && parseFloat(f.reste_a_payer) > 0;

                    return (
                        <Link
                            key={f.id}
                            to={`/portail/${grossiste}/factures/${f.id}`}
                            className="flex items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm transition hover:shadow"
                        >
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-sm text-slate-700">{f.code}</span>
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${etat.classe}`}>
                                        {etat.libelle}
                                    </span>
                                    {f.jours_retard > 0 && (
                                        <span className="text-[11px] font-medium text-red-600">
                                            +{f.jours_retard} j
                                        </span>
                                    )}
                                </div>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    {f.date}
                                    {f.echeance && f.type === 'facture' && ` · échéance ${f.echeance}`}
                                    {partiel && ` · ${formatMAD(f.montant_paye)} déjà réglés`}
                                </p>
                            </div>
                            <div className="shrink-0 text-right">
                                <div className="font-semibold tabular-nums text-slate-900">
                                    {formatMAD(f.total_ttc)}
                                </div>
                                {/* Le reste n'est annoncé que s'il est réellement
                                    réclamé : sous traite, il ne l'est pas. */}
                                {(f.etat === 'a_payer' || f.etat === 'en_retard') && (
                                    <div className="text-xs text-slate-500">
                                        reste {formatMAD(f.reste_a_payer)}
                                    </div>
                                )}
                            </div>
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
