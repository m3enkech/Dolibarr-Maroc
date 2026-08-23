import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { Paginated, Produit, Tiers } from '@/types';

interface CategorieTarifaire {
    id: number;
    name: string;
    description: string | null;
    is_default: boolean;
    tarifs_count: number;
}

interface Conditionnement {
    id: number;
    nom: string;
    quantite_base: number;
    barcode: string | null;
    is_default: boolean;
}

interface LigneTarif {
    id: number;
    categorie_tarifaire_id: number | null;
    categorie: string | null;
    tiers_id: number | null;
    client: string | null;
    quantite_min: number;
    prix: string;
}

const errMsg = (err: any, fallback: string): string => {
    const messages = err?.response?.data?.errors;
    if (messages) return (Object.values(messages).flat() as string[]).join(' ');
    return err?.response?.data?.message ?? fallback;
};

export default function Tarifs() {
    const queryClient = useQueryClient();
    const [error, setError] = useState<string | null>(null);
    const [produitId, setProduitId] = useState<string>('');

    /* --- Catégories tarifaires --- */
    const [nom, setNom] = useState('');
    const [parDefaut, setParDefaut] = useState(false);

    const { data: categories } = useQuery({
        queryKey: ['categories-tarifaires'],
        queryFn: async () =>
            (await api.get<{ data: CategorieTarifaire[] }>('/categories-tarifaires')).data.data,
    });

    const { data: produits } = useQuery({
        queryKey: ['tarifs-produits'],
        queryFn: async () =>
            (await api.get<Paginated<Produit>>('/produits', { params: { per_page: 500 } })).data.data,
    });

    const { data: clients } = useQuery({
        queryKey: ['tarifs-clients'],
        queryFn: async () =>
            (await api.get<Paginated<Tiers>>('/tiers', { params: { type: 'client', per_page: 300 } })).data.data,
    });

    const { data: grille } = useQuery({
        queryKey: ['produit-tarifs', produitId],
        queryFn: async () =>
            (await api.get<{ data: LigneTarif[]; prix_catalogue: string }>(`/produits/${produitId}/tarifs`)).data,
        enabled: produitId !== '',
    });

    const creerCategorie = useMutation({
        mutationFn: () => api.post('/categories-tarifaires', { name: nom, is_default: parDefaut }),
        onSuccess: () => {
            setNom('');
            setParDefaut(false);
            setError(null);
            queryClient.invalidateQueries({ queryKey: ['categories-tarifaires'] });
        },
        onError: (err) => setError(errMsg(err, 'Création impossible.')),
    });

    const supprimerCategorie = useMutation({
        mutationFn: (id: number) => api.delete(`/categories-tarifaires/${id}`),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['categories-tarifaires'] }),
    });

    /* --- Lignes de tarif --- */
    const [cible, setCible] = useState(''); // "cat:3" ou "cli:7"
    const [qteMin, setQteMin] = useState('1');
    const [prix, setPrix] = useState('');

    const ajouterTarif = useMutation({
        mutationFn: () => {
            const [genre, id] = cible.split(':');
            return api.post(`/produits/${produitId}/tarifs`, {
                categorie_tarifaire_id: genre === 'cat' ? Number(id) : null,
                tiers_id: genre === 'cli' ? Number(id) : null,
                quantite_min: Number(qteMin),
                prix: Number(prix),
            });
        },
        onSuccess: () => {
            setPrix('');
            setQteMin('1');
            setError(null);
            queryClient.invalidateQueries({ queryKey: ['produit-tarifs', produitId] });
        },
        onError: (err) => setError(errMsg(err, 'Ajout du tarif impossible.')),
    });

    const supprimerTarif = useMutation({
        mutationFn: (id: number) => api.delete(`/tarifs/${id}`),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['produit-tarifs', produitId] }),
    });

    /* --- Conditionnements (carton, palette) --- */
    const [condNom, setCondNom] = useState('');
    const [condQte, setCondQte] = useState('');
    const [condBarcode, setCondBarcode] = useState('');

    const { data: conditionnements } = useQuery({
        queryKey: ['produit-conditionnements', produitId],
        queryFn: async () =>
            (await api.get<{ data: Conditionnement[] }>(`/produits/${produitId}/conditionnements`)).data.data,
        enabled: produitId !== '',
    });

    const rafraichirCond = () =>
        queryClient.invalidateQueries({ queryKey: ['produit-conditionnements', produitId] });

    const ajouterConditionnement = useMutation({
        mutationFn: () =>
            api.post(`/produits/${produitId}/conditionnements`, {
                nom: condNom,
                quantite_base: Number(condQte),
                barcode: condBarcode || null,
            }),
        onSuccess: () => {
            setCondNom('');
            setCondQte('');
            setCondBarcode('');
            setError(null);
            rafraichirCond();
        },
        onError: (err) => setError(errMsg(err, 'Ajout du conditionnement impossible.')),
    });

    const supprimerConditionnement = useMutation({
        mutationFn: (id: number) => api.delete(`/conditionnements/${id}`),
        onSuccess: rafraichirCond,
    });

    const champ = 'rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none';

    return (
        <div className="space-y-4">
            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            {/* Catégories tarifaires */}
            <section className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="font-medium text-slate-900">Niveaux de tarif</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Créez vos niveaux — par exemple <strong>Gros</strong>, <strong>Demi-gros</strong> et{' '}
                    <strong>Détail</strong>. Le niveau par défaut s'applique aux clients qui n'en ont pas.
                </p>

                <div className="mt-4 flex flex-wrap items-center gap-3">
                    <input
                        value={nom}
                        onChange={(e) => setNom(e.target.value)}
                        placeholder="Nom du niveau"
                        className={champ}
                    />
                    <label className="flex items-center gap-2 text-sm text-slate-600">
                        <input
                            type="checkbox"
                            checked={parDefaut}
                            onChange={(e) => setParDefaut(e.target.checked)}
                            className="rounded border-slate-300"
                        />
                        Par défaut
                    </label>
                    <button
                        onClick={() => creerCategorie.mutate()}
                        disabled={!nom || creerCategorie.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                    >
                        Ajouter
                    </button>
                </div>

                <div className="mt-4 flex flex-wrap gap-2">
                    {(categories ?? []).map((c) => (
                        <span
                            key={c.id}
                            className={`flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm ${
                                c.is_default
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                    : 'border-slate-200 text-slate-700'
                            }`}
                        >
                            {c.name}
                            {c.is_default && <span className="text-xs text-emerald-600">défaut</span>}
                            <span className="text-xs text-slate-400">{c.tarifs_count} prix</span>
                            <button
                                onClick={() => confirm(`Supprimer « ${c.name} » et ses prix ?`) && supprimerCategorie.mutate(c.id)}
                                className="text-slate-300 transition hover:text-red-500"
                            >
                                ✕
                            </button>
                        </span>
                    ))}
                    {(categories ?? []).length === 0 && (
                        <span className="text-sm text-slate-400">Aucun niveau. Commencez par en créer un.</span>
                    )}
                </div>
            </section>

            {/* Grille par article */}
            <section className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="font-medium text-slate-900">Prix par article</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Choisissez un article, puis fixez son prix par niveau ou pour un client précis. La{' '}
                    <strong>quantité minimum</strong> crée un palier dégressif.
                </p>

                <select
                    value={produitId}
                    onChange={(e) => setProduitId(e.target.value)}
                    className={`mt-4 w-full max-w-md ${champ}`}
                >
                    <option value="">Choisir un article…</option>
                    {(produits ?? []).map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.code} — {p.name}
                        </option>
                    ))}
                </select>

                {produitId !== '' && grille && (
                    <>
                        <div className="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            Prix catalogue : <strong>{formatMAD(grille.prix_catalogue)}</strong> — appliqué quand aucun
                            tarif ne correspond.
                        </div>

                        <div className="mt-3 flex flex-wrap items-end gap-3">
                            <div>
                                <label className="mb-1 block text-xs text-slate-500">Pour</label>
                                <select value={cible} onChange={(e) => setCible(e.target.value)} className={champ}>
                                    <option value="">Choisir…</option>
                                    <optgroup label="Niveau de tarif">
                                        {(categories ?? []).map((c) => (
                                            <option key={`cat-${c.id}`} value={`cat:${c.id}`}>{c.name}</option>
                                        ))}
                                    </optgroup>
                                    <optgroup label="Client précis">
                                        {(clients ?? []).map((c) => (
                                            <option key={`cli-${c.id}`} value={`cli:${c.id}`}>{c.name}</option>
                                        ))}
                                    </optgroup>
                                </select>
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-slate-500">À partir de (qté)</label>
                                <input
                                    type="number"
                                    min="0.001"
                                    step="any"
                                    value={qteMin}
                                    onChange={(e) => setQteMin(e.target.value)}
                                    className={`w-32 ${champ}`}
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-slate-500">Prix unitaire HT</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    value={prix}
                                    onChange={(e) => setPrix(e.target.value)}
                                    className={`w-36 ${champ}`}
                                />
                            </div>
                            <button
                                onClick={() => ajouterTarif.mutate()}
                                disabled={!cible || !prix || ajouterTarif.isPending}
                                className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                            >
                                Enregistrer le prix
                            </button>
                        </div>

                        <div className="mt-5 overflow-hidden rounded-lg border border-slate-200">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-4 py-2">Pour</th>
                                        <th className="px-4 py-2 text-right">À partir de</th>
                                        <th className="px-4 py-2 text-right">Prix HT</th>
                                        <th className="px-4 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {grille.data.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-6 text-center text-slate-400">
                                                Aucun tarif : cet article se vend au prix catalogue.
                                            </td>
                                        </tr>
                                    )}
                                    {grille.data.map((t) => (
                                        <tr key={t.id} className="hover:bg-slate-50">
                                            <td className="px-4 py-2">
                                                {t.client ? (
                                                    <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700">
                                                        {t.client}
                                                    </span>
                                                ) : (
                                                    <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700">
                                                        {t.categorie}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums text-slate-600">
                                                {t.quantite_min}
                                            </td>
                                            <td className="px-4 py-2 text-right font-medium tabular-nums text-slate-800">
                                                {formatMAD(t.prix)}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                <button
                                                    onClick={() => supprimerTarif.mutate(t.id)}
                                                    className="text-xs text-slate-400 hover:text-red-600"
                                                >
                                                    Supprimer
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </section>

            {/* Conditionnements de l'article sélectionné */}
            {produitId !== '' && (
                <section className="rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="font-medium text-slate-900">Conditionnements</h2>
                    <p className="mt-1 text-sm text-slate-500">
                        Vendez au carton ou à la palette : le stock reste tenu à l'unité. Un code-barres par colis
                        permet de le scanner en caisse.
                    </p>

                    <div className="mt-4 flex flex-wrap items-end gap-3">
                        <div>
                            <label className="mb-1 block text-xs text-slate-500">Nom</label>
                            <input
                                value={condNom}
                                onChange={(e) => setCondNom(e.target.value)}
                                placeholder="Carton de 12"
                                className={champ}
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs text-slate-500">Contient (unités)</label>
                            <input
                                type="number"
                                step="any"
                                min="0.001"
                                value={condQte}
                                onChange={(e) => setCondQte(e.target.value)}
                                className={`w-32 ${champ}`}
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs text-slate-500">Code-barres (optionnel)</label>
                            <input
                                value={condBarcode}
                                onChange={(e) => setCondBarcode(e.target.value)}
                                className={champ}
                            />
                        </div>
                        <button
                            onClick={() => ajouterConditionnement.mutate()}
                            disabled={!condNom || !condQte || ajouterConditionnement.isPending}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            Ajouter
                        </button>
                    </div>

                    <div className="mt-4 flex flex-wrap gap-2">
                        {(conditionnements ?? []).map((c) => (
                            <span
                                key={c.id}
                                className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-700"
                            >
                                <strong>{c.nom}</strong>
                                <span className="text-xs text-slate-500">= {c.quantite_base} unités</span>
                                {c.barcode && <span className="font-mono text-xs text-slate-400">{c.barcode}</span>}
                                <button
                                    onClick={() => supprimerConditionnement.mutate(c.id)}
                                    className="text-slate-300 transition hover:text-red-500"
                                >
                                    ✕
                                </button>
                            </span>
                        ))}
                        {(conditionnements ?? []).length === 0 && (
                            <span className="text-sm text-slate-400">
                                Aucun colis : cet article se vend à l'unité.
                            </span>
                        )}
                    </div>
                </section>
            )}
        </div>
    );
}
