import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { AdhesionStatut, Paginated, PortailAdhesion, Tiers } from '@/types';

const ETAT: Record<AdhesionStatut, { libelle: string; classe: string }> = {
    en_attente: { libelle: 'En attente', classe: 'bg-amber-100 text-amber-700' },
    approuve: { libelle: 'Accès actif', classe: 'bg-emerald-100 text-emerald-700' },
    refuse: { libelle: 'Refusée', classe: 'bg-red-100 text-red-700' },
    revoque: { libelle: 'Accès fermé', classe: 'bg-slate-200 text-slate-600' },
};

function jour(date: string | null): string {
    return date ? new Date(date).toLocaleDateString('fr-MA') : '—';
}

function messageErreur(err: unknown, defaut: string): string {
    const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
    const erreurs = e?.response?.data?.errors;
    if (erreurs) return Object.values(erreurs).flat().join(' ');
    return e?.response?.data?.message ?? defaut;
}

/** Adresse du portail à communiquer aux clients, avec retour visuel à la copie. */
function AdressePortail({ grossiste }: { grossiste: string }) {
    const [copie, setCopie] = useState(false);
    const url = `${window.location.origin}/portail/connexion`;

    const copier = async () => {
        await navigator.clipboard.writeText(url);
        setCopie(true);
        setTimeout(() => setCopie(false), 1800);
    };

    return (
        <section className="rounded-xl bg-white p-5 shadow-sm">
            <h2 className="font-medium text-slate-900">L'adresse à donner à vos clients</h2>
            <p className="mt-1 text-sm text-slate-500">
                Ils créent leur compte, puis demandent l'accès en choisissant « {grossiste} » dans la liste des
                grossistes. Leur demande arrive ici.
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <input
                    readOnly
                    value={url}
                    className="min-w-64 flex-1 rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700"
                />
                <button
                    onClick={copier}
                    className="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                >
                    {copie ? '✓ Copié' : '📋 Copier'}
                </button>
            </div>
        </section>
    );
}

export default function Adhesions() {
    const queryClient = useQueryClient();
    const { tenant, can } = useAuth();
    const peutAgir = can('tiers', 'write');

    const [ouverte, setOuverte] = useState<number | null>(null);
    const [clientChoisi, setClientChoisi] = useState('');
    const [message, setMessage] = useState<string | null>(null);
    const [erreur, setErreur] = useState<string | null>(null);

    const { data: adhesions, isLoading } = useQuery({
        queryKey: ['portail-adhesions'],
        queryFn: async () => (await api.get<{ data: PortailAdhesion[] }>('/portail/adhesions')).data.data,
    });

    // Les fiches clients ne sont chargées qu'à l'ouverture d'un panneau : inutile
    // d'en tirer 300 pour un écran qu'on ne fait souvent que consulter.
    const { data: clients } = useQuery({
        queryKey: ['tiers-clients'],
        queryFn: async () =>
            (await api.get<Paginated<Tiers>>('/tiers', { params: { type: 'client', per_page: 300 } })).data.data,
        enabled: ouverte !== null,
    });

    const action = useMutation({
        mutationFn: (v: { id: number; verbe: 'approuver' | 'refuser' | 'revoquer'; tiersId?: number | null }) =>
            api.post(
                `/portail/adhesions/${v.id}/${v.verbe}`,
                v.verbe === 'approuver' ? { tiers_id: v.tiersId ?? null } : {},
            ),
        onSuccess: ({ data }) => {
            setErreur(null);
            setMessage(data.message ?? null);
            setOuverte(null);
            queryClient.invalidateQueries({ queryKey: ['portail-adhesions'] });
            // Une approbation sans fiche choisie crée un client : les listes de
            // tiers déjà en cache seraient sinon incomplètes.
            queryClient.invalidateQueries({ queryKey: ['tiers'] });
            queryClient.invalidateQueries({ queryKey: ['tiers-clients'] });
            queryClient.invalidateQueries({ queryKey: ['tiers-count'] });
        },
        onError: (err) => {
            setMessage(null);
            setErreur(messageErreur(err, 'Action impossible.'));
        },
    });

    /**
     * Un accès révoqué (ou refusé puis reconsidéré) garde sa fiche client : on la
     * présélectionne, sinon confirmer en créerait une seconde au même nom.
     */
    const ouvrirPanneau = (a: PortailAdhesion) => {
        setOuverte(a.id);
        setClientChoisi(a.client ? String(a.client.id) : '');
    };

    if (isLoading) {
        return <div className="text-sm text-slate-400">Chargement…</div>;
    }

    const toutes = adhesions ?? [];
    const enAttente = toutes.filter((a) => a.statut === 'en_attente');
    const actifs = toutes.filter((a) => a.statut === 'approuve');
    const clos = toutes.filter((a) => a.statut === 'refuse' || a.statut === 'revoque');

    const identite = (a: PortailAdhesion) => (
        <div>
            <div className="text-sm font-medium text-slate-800">{a.acheteur.name ?? '—'}</div>
            <div className="text-xs text-slate-400">
                {a.acheteur.email}
                {a.acheteur.phone && ` · ${a.acheteur.phone}`}
            </div>
        </div>
    );

    const panneau = (a: PortailAdhesion) => (
        <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <label className="block text-xs font-medium text-emerald-900">Compte client à relier</label>
            <div className="mt-2 flex flex-wrap items-center gap-2">
                <select
                    value={clientChoisi}
                    onChange={(e) => setClientChoisi(e.target.value)}
                    className="min-w-64 flex-1 rounded-md border border-emerald-300 bg-white px-3 py-2 text-sm"
                >
                    <option value="">➕ Créer une fiche client à son nom</option>
                    {(clients ?? []).map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.code} — {c.name}
                        </option>
                    ))}
                </select>
                <button
                    disabled={action.isPending}
                    onClick={() =>
                        action.mutate({
                            id: a.id,
                            verbe: 'approuver',
                            tiersId: clientChoisi ? Number(clientChoisi) : null,
                        })
                    }
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                >
                    Confirmer l'accès
                </button>
                <button
                    onClick={() => setOuverte(null)}
                    className="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                >
                    Annuler
                </button>
            </div>
            <p className="mt-2 text-xs text-emerald-800">
                L'acheteur commandera aux conditions de cette fiche : sa catégorie tarifaire, son plafond de crédit,
                son historique.
            </p>
        </div>
    );

    return (
        <div className="max-w-4xl space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Adhésions portail</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Les acheteurs qui demandent l'accès à votre catalogue en ligne. En approuvant, vous les reliez à
                    une fiche client — celle qui porte leurs tarifs.
                </p>
            </div>

            {message && <div className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{message}</div>}
            {erreur && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{erreur}</div>}

            {/* Demandes en attente : la seule partie sur laquelle il y a à décider. */}
            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">
                    Demandes en attente
                    {enAttente.length > 0 && (
                        <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
                            {enAttente.length}
                        </span>
                    )}
                </h2>

                {enAttente.length === 0 ? (
                    <p className="mt-3 text-sm text-slate-400">Aucune demande en attente.</p>
                ) : (
                    <ul className="mt-4 divide-y divide-slate-100">
                        {enAttente.map((a) => (
                            <li key={a.id} className="py-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    {identite(a)}
                                    <div className="flex items-center gap-3">
                                        <span className="text-xs text-slate-400">
                                            Demandé le {jour(a.demande_at)}
                                        </span>
                                        {peutAgir && ouverte !== a.id && (
                                            <>
                                                <button
                                                    onClick={() => ouvrirPanneau(a)}
                                                    className="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-emerald-700"
                                                >
                                                    Approuver
                                                </button>
                                                <button
                                                    onClick={() => {
                                                        if (confirm(`Refuser la demande de ${a.acheteur.name} ?`)) {
                                                            action.mutate({ id: a.id, verbe: 'refuser' });
                                                        }
                                                    }}
                                                    className="text-xs text-red-600 hover:underline"
                                                >
                                                    Refuser
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>
                                {ouverte === a.id && panneau(a)}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {/* Accès actifs */}
            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">Accès actifs ({actifs.length})</h2>

                {actifs.length === 0 ? (
                    <p className="mt-3 text-sm text-slate-400">Aucun acheteur ne commande encore en ligne.</p>
                ) : (
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-100 text-left text-xs uppercase text-slate-400">
                                    <th className="pb-2">Acheteur</th>
                                    <th className="pb-2">Compte client</th>
                                    <th className="pb-2">Depuis le</th>
                                    <th className="pb-2 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-50">
                                {actifs.map((a) => (
                                    <tr key={a.id}>
                                        <td className="py-3">{identite(a)}</td>
                                        <td className="py-3">
                                            {a.client ? (
                                                <Link
                                                    to={`/tiers/${a.client.id}`}
                                                    className="text-emerald-600 hover:underline"
                                                >
                                                    {a.client.code} — {a.client.name}
                                                </Link>
                                            ) : (
                                                <span className="text-slate-400">—</span>
                                            )}
                                        </td>
                                        <td className="py-3 text-slate-600">{jour(a.approuve_at)}</td>
                                        <td className="py-3 text-right">
                                            {peutAgir && (
                                                <button
                                                    onClick={() => {
                                                        if (confirm(`Fermer l'accès de ${a.acheteur.name} ?`)) {
                                                            action.mutate({ id: a.id, verbe: 'revoquer' });
                                                        }
                                                    }}
                                                    className="text-xs text-red-600 hover:underline"
                                                >
                                                    Révoquer
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>

            {/* Demandes closes : refusées ou révoquées, réouvrables. */}
            {clos.length > 0 && (
                <section className="rounded-xl bg-white p-6 shadow-sm">
                    <h2 className="font-medium text-slate-900">Demandes closes ({clos.length})</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Un acheteur refusé ne peut pas redéposer de demande : la réouverture se fait d'ici.
                    </p>
                    <ul className="mt-4 divide-y divide-slate-100">
                        {clos.map((a) => (
                            <li key={a.id} className="py-3">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    {identite(a)}
                                    <div className="flex items-center gap-3">
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-xs font-medium ${ETAT[a.statut].classe}`}
                                        >
                                            {ETAT[a.statut].libelle}
                                        </span>
                                        {peutAgir && ouverte !== a.id && (
                                            <button
                                                onClick={() => ouvrirPanneau(a)}
                                                className="text-xs text-emerald-600 hover:underline"
                                            >
                                                Rouvrir l'accès
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {ouverte === a.id && panneau(a)}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {tenant && toutes.length === 0 && <AdressePortail grossiste={tenant.name} />}
        </div>
    );
}
