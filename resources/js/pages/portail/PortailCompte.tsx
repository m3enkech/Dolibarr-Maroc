import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { formatMAD } from '@/lib/format';
import { portailApi } from '@/lib/portail-api';
import { useT } from '@/lib/langue';

interface Compte {
    client: { code: string; name: string };
    encours: string;
    plafond: string | null;
    disponible: string | null;
    delai_paiement_jours: number | null;
}

export default function PortailCompte() {
    const { grossiste } = useParams();
    const t = useT();

    const { data, isLoading } = useQuery({
        queryKey: ['portail-compte', grossiste],
        queryFn: async () =>
            (await portailApi.get<{ data: Compte }>(`/grossistes/${grossiste}/mon-compte`)).data.data,
    });

    if (isLoading || !data) return <p className="text-sm text-slate-400">{t('Chargement…')}</p>;

    const aPlafond = data.plafond !== null;
    const encours = parseFloat(data.encours);
    const plafond = aPlafond ? parseFloat(data.plafond as string) : 0;
    const part = aPlafond && plafond > 0 ? Math.min(100, Math.round((encours / plafond) * 100)) : 0;

    return (
        <div className="max-w-xl space-y-4">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">{t('Mon compte')}</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {data.client.name} · <span className="font-mono text-xs">{data.client.code}</span>
                </p>
            </div>

            <section className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="text-sm font-medium text-slate-900">{t('Ce que je dois')}</h2>

                <div className="mt-3 flex items-baseline gap-2">
                    <span className="text-3xl font-semibold tabular-nums text-slate-900">
                        {formatMAD(data.encours)}
                    </span>
                    {aPlafond && (
                        <span className="text-sm text-slate-500">
                            {t('sur {plafond} autorisés', { plafond: formatMAD(data.plafond as string) })}
                        </span>
                    )}
                </div>

                {aPlafond ? (
                    <>
                        <div className="mt-3 h-2 w-full rounded-full bg-slate-100">
                            <div
                                className={`h-2 rounded-full ${part >= 90 ? 'bg-red-500' : part >= 70 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                                style={{ width: `${Math.max(2, part)}%` }}
                            />
                        </div>
                        <p className="mt-2 text-sm text-slate-600">
                            {t('Crédit encore disponible')} :{' '}
                            <strong>{formatMAD(data.disponible as string)}</strong>
                        </p>
                    </>
                ) : (
                    <p className="mt-2 text-sm text-slate-500">
                        {t("Aucun plafond de crédit n'est fixé sur votre compte.")}
                    </p>
                )}

                {data.delai_paiement_jours !== null && (
                    <p className="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600">
                        {t('Délai de règlement accordé')} :{' '}
                        <strong>{t('{n} jours', { n: data.delai_paiement_jours })}</strong>
                    </p>
                )}

                {/* L'encours vient du grand livre : il inclut les traites tirées
                    et déduit les avoirs. Il ne coïncide donc PAS avec le « à
                    payer » de l'écran des factures, qui écarte les deux — d'où
                    la phrase, sans quoi l'acheteur croirait à une erreur. */}
                <p className="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    {t('Ce montant tient compte de vos traites en cours et déduit vos avoirs.')}
                </p>
                <Link
                    to={`/portail/${grossiste}/factures`}
                    className="mt-2 inline-block text-sm font-medium text-emerald-600 hover:underline"
                >
                    {t('Voir le détail de mes factures →')}
                </Link>
            </section>
        </div>
    );
}
