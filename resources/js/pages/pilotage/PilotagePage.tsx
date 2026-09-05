import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useT } from '@/lib/langue';
import type { Entrepot } from '@/types';
import AReapprovisionner from './AReapprovisionner';
import DetailDepots from './DetailDepots';
import FluxBandeau from './FluxBandeau';
import ProduitsTable from './ProduitsTable';

const CHAMP =
    'rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

/** Âge de la donnée la plus fraîche de l'écran, en secondes. */
function useAgeDesDonnees(): number | null {
    const queryClient = useQueryClient();
    const [, retracer] = useState(0);

    // Le temps passe même quand rien ne change : sans ce battement, « il y a
    // 3 s » resterait affiché une heure durant.
    useEffect(() => {
        const battement = setInterval(() => retracer((n) => n + 1), 10_000);

        return () => clearInterval(battement);
    }, []);

    const horodatages = queryClient
        .getQueryCache()
        .findAll({ queryKey: ['pilotage'] })
        .map((requete) => requete.state.dataUpdatedAt)
        .filter((valeur) => valeur > 0);

    if (horodatages.length === 0) {
        return null;
    }

    return Math.round((Date.now() - Math.max(...horodatages)) / 1000);
}

/**
 * Écran de suivi : ce qui manque, et où en sont les flux.
 *
 * Fait pour rester ouvert. Deux conséquences qui ne vont pas de soi :
 *
 *  — Les requêtes de cet écran se rafraîchissent seules, alors que le reste de
 *    l'ERP ne le fait pas (`refetchOnWindowFocus` est désactivé globalement, ce
 *    qui protège les formulaires de saisie d'un écrasement en cours de frappe).
 *    Le réglage est donc posé REQUÊTE PAR REQUÊTE ici, jamais sur le client
 *    global.
 *  — L'écran dit son âge. Un tableau de bord figé ressemble à une entreprise
 *    calme ; s'il ne dit pas quand il a regardé, il ment par omission.
 */
export default function PilotagePage() {
    const t = useT();
    const queryClient = useQueryClient();
    const [entrepotId, setEntrepotId] = useState('');
    const [produitOuvert, setProduitOuvert] = useState<number | null>(null);
    const age = useAgeDesDonnees();

    const { data: entrepots } = useQuery({
        queryKey: ['entrepots'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Entrepot[] }>('/stock/entrepots');
            return data.data;
        },
    });

    return (
        <div className="space-y-5">
            <header className="flex flex-wrap items-center gap-3">
                <h1 className="text-xl font-semibold text-slate-900">{t('Suivi')}</h1>

                <div className="ms-auto flex flex-wrap items-center gap-3">
                    {age !== null && (
                        <span className="text-xs text-slate-400">
                            {age < 60
                                ? t('à jour il y a {n} s', { n: age })
                                : t('à jour il y a {n} min', { n: Math.round(age / 60) })}
                        </span>
                    )}
                    <button
                        type="button"
                        onClick={() => queryClient.invalidateQueries({ queryKey: ['pilotage'] })}
                        className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                    >
                        {t('Rafraîchir')}
                    </button>
                    {entrepots && entrepots.length > 1 && (
                        <select
                            value={entrepotId}
                            onChange={(e) => setEntrepotId(e.target.value)}
                            aria-label={t('Filtrer par dépôt')}
                            className={CHAMP}
                        >
                            <option value="">{t('Tous les dépôts')}</option>
                            {entrepots.map((entrepot) => (
                                <option key={entrepot.id} value={entrepot.id}>
                                    {entrepot.name}
                                </option>
                            ))}
                        </select>
                    )}
                </div>
            </header>

            <FluxBandeau />

            {/*
             * Quand aucun article n'est ouvert, le tableau prend toute la
             * largeur : sur un écran dont la raison d'être est la densité, on ne
             * réserve pas un tiers de la place à un panneau vide.
             */}
            <div className="grid min-w-0 gap-6 lg:grid-cols-3">
                <div className={produitOuvert !== null ? 'min-w-0 lg:col-span-2' : 'min-w-0 lg:col-span-3'}>
                    <ProduitsTable
                        entrepotId={entrepotId}
                        produitOuvert={produitOuvert}
                        onOuvrir={(id) => setProduitOuvert((ouvert) => (ouvert === id ? null : id))}
                    />
                </div>

                {produitOuvert !== null && (
                    <DetailDepots produitId={produitOuvert} onFermer={() => setProduitOuvert(null)} />
                )}
            </div>

            <AReapprovisionner entrepotId={entrepotId} />
        </div>
    );
}
