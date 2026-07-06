import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useFeatures } from '@/lib/features';
import TiersTimeline from '@/pages/tiers/TiersTimeline';
import type { Tiers } from '@/types';

interface TiersFormData {
    name: string;
    is_client: boolean;
    is_supplier: boolean;
    ice: string;
    if_number: string;
    rc: string;
    patente: string;
    cnss: string;
    address: string;
    city: string;
    postal_code: string;
    phone: string;
    email: string;
    website: string;
    contact_name: string;
    notes: string;
    is_active: boolean;
}

const emptyForm: TiersFormData = {
    name: '',
    is_client: true,
    is_supplier: false,
    ice: '',
    if_number: '',
    rc: '',
    patente: '',
    cnss: '',
    address: '',
    city: '',
    postal_code: '',
    phone: '',
    email: '',
    website: '',
    contact_name: '',
    notes: '',
    is_active: true,
};

/** Convertit le formulaire en payload API : chaînes vides → null. */
function toPayload(form: TiersFormData) {
    return Object.fromEntries(
        Object.entries(form).map(([key, value]) => [key, value === '' ? null : value]),
    );
}

export default function TiersForm() {
    const { id } = useParams();
    const isEdit = id !== undefined;
    const navigate = useNavigate();
    const { features } = useFeatures();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<TiersFormData>(emptyForm);
    const [error, setError] = useState<string | null>(null);

    const { data: existing } = useQuery({
        queryKey: ['tiers-detail', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Tiers }>(`/tiers/${id}`);
            return data.data;
        },
        enabled: isEdit,
    });

    useEffect(() => {
        if (existing) {
            setForm({
                name: existing.name,
                is_client: existing.is_client,
                is_supplier: existing.is_supplier,
                ice: existing.ice ?? '',
                if_number: existing.if_number ?? '',
                rc: existing.rc ?? '',
                patente: existing.patente ?? '',
                cnss: existing.cnss ?? '',
                address: existing.address ?? '',
                city: existing.city ?? '',
                postal_code: existing.postal_code ?? '',
                phone: existing.phone ?? '',
                email: existing.email ?? '',
                website: existing.website ?? '',
                contact_name: existing.contact_name ?? '',
                notes: existing.notes ?? '',
                is_active: existing.is_active,
            });
        }
    }, [existing]);

    const mutation = useMutation({
        mutationFn: (payload: Record<string, unknown>) =>
            isEdit ? api.put(`/tiers/${id}`, payload) : api.post('/tiers', payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['tiers'] });
            queryClient.invalidateQueries({ queryKey: ['tiers-count'] });
            navigate('/tiers');
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setError(
                messages
                    ? (Object.values(messages).flat() as string[]).join(' ')
                    : 'Enregistrement impossible.',
            );
        },
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        mutation.mutate(toPayload(form));
    };

    const text =
        (key: keyof TiersFormData) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
            setForm((f) => ({ ...f, [key]: e.target.value }));
    const check = (key: keyof TiersFormData) => (e: React.ChangeEvent<HTMLInputElement>) =>
        setForm((f) => ({ ...f, [key]: e.target.checked }));

    const input =
        'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
    const label = 'mb-1 block text-sm font-medium text-slate-700';

    return (
        <div className="max-w-3xl space-y-4">
            <div>
                <Link to="/tiers" className="text-sm text-emerald-600 hover:underline">
                    ← Retour à la liste
                </Link>
                <h1 className="mt-2 text-xl font-semibold text-slate-900">
                    {isEdit ? `Modifier ${existing?.name ?? ''}` : 'Nouveau tiers'}
                </h1>
                {isEdit && existing && (
                    <p className="mt-1 font-mono text-xs text-slate-500">{existing.code}</p>
                )}
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
                <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">Identité</legend>
                    <h2 className="mb-4 font-medium text-slate-900">Identité</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className={label}>Nom / Raison sociale *</label>
                            <input required value={form.name} onChange={text('name')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Contact principal</label>
                            <input value={form.contact_name} onChange={text('contact_name')} className={input} />
                        </div>
                        <div className="flex items-end gap-6 pb-2">
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_client}
                                    onChange={check('is_client')}
                                    className="rounded border-slate-300"
                                />
                                Client
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_supplier}
                                    onChange={check('is_supplier')}
                                    className="rounded border-slate-300"
                                />
                                Fournisseur
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={check('is_active')}
                                    className="rounded border-slate-300"
                                />
                                Actif
                            </label>
                        </div>
                    </div>
                </fieldset>

                <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">Identifiants légaux</legend>
                    <h2 className="mb-4 font-medium text-slate-900">Identifiants légaux (Maroc)</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className={label}>
                                ICE <span className="font-normal text-slate-400">(15 chiffres)</span>
                            </label>
                            <input
                                value={form.ice}
                                onChange={text('ice')}
                                maxLength={15}
                                pattern="\d{15}"
                                title="L'ICE comporte exactement 15 chiffres"
                                className={input}
                            />
                        </div>
                        <div>
                            <label className={label}>Identifiant fiscal (IF)</label>
                            <input value={form.if_number} onChange={text('if_number')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Registre de commerce (RC)</label>
                            <input value={form.rc} onChange={text('rc')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Patente</label>
                            <input value={form.patente} onChange={text('patente')} className={input} />
                        </div>
                        <div>
                            <label className={label}>CNSS</label>
                            <input value={form.cnss} onChange={text('cnss')} className={input} />
                        </div>
                    </div>
                </fieldset>

                <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">Coordonnées</legend>
                    <h2 className="mb-4 font-medium text-slate-900">Coordonnées</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className={label}>Adresse</label>
                            <input value={form.address} onChange={text('address')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Ville</label>
                            <input value={form.city} onChange={text('city')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Code postal</label>
                            <input value={form.postal_code} onChange={text('postal_code')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Téléphone</label>
                            <input value={form.phone} onChange={text('phone')} className={input} />
                        </div>
                        <div>
                            <label className={label}>Email</label>
                            <input type="email" value={form.email} onChange={text('email')} className={input} />
                        </div>
                        <div className="sm:col-span-2">
                            <label className={label}>Site web</label>
                            <input
                                type="url"
                                value={form.website}
                                onChange={text('website')}
                                placeholder="https://…"
                                className={input}
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <label className={label}>Notes</label>
                            <textarea value={form.notes} onChange={text('notes')} rows={3} className={input} />
                        </div>
                    </div>
                </fieldset>

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={mutation.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {mutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                    <Link
                        to="/tiers"
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        Annuler
                    </Link>
                </div>
            </form>

            {isEdit && id && features.crm && <TiersTimeline tiersId={id} />}
        </div>
    );
}
