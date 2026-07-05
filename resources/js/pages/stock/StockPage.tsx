import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import Entrepots from '@/pages/stock/Entrepots';
import StockMouvements from '@/pages/stock/StockMouvements';
import StockNiveaux from '@/pages/stock/StockNiveaux';
import type { Entrepot } from '@/types';

const TABS = [
    { key: 'niveaux', label: 'Niveaux' },
    { key: 'mouvements', label: 'Mouvements' },
    { key: 'entrepots', label: 'Entrepôts' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

export default function StockPage() {
    const [tab, setTab] = useState<TabKey>('niveaux');

    const { data: entrepots } = useQuery({
        queryKey: ['entrepots'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Entrepot[] }>('/stock/entrepots');
            return data.data;
        },
    });

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Stock</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Niveaux, mouvements et entrepôts — les factures validées sortent le stock automatiquement
                </p>
            </div>

            <div className="flex rounded-lg border border-slate-200 bg-white p-1" style={{ width: 'fit-content' }}>
                {TABS.map(({ key, label }) => (
                    <button
                        key={key}
                        onClick={() => setTab(key)}
                        className={`rounded-md px-4 py-1.5 text-sm font-medium transition ${
                            tab === key ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {tab === 'niveaux' && <StockNiveaux entrepots={entrepots ?? []} />}
            {tab === 'mouvements' && <StockMouvements entrepots={entrepots ?? []} />}
            {tab === 'entrepots' && <Entrepots entrepots={entrepots ?? []} />}
        </div>
    );
}
