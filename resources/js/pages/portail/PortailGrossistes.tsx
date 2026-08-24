import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { messageErreur, portailApi } from '@/lib/portail-api';

interface Rattachement {
    grossiste: string | null;
    slug: string | null;
    statut: 'en_attente' | 'approuve' | 'refuse' | 'revoque';
    demande_at: string | null;
    approuve_at: string | null;
}

const ETAT: Record<string, { libelle: string; classe: string; aide: string }> = {
    approuve: {
        libelle: 'Accès actif',
        classe: 'bg-emerald-100 text-emerald-700',
        aide: 'Vous pouvez commander.',
    },
    en_attente: {
        libelle: 'En attente',
        classe: 'bg-amber-100 text-amber-700',
        aide: 'Le grossiste doit valider votre demande.',
    },
    refuse: {
        libelle: 'Refusée',
        classe: 'bg-red-100 text-red-700',
        aide: 'Contactez le grossiste directement.',
    },
    revoque: {
        libelle: 'Accès fermé',
        classe: 'bg-slate-200 text-slate-600',
        aide: 'Votre accès a été retiré.',
    },
};

export default function PortailGrossistes() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [slug, setSlug] = useState('');
    const [erreur, setErreur] = useState<string | null>(null);
    const [message, setMessage] = useState<string | null>(null);

    const { data: mes, isLoading } = useQuery({
        queryKey: ['portail-mes-grossistes'],
        queryFn: async () => (await portailApi.get<{ data: Rattachement[] }>('/mes-grossistes')).data.data,
    });

    const { data: annuaire } = useQuery({
        queryKey: ['portail-annuaire'],
        queryFn: async () =>
            (await portailApi.get<{ data: { name: string; slug: string }[] }>('/annuaire')).data.data,
    });

    const demander = useMutation({
        mutationFn: () => portailApi.post('/demander-acces', { slug }),
        onSuccess: ({ data }) => {
            setErreur(null);
            setMessage(data.data.message);
            setSlug('');
            queryClient.invalidateQueries({ queryKey: ['portail-mes-grossistes'] });
        },
        onError: (err) => {
            setMessage(null);
            setErreur(messageErreur(err, 'Demande impossible.'));
        },
    });

    // On ne propose que les grossistes auxquels l'acheteur n'est pas déjà lié.
    const dejaDemandes = new Set((mes ?? []).map((r) => r.slug));
    const disponibles = (annuaire ?? []).filter((g) => !dejaDemandes.has(g.slug));

    return (
        <div className="space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Mes grossistes</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Choisissez un grossiste pour voir son catalogue et passer commande.
                </p>
            </div>

            {message && <div className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{message}</div>}
            {erreur && <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{erreur}</div>}

            {isLoading && <p className="text-sm text-slate-400">Chargement…</p>}

            <div className="grid gap-3 sm:grid-cols-2">
                {(mes ?? []).map((r) => {
                    const etat = ETAT[r.statut];
                    const actif = r.statut === 'approuve';

                    return (
                        <button
                            key={r.slug ?? r.grossiste}
                            disabled={!actif}
                            onClick={() => navigate(`/portail/${r.slug}/catalogue`)}
                            className={`rounded-xl border bg-white p-4 text-left shadow-sm transition ${
                                actif
                                    ? 'border-slate-200 hover:border-emerald-400 hover:shadow'
                                    : 'border-slate-200 opacity-70'
                            }`}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <span className="font-medium text-slate-900">{r.grossiste}</span>
                                <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${etat.classe}`}>
                                    {etat.libelle}
                                </span>
                            </div>
                            <p className="mt-1 text-xs text-slate-500">{etat.aide}</p>
                            {actif && <p className="mt-3 text-sm font-medium text-emerald-600">Voir le catalogue →</p>}
                        </button>
                    );
                })}

                {!isLoading && (mes ?? []).length === 0 && (
                    <div className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 sm:col-span-2">
                        Vous n'êtes encore rattaché à aucun grossiste. Demandez un accès ci-dessous.
                    </div>
                )}
            </div>

            <section className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="font-medium text-slate-900">Demander un accès</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Le grossiste validera votre demande avant de vous ouvrir son catalogue.
                </p>

                <div className="mt-4 flex flex-wrap gap-3">
                    <select
                        value={slug}
                        onChange={(e) => setSlug(e.target.value)}
                        className="min-w-56 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
                    >
                        <option value="">Choisir un grossiste…</option>
                        {disponibles.map((g) => (
                            <option key={g.slug} value={g.slug}>{g.name}</option>
                        ))}
                    </select>
                    <button
                        onClick={() => demander.mutate()}
                        disabled={!slug || demander.isPending}
                        className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {demander.isPending ? 'Envoi…' : 'Envoyer la demande'}
                    </button>
                </div>

                {disponibles.length === 0 && (annuaire ?? []).length > 0 && (
                    <p className="mt-3 text-xs text-slate-400">
                        Vous avez déjà une demande auprès de tous les grossistes référencés.
                    </p>
                )}
            </section>
        </div>
    );
}
