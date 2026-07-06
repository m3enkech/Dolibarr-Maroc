import { useState } from 'react';
import Activites from '@/pages/crm/Activites';
import Opportunites from '@/pages/crm/Opportunites';

const TABS = [
    { key: 'pipeline', label: 'Pipeline' },
    { key: 'activites', label: 'Activités' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

export default function CrmPage() {
    const [tab, setTab] = useState<TabKey>('pipeline');

    return (
        <div className="space-y-4">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">CRM</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Pilotez votre activité commerciale : pipeline d'opportunités et suivi des interactions
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

            {tab === 'pipeline' && <Opportunites />}
            {tab === 'activites' && <Activites />}
        </div>
    );
}
