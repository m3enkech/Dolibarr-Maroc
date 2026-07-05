import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { Paginated } from '@/types';

function useCount(countKey: string, endpoint: string, type?: string) {
    return useQuery({
        queryKey: [countKey, type ?? 'all'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<unknown>>(endpoint, {
                params: { per_page: 1, ...(type ? { type } : {}) },
            });
            return data.meta.total;
        },
    });
}

export default function Dashboard() {
    const { tenant } = useAuth();
    const clients = useCount('tiers-count', '/tiers', 'client');
    const fournisseurs = useCount('tiers-count', '/tiers', 'fournisseur');
    const produits = useCount('produits-count', '/produits');
    const factures = useCount('ventes-count', '/ventes/documents', 'facture');

    const cards = [
        { label: 'Clients', value: clients.data },
        { label: 'Fournisseurs', value: fournisseurs.data },
        { label: 'Produits & services', value: produits.data },
        { label: 'Factures', value: factures.data },
    ];

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Tableau de bord</h1>
                <p className="mt-1 text-sm text-slate-500">{tenant?.name} — vue d'ensemble</p>
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {cards.map((card) => (
                    <div key={card.label} className="rounded-xl bg-white p-5 shadow-sm">
                        <div className="text-sm text-slate-500">{card.label}</div>
                        <div className="mt-1 text-3xl font-semibold text-slate-900">
                            {card.value ?? '—'}
                        </div>
                    </div>
                ))}
            </div>

            <div className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="font-medium text-slate-900">Démarrage rapide</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Commencez par enregistrer vos clients et fournisseurs. Les modules Ventes, Stock et
                    Comptabilité s'appuieront sur ces données.
                </p>
                <Link
                    to="/tiers/nouveau"
                    className="mt-4 inline-block rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + Ajouter un tiers
                </Link>
            </div>
        </div>
    );
}
