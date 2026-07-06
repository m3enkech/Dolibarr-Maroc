import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Features, Parametres as ParametresData } from '@/types';

interface FeatureMeta {
    key: keyof Features;
    label: string;
    description: string;
}

const MODULES: FeatureMeta[] = [
    {
        key: 'relances',
        label: 'Relances / recouvrement',
        description:
            'Suivi des factures échues impayées, niveaux de relance (rappel → mise en demeure) et lettres de relance. Utile à toutes les entreprises.',
    },
    {
        key: 'effets',
        label: 'Effets & traites (LCN)',
        description:
            'Gestion des lettres de change et effets à recevoir / payer. Surtout utile en B2B (négoce, distribution).',
    },
];

export default function Parametres() {
    const queryClient = useQueryClient();

    const { data, isLoading } = useQuery({
        queryKey: ['parametres'],
        queryFn: async () => {
            const { data } = await api.get<{ data: ParametresData }>('/parametres');
            return data.data;
        },
    });

    const toggle = useMutation({
        mutationFn: (features: Partial<Features>) => api.put('/parametres', { features }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['parametres'] }),
    });

    return (
        <div className="max-w-3xl space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Paramètres</h1>
                <p className="mt-1 text-sm text-slate-500">{data?.name}</p>
            </div>

            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">Modules</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Activez uniquement ce dont votre entreprise a besoin. Les modules désactivés sont masqués
                    de l'interface.
                </p>

                <div className="mt-5 divide-y divide-slate-100">
                    {isLoading && <div className="py-6 text-sm text-slate-400">Chargement…</div>}
                    {data &&
                        MODULES.map((module) => {
                            const active = data.features[module.key];
                            return (
                                <div key={module.key} className="flex items-start justify-between gap-6 py-4">
                                    <div>
                                        <div className="text-sm font-medium text-slate-900">{module.label}</div>
                                        <p className="mt-1 text-sm text-slate-500">{module.description}</p>
                                    </div>
                                    <button
                                        role="switch"
                                        aria-checked={active}
                                        disabled={toggle.isPending}
                                        onClick={() => toggle.mutate({ [module.key]: !active })}
                                        className={`relative mt-1 h-6 w-11 shrink-0 rounded-full transition ${
                                            active ? 'bg-emerald-600' : 'bg-slate-300'
                                        } disabled:opacity-60`}
                                    >
                                        <span
                                            className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition ${
                                                active ? 'left-[22px]' : 'left-0.5'
                                            }`}
                                        />
                                    </button>
                                </div>
                            );
                        })}
                </div>

                <p className="mt-4 text-xs text-slate-400">
                    Relances et effets sont indépendants : vous pouvez activer les deux, l'un, ou aucun.
                </p>
            </section>
        </div>
    );
}
