import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useFeatures } from '@/lib/features';
import TiersTimeline from '@/pages/tiers/TiersTimeline';
import { LEAD_SOURCES, LEAD_SOURCE_LABELS, type Tiers } from '@/types';
import { useT } from '@/lib/langue';

interface TiersFormData {
    name: string;
    is_client: boolean;
    is_supplier: boolean;
    is_prospect: boolean;
    lead_source: string;
    categorie_tarifaire_id: string;
    plafond_credit: string;
    delai_paiement_jours: string;
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
    is_prospect: false,
    lead_source: '',
    categorie_tarifaire_id: '',
    plafond_credit: '',
    delai_paiement_jours: '',
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
    const t = useT();
    const { id } = useParams();
    const isEdit = id !== undefined;
    const navigate = useNavigate();
    const { features } = useFeatures();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<TiersFormData>(emptyForm);
    const [error, setError] = useState<string | null>(null);

    const { data: categoriesTarifaires } = useQuery({
        queryKey: ['categories-tarifaires'],
        queryFn: async () =>
            (await api.get<{ data: { id: number; name: string; is_default: boolean }[] }>('/categories-tarifaires'))
                .data.data,
    });

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
                is_prospect: existing.is_prospect,
                lead_source: existing.lead_source ?? '',
                categorie_tarifaire_id: existing.categorie_tarifaire_id ? String(existing.categorie_tarifaire_id) : '',
                plafond_credit: existing.plafond_credit ?? '',
                delai_paiement_jours: existing.delai_paiement_jours != null ? String(existing.delai_paiement_jours) : '',
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

    /** Conversion prospect → client : le tiers garde son code et son historique. */
    const convertir = useMutation({
        mutationFn: () => api.post(`/tiers/${id}/convertir`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['tiers'] });
            queryClient.invalidateQueries({ queryKey: ['tiers-detail', id] });
        },
        onError: () => setError('Conversion impossible.'),
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
                    {t('← Retour à la liste')}
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
                    <legend className="sr-only">{t('Identité')}</legend>
                    <h2 className="mb-4 font-medium text-slate-900">{t('Identité')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className={label}>{t('Nom / Raison sociale *')}</label>
                            <input required value={form.name} onChange={text('name')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Contact principal')}</label>
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
                                {t('Client')}
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_supplier}
                                    onChange={check('is_supplier')}
                                    className="rounded border-slate-300"
                                />
                                {t('Fournisseur')}
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_prospect}
                                    onChange={check('is_prospect')}
                                    className="rounded border-slate-300"
                                />
                                {t('Prospect')}
                            </label>
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={check('is_active')}
                                    className="rounded border-slate-300"
                                />
                                {t('Actif')}
                            </label>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm text-slate-600">{t('Plafond de crédit (DH)')}</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.plafond_credit}
                                onChange={text('plafond_credit')}
                                placeholder={t('Vide = pas de plafond')}
                                className={input}
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm text-slate-600">{t('Délai de paiement (jours)')}</label>
                            <input
                                type="number"
                                min="0"
                                max="365"
                                value={form.delai_paiement_jours}
                                onChange={text('delai_paiement_jours')}
                                placeholder="0"
                                className={input}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm text-slate-600">{t('Niveau de tarif')}</label>
                            <select
                                value={form.categorie_tarifaire_id}
                                onChange={(e) => setForm((f) => ({ ...f, categorie_tarifaire_id: e.target.value }))}
                                className={input}
                            >
                                <option value="">{t('— Prix catalogue —')}</option>
                                {(categoriesTarifaires ?? []).map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}{c.is_default ? ' (défaut)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {form.is_prospect && (
                            <div>
                                <label className="mb-1 block text-sm text-slate-600">{t('Origine du lead')}</label>
                                <select
                                    value={form.lead_source}
                                    onChange={(e) => setForm((f) => ({ ...f, lead_source: e.target.value }))}
                                    className={input}
                                >
                                    <option value="">{t('— Non renseignée —')}</option>
                                    {LEAD_SOURCES.map((s) => (
                                        <option key={s} value={s}>{LEAD_SOURCE_LABELS[s]}</option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>

                    {isEdit && existing?.is_prospect && (
                        <div className="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                            <span className="text-sm text-amber-800">
                                Ce tiers est un <strong>prospect</strong>. Convertissez-le en client une fois l'affaire
                                signée — il conserve son code et tout son historique.
                            </span>
                            <button
                                type="button"
                                onClick={() => convertir.mutate()}
                                disabled={convertir.isPending}
                                className="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-amber-700 disabled:opacity-50"
                            >
                                {convertir.isPending ? 'Conversion…' : '✓ Convertir en client'}
                            </button>
                        </div>
                    )}
                </fieldset>

                <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">{t('Identifiants légaux')}</legend>
                    <h2 className="mb-4 font-medium text-slate-900">{t('Identifiants légaux (Maroc)')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className={label}>
                                ICE <span className="font-normal text-slate-400">{t('(15 chiffres)')}</span>
                            </label>
                            <input
                                value={form.ice}
                                onChange={text('ice')}
                                maxLength={15}
                                pattern="\d{15}"
                                title={t("L'ICE comporte exactement 15 chiffres")}
                                className={input}
                            />
                        </div>
                        <div>
                            <label className={label}>{t('Identifiant fiscal (IF)')}</label>
                            <input value={form.if_number} onChange={text('if_number')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Registre de commerce (RC)')}</label>
                            <input value={form.rc} onChange={text('rc')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Patente')}</label>
                            <input value={form.patente} onChange={text('patente')} className={input} />
                        </div>
                        <div>
                            <label className={label}>CNSS</label>
                            <input value={form.cnss} onChange={text('cnss')} className={input} />
                        </div>
                    </div>
                </fieldset>

                <fieldset className="rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">{t('Coordonnées')}</legend>
                    <h2 className="mb-4 font-medium text-slate-900">{t('Coordonnées')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className={label}>{t('Adresse')}</label>
                            <input value={form.address} onChange={text('address')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Ville')}</label>
                            <input value={form.city} onChange={text('city')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Code postal')}</label>
                            <input value={form.postal_code} onChange={text('postal_code')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Téléphone')}</label>
                            <input value={form.phone} onChange={text('phone')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Email')}</label>
                            <input type="email" value={form.email} onChange={text('email')} className={input} />
                        </div>
                        <div className="sm:col-span-2">
                            <label className={label}>{t('Site web')}</label>
                            <input
                                type="url"
                                value={form.website}
                                onChange={text('website')}
                                placeholder={t('https://…')}
                                className={input}
                            />
                        </div>
                        <div className="sm:col-span-2">
                            <label className={label}>{t('Notes')}</label>
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
                        {t('Annuler')}
                    </Link>
                </div>
            </form>

            {isEdit && id && features.crm && <TiersTimeline tiersId={id} />}
        </div>
    );
}
