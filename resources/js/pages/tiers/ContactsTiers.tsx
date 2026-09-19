import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useT } from '@/lib/langue';

type Contact = {
    id: number;
    nom: string;
    fonction: string | null;
    email: string | null;
    phone: string | null;
    mobile: string | null;
    notes: string | null;
    is_principal: boolean;
    is_active: boolean;
};

const VIDE = { nom: '', fonction: '', email: '', phone: '', mobile: '' };

/**
 * Les interlocuteurs d'un tiers, édités sur place.
 *
 * Le contact PRINCIPAL est mis en avant : c'est lui que viseront les documents
 * et les relances, et une fiche qui ne le distingue pas laisse envoyer au
 * magasinier une mise en demeure destinée au gérant.
 */
export default function ContactsTiers({ tiersId }: { tiersId: number }) {
    const t = useT();
    const qc = useQueryClient();
    const [formulaire, setFormulaire] = useState<typeof VIDE | null>(null);
    const [editionId, setEditionId] = useState<number | null>(null);

    const { data: contacts, isLoading } = useQuery({
        queryKey: ['tiers-contacts', tiersId],
        queryFn: async () => {
            const { data } = await api.get<{ data: Contact[] }>(`/tiers/${tiersId}/contacts`);
            return data.data;
        },
    });

    const rafraichir = () => {
        qc.invalidateQueries({ queryKey: ['tiers-contacts', tiersId] });
        qc.invalidateQueries({ queryKey: ['tiers-synthese', String(tiersId)] });
        setFormulaire(null);
        setEditionId(null);
    };

    const enregistrer = useMutation({
        mutationFn: (data: typeof VIDE) =>
            editionId
                ? api.put(`/tiers/${tiersId}/contacts/${editionId}`, data)
                : api.post(`/tiers/${tiersId}/contacts`, data),
        onSuccess: rafraichir,
    });

    const designer = useMutation({
        mutationFn: (id: number) => api.put(`/tiers/${tiersId}/contacts/${id}`, { is_principal: true }),
        onSuccess: rafraichir,
    });

    const supprimer = useMutation({
        mutationFn: (id: number) => api.delete(`/tiers/${tiersId}/contacts/${id}`),
        onSuccess: rafraichir,
    });

    const ouvrirEdition = (c: Contact) => {
        setEditionId(c.id);
        setFormulaire({
            nom: c.nom,
            fonction: c.fonction ?? '',
            email: c.email ?? '',
            phone: c.phone ?? '',
            mobile: c.mobile ?? '',
        });
    };

    return (
        <div className="rounded-xl bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between">
                <h2 className="font-medium text-slate-900">{t('Contacts')}</h2>
                {formulaire === null && (
                    <button
                        onClick={() => {
                            setEditionId(null);
                            setFormulaire({ ...VIDE });
                        }}
                        className="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-emerald-700"
                    >
                        + {t('Contact')}
                    </button>
                )}
            </div>

            {isLoading && <div className="py-4 text-sm text-slate-400">{t('Chargement…')}</div>}

            {!isLoading && contacts?.length === 0 && formulaire === null && (
                <p className="text-sm text-slate-400">
                    {t('Aucun interlocuteur enregistré chez ce tiers.')}
                </p>
            )}

            <div className="space-y-2">
                {contacts?.map((c) => (
                    <div
                        key={c.id}
                        className={`flex flex-wrap items-start justify-between gap-3 rounded-lg border p-3 ${
                            c.is_principal ? 'border-emerald-200 bg-emerald-50/50' : 'border-slate-200'
                        }`}
                    >
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-medium text-slate-900">{c.nom}</span>
                                {c.fonction && <span className="text-sm text-slate-500">— {c.fonction}</span>}
                                {c.is_principal && (
                                    <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700">
                                        {t('Principal')}
                                    </span>
                                )}
                            </div>
                            <div className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-sm text-slate-600">
                                {c.email && <a href={`mailto:${c.email}`} className="hover:underline">{c.email}</a>}
                                {c.mobile && <a href={`tel:${c.mobile}`} className="hover:underline">{c.mobile}</a>}
                                {c.phone && <a href={`tel:${c.phone}`} className="hover:underline">{c.phone}</a>}
                            </div>
                        </div>

                        <div className="flex shrink-0 gap-2 text-sm">
                            {!c.is_principal && (
                                <button
                                    onClick={() => designer.mutate(c.id)}
                                    className="text-slate-500 underline hover:text-emerald-700"
                                >
                                    {t('Rendre principal')}
                                </button>
                            )}
                            <button onClick={() => ouvrirEdition(c)} className="text-slate-500 underline hover:text-slate-900">
                                {t('Modifier')}
                            </button>
                            <button
                                onClick={() => {
                                    if (window.confirm(t('Supprimer ce contact ?'))) supprimer.mutate(c.id);
                                }}
                                className="text-slate-400 underline hover:text-red-600"
                            >
                                {t('Supprimer')}
                            </button>
                        </div>
                    </div>
                ))}
            </div>

            {formulaire !== null && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        enregistrer.mutate(formulaire);
                    }}
                    className="mt-4 grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-2"
                >
                    <Champ libelle={t('Nom')} requis>
                        <input
                            required
                            value={formulaire.nom}
                            onChange={(e) => setFormulaire({ ...formulaire, nom: e.target.value })}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </Champ>
                    <Champ libelle={t('Fonction')}>
                        <input
                            value={formulaire.fonction}
                            onChange={(e) => setFormulaire({ ...formulaire, fonction: e.target.value })}
                            placeholder={t('Directeur achats, comptabilité…')}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </Champ>
                    <Champ libelle={t('Email')}>
                        <input
                            type="email"
                            value={formulaire.email}
                            onChange={(e) => setFormulaire({ ...formulaire, email: e.target.value })}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </Champ>
                    <Champ libelle={t('Mobile')}>
                        <input
                            value={formulaire.mobile}
                            onChange={(e) => setFormulaire({ ...formulaire, mobile: e.target.value })}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        />
                    </Champ>

                    <div className="flex gap-2 sm:col-span-2">
                        <button
                            type="submit"
                            disabled={enregistrer.isPending}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {t('Enregistrer')}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setFormulaire(null);
                                setEditionId(null);
                            }}
                            className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                        >
                            {t('Annuler')}
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}

function Champ({ libelle, requis, children }: { libelle: string; requis?: boolean; children: React.ReactNode }) {
    return (
        <label className="block min-w-0">
            <span className="mb-1 block text-xs uppercase tracking-wide text-slate-500">
                {libelle}
                {requis && ' *'}
            </span>
            {children}
        </label>
    );
}
