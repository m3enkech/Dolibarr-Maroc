import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Features, Parametres as ParametresData, Societe } from '@/types';

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
    {
        key: 'crm',
        label: 'CRM / Pipeline commercial',
        description:
            'Suivi des opportunités en pipeline (kanban), du prospect à l\'affaire gagnée. Pour piloter votre activité commerciale.',
    },
];

const CHAMPS_SOCIETE: { key: keyof Societe; label: string; placeholder?: string; span?: boolean }[] = [
    { key: 'name', label: 'Raison sociale', span: true },
    { key: 'ice', label: 'ICE', placeholder: '15 chiffres' },
    { key: 'if', label: 'Identifiant fiscal (IF)' },
    { key: 'rc', label: 'Registre du commerce (RC)' },
    { key: 'patente', label: 'Patente' },
    { key: 'cnss', label: 'CNSS' },
    { key: 'phone', label: 'Téléphone' },
    { key: 'email', label: 'Email' },
    { key: 'website', label: 'Site web' },
    { key: 'address', label: 'Adresse', span: true },
    { key: 'city', label: 'Ville' },
    { key: 'postal_code', label: 'Code postal' },
];

function SocieteSection({ societe }: { societe: Societe }) {
    const queryClient = useQueryClient();
    const [form, setForm] = useState<Societe>(societe);
    const [enregistre, setEnregistre] = useState(false);

    useEffect(() => setForm(societe), [societe]);

    const save = useMutation({
        mutationFn: (data: Societe) => api.put('/parametres/societe', data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['parametres'] });
            setEnregistre(true);
            setTimeout(() => setEnregistre(false), 2000);
        },
    });

    const set = (key: keyof Societe) => (e: React.ChangeEvent<HTMLInputElement>) =>
        setForm((f) => ({ ...f, [key]: e.target.value }));

    return (
        <section className="rounded-xl bg-white p-6 shadow-sm">
            <h2 className="font-medium text-slate-900">Identité de l'entreprise</h2>
            <p className="mt-1 text-sm text-slate-500">
                Ces informations apparaissent sur vos factures PDF, la facture électronique (UBL) et l'export
                SIMPL-TVA. Renseignez-les pour être en conformité.
            </p>

            <div className="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                {CHAMPS_SOCIETE.map((c) => (
                    <div key={c.key} className={c.span ? 'sm:col-span-2' : ''}>
                        <label className="mb-1 block text-xs font-medium text-slate-600">{c.label}</label>
                        <input
                            value={(form[c.key] as string) ?? ''}
                            onChange={set(c.key)}
                            placeholder={c.placeholder}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                ))}
            </div>

            <div className="mt-5 flex items-center gap-3">
                <button
                    onClick={() => save.mutate(form)}
                    disabled={save.isPending}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                >
                    {save.isPending ? 'Enregistrement…' : 'Enregistrer'}
                </button>
                {enregistre && <span className="text-sm text-emerald-600">✓ Enregistré</span>}
            </div>
        </section>
    );
}

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

            {isLoading && <div className="text-sm text-slate-400">Chargement…</div>}

            {data && <SocieteSection societe={data.societe} />}

            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">Modules</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Activez uniquement ce dont votre entreprise a besoin. Les modules désactivés sont masqués
                    de l'interface.
                </p>

                <div className="mt-5 divide-y divide-slate-100">
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
