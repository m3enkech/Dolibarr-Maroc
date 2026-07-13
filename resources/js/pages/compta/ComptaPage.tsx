import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import Balance from '@/pages/compta/Balance';
import BalanceAgee from '@/pages/compta/BalanceAgee';
import Cloture from '@/pages/compta/Cloture';
import EtatsSynthese from '@/pages/compta/EtatsSynthese';
import Ecritures from '@/pages/compta/Ecritures';
import EtatTva from '@/pages/compta/EtatTva';
import Immobilisations from '@/pages/compta/Immobilisations';
import Lettrage from '@/pages/compta/Lettrage';
import Ouverture from '@/pages/compta/Ouverture';
import PlanComptable from '@/pages/compta/PlanComptable';
import type { ComptaMappingRow, Compte } from '@/types';

const TABS = [
    { key: 'ecritures', label: 'Écritures' },
    { key: 'lettrage', label: 'Lettrage' },
    { key: 'balance', label: 'Balance' },
    { key: 'balance-agee', label: 'Balance âgée' },
    { key: 'etats', label: 'Bilan / CPC' },
    { key: 'tva', label: 'État TVA' },
    { key: 'immobilisations', label: 'Immobilisations' },
    { key: 'ouverture', label: 'Balance d\'ouverture' },
    { key: 'cloture', label: 'Clôture' },
    { key: 'plan', label: 'Plan comptable' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

export default function ComptaPage() {
    const [searchParams, setSearchParams] = useSearchParams();
    const raw = searchParams.get('section');
    const keys = TABS.map((t) => t.key) as readonly string[];
    const tab: TabKey = (keys.includes(raw ?? '') ? raw : 'ecritures') as TabKey;
    const setTab = (key: TabKey) => setSearchParams({ section: key });

    const { data: comptesData } = useQuery({
        queryKey: ['compta-comptes'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Compte[]; classes: Record<string, string> }>('/compta/comptes');
            return data;
        },
    });

    const { data: mappings } = useQuery({
        queryKey: ['compta-mappings'],
        queryFn: async () => {
            const { data } = await api.get<{ data: ComptaMappingRow[] }>('/compta/mappings');
            return data.data;
        },
    });

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Comptabilité</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Plan comptable marocain (CGNC) — les écritures de vente et d'encaissement sont générées
                    automatiquement
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

            {tab === 'ecritures' && <Ecritures comptes={comptesData?.data ?? []} />}
            {tab === 'lettrage' && (
                <Lettrage comptes={comptesData?.data ?? []} mappings={mappings ?? []} />
            )}
            {tab === 'balance' && <Balance />}
            {tab === 'balance-agee' && <BalanceAgee />}
            {tab === 'etats' && <EtatsSynthese />}
            {tab === 'tva' && <EtatTva />}
            {tab === 'immobilisations' && <Immobilisations />}
            {tab === 'ouverture' && <Ouverture />}
            {tab === 'cloture' && <Cloture />}
            {tab === 'plan' && (
                <PlanComptable
                    comptes={comptesData?.data ?? []}
                    classes={comptesData?.classes ?? {}}
                    mappings={mappings ?? []}
                />
            )}
        </div>
    );
}
