import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { TYPE_LABELS } from '@/pages/ventes/common';
import type { DocumentType, DocumentVente, Paginated, Produit, Tiers } from '@/types';

const TVA_RATES = ['20', '14', '10', '7', '0'];

interface LigneForm {
    /**
     * Identifiant LOCAL, jamais envoyé au serveur (handleSubmit énumère les
     * champs un par un). Il remplace `key={index}` : par position, React
     * réutilisait les champs, si bien que supprimer une ligne du milieu faisait
     * remonter les valeurs de la suivante dans le champ où était le curseur. Il
     * sert aussi de suffixe aux `htmlFor` des libellés de carte.
     */
    uid: string;
    produit_id: string;
    designation: string;
    quantite: string;
    prix_unitaire: string;
    remise_percent: string;
    tva_rate: string;
}

// Une fabrique, pas un objet partagé : un uid figé dans une constante recopiée
// donnerait le même identifiant à toutes les lignes.
let compteurLigne = 0;
const nouvelleLigne = (): LigneForm => ({
    uid: `v${++compteurLigne}`,
    produit_id: '',
    designation: '',
    quantite: '1',
    prix_unitaire: '0',
    remise_percent: '0',
    tva_rate: '20',
});

function ligneHt(ligne: LigneForm): number {
    const qty = parseFloat(ligne.quantite || '0');
    const pu = parseFloat(ligne.prix_unitaire || '0');
    const remise = parseFloat(ligne.remise_percent || '0');
    return Math.round(qty * pu * (1 - remise / 100) * 100) / 100;
}

export default function VenteForm() {
    const { id } = useParams();
    const isEdit = id !== undefined;
    const [searchParams] = useSearchParams();
    const navigate = useNavigate();
    const queryClient = useQueryClient();

    const [type, setType] = useState<DocumentType>(
        (['devis', 'commande', 'bon_livraison', 'facture', 'avoir'].includes(searchParams.get('type') ?? '')
            ? searchParams.get('type')
            : 'devis') as DocumentType,
    );
    const [tiersId, setTiersId] = useState('');
    const [dateDocument, setDateDocument] = useState(() => new Date().toISOString().slice(0, 10));
    const [dateEcheance, setDateEcheance] = useState('');
    const [notes, setNotes] = useState('');
    const [lignes, setLignes] = useState<LigneForm[]>(() => [nouvelleLigne()]);
    const [error, setError] = useState<string | null>(null);

    const { data: tiersList } = useQuery({
        queryKey: ['tiers-options'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Tiers>>('/tiers', { params: { per_page: 200 } });
            return data.data;
        },
    });

    const { data: produits } = useQuery({
        queryKey: ['produits-options'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Produit>>('/produits', { params: { per_page: 200 } });
            return data.data;
        },
    });

    const { data: existing } = useQuery({
        queryKey: ['vente-detail', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: DocumentVente }>(`/ventes/documents/${id}`);
            return data.data;
        },
        enabled: isEdit,
    });

    useEffect(() => {
        if (existing) {
            setType(existing.type);
            setTiersId(String(existing.tiers_id));
            setDateDocument(existing.date_document);
            setDateEcheance(existing.date_echeance ?? '');
            setNotes(existing.notes ?? '');
            setLignes(
                (existing.lignes ?? []).map((l) => ({
                    uid: nouvelleLigne().uid,
                    produit_id: l.produit_id ? String(l.produit_id) : '',
                    designation: l.designation,
                    quantite: String(parseFloat(l.quantite)),
                    prix_unitaire: l.prix_unitaire,
                    remise_percent: String(parseFloat(l.remise_percent)),
                    tva_rate: String(parseFloat(l.tva_rate)),
                })),
            );
        }
    }, [existing]);

    const setLigne = (index: number, patch: Partial<LigneForm>) =>
        setLignes((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    const onProduitChange = (index: number, produitId: string) => {
        const produit = produits?.find((p) => String(p.id) === produitId);
        if (produit) {
            setLigne(index, {
                produit_id: produitId,
                designation: produit.name,
                prix_unitaire: produit.sell_price,
                tva_rate: String(parseFloat(produit.tva_rate)),
            });
        } else {
            setLigne(index, { produit_id: '' });
        }
    };

    const totalHt = lignes.reduce((sum, l) => sum + ligneHt(l), 0);
    const totalTva = lignes.reduce(
        (sum, l) => sum + Math.round(ligneHt(l) * parseFloat(l.tva_rate || '0')) / 100,
        0,
    );

    const mutation = useMutation({
        mutationFn: (payload: Record<string, unknown>) =>
            isEdit
                ? api.put(`/ventes/documents/${id}`, payload)
                : api.post('/ventes/documents', payload),
        onSuccess: ({ data }) => {
            queryClient.invalidateQueries({ queryKey: ['ventes'] });
            queryClient.invalidateQueries({ queryKey: ['vente-detail'] });
            navigate(`/ventes/${data.data.id}`);
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
        const payload: Record<string, unknown> = {
            tiers_id: parseInt(tiersId, 10),
            date_document: dateDocument,
            date_echeance: dateEcheance || null,
            notes: notes || null,
            lignes: lignes.map((l) => ({
                produit_id: l.produit_id ? parseInt(l.produit_id, 10) : null,
                designation: l.designation || null,
                quantite: parseFloat(l.quantite || '0'),
                prix_unitaire: parseFloat(l.prix_unitaire || '0'),
                remise_percent: parseFloat(l.remise_percent || '0'),
                tva_rate: parseFloat(l.tva_rate),
            })),
        };
        if (!isEdit) {
            payload.type = type;
        }
        mutation.mutate(payload);
    };

    const input =
        'w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
    const label = 'mb-1 block text-sm font-medium text-slate-700';

    /*
     * Saisie des lignes : une SEULE arborescence de champs, empilée en carte
     * par défaut, remise en rangée à partir de xl.
     *
     * Pourquoi pas un tableau `hidden xl:block` doublé de cartes `xl:hidden` —
     * le patron le plus courant : trois champs de ligne portent `required`, et
     * un champ requis dans un conteneur en `display:none` fait refuser la
     * soumission par Chrome (« invalid form control is not focusable ») SANS
     * afficher le moindre message.
     *
     * Pourquoi xl (1280) et non sm (640) : la barre latérale prend 256 px, le
     * formulaire est plafonné à `max-w-5xl`, et la rangée à huit colonnes
     * réclame ~1150 px. En dessous de 1280 elle défilait déjà latéralement sur
     * un poste de bureau — c'est précisément pourquoi ce bloc portait un
     * `overflow-x-auto`.
     */
    const champLigne =
        'w-full min-w-0 rounded-md border border-slate-300 px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 xl:px-2 xl:py-1.5';

    // L'intitulé est porté par chaque champ : le <table> donnait ce nom
    // accessible par l'association <th>/<td> ; en le quittant, il faut de vrais
    // <label>. En rangée ils restent annoncés mais cessent d'être affichés.
    const labelLigne = 'mb-1 block text-xs font-medium text-slate-600 xl:sr-only';

    // Gabarit COMMUN à l'en-tête de colonnes et aux rangées : c'est lui qui
    // porte les largeurs, à la place des `style={{ width }}` du <thead>. Un
    // seul endroit à corriger, donc aucun désalignement possible.
    const gabaritLigne =
        'xl:grid xl:grid-cols-[minmax(0,1.6fr)_minmax(0,2.2fr)_5.5rem_6.5rem_5rem_5rem_6.5rem_2.5rem] xl:items-center xl:gap-2';

    return (
        <div className="max-w-5xl space-y-4">
            <div>
                <Link to={isEdit ? `/ventes/${id}` : '/ventes'} className="text-sm text-emerald-600 hover:underline">
                    ← Retour
                </Link>
                <h1 className="mt-2 text-xl font-semibold text-slate-900">
                    {isEdit ? `Modifier ${existing?.code ?? ''}` : `Nouveau ${TYPE_LABELS[type].toLowerCase()}`}
                </h1>
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label className={label}>Client *</label>
                            <select required value={tiersId} onChange={(e) => setTiersId(e.target.value)} className={input}>
                                <option value="">— Choisir —</option>
                                {tiersList?.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={label}>Date</label>
                            <input type="date" value={dateDocument} onChange={(e) => setDateDocument(e.target.value)} className={input} />
                        </div>
                        <div>
                            <label className={label}>Échéance</label>
                            <input type="date" value={dateEcheance} onChange={(e) => setDateEcheance(e.target.value)} className={input} />
                        </div>
                    </div>
                </div>

                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <h2 className="mb-4 font-medium text-slate-900">Lignes</h2>

                    <div className="space-y-3 xl:space-y-0">
                        {/* En-tête de colonnes : n'existe qu'en mode rangée. */}
                        <div className={`hidden pb-2 text-xs uppercase tracking-wide text-slate-500 ${gabaritLigne}`}>
                            <span>Produit</span>
                            <span>Désignation *</span>
                            <span className="text-right">Qté</span>
                            <span className="text-right">P.U. HT</span>
                            <span className="text-right">Remise %</span>
                            <span className="text-right">TVA</span>
                            <span className="text-right">Total HT</span>
                            <span />
                        </div>

                        {lignes.map((ligne, index) => (
                            <div
                                key={ligne.uid}
                                className={`grid grid-cols-2 gap-3 rounded-lg border border-slate-200 p-3 xl:rounded-none xl:border-x-0 xl:border-b-0 xl:border-slate-100 xl:p-0 xl:py-2 ${gabaritLigne}`}
                            >
                                {/* Repère de carte : en rangée, la position se lit d'elle-même. */}
                                <div className="col-span-2 text-xs font-medium uppercase tracking-wide text-slate-400 xl:hidden">
                                    Ligne {index + 1}
                                </div>

                                {/* L'ordre du DOM est l'ordre de tabulation du vendeur :
                                    produit → désignation → qté → P.U. → remise → TVA →
                                    supprimer. Ne jamais le réordonner avec `order-*`. */}
                                <div className="col-span-2 min-w-0 xl:col-span-1">
                                    <label htmlFor={`ligne-produit-${ligne.uid}`} className={labelLigne}>
                                        Produit
                                    </label>
                                    <select
                                        id={`ligne-produit-${ligne.uid}`}
                                        value={ligne.produit_id}
                                        onChange={(e) => onProduitChange(index, e.target.value)}
                                        className={champLigne}
                                    >
                                        <option value="">Ligne libre</option>
                                        {produits?.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="col-span-2 min-w-0 xl:col-span-1">
                                    <label htmlFor={`ligne-designation-${ligne.uid}`} className={labelLigne}>
                                        Désignation *
                                    </label>
                                    <input
                                        id={`ligne-designation-${ligne.uid}`}
                                        required
                                        value={ligne.designation}
                                        onChange={(e) => setLigne(index, { designation: e.target.value })}
                                        className={champLigne}
                                    />
                                </div>

                                {/* `inputMode="decimal"` fait sortir le pavé numérique du
                                    téléphone ; `type="number"` reste, il porte step et min. */}
                                <div className="min-w-0">
                                    <label htmlFor={`ligne-quantite-${ligne.uid}`} className={labelLigne}>
                                        Qté
                                    </label>
                                    <input
                                        id={`ligne-quantite-${ligne.uid}`}
                                        type="number"
                                        inputMode="decimal"
                                        step="0.001"
                                        min="0.001"
                                        required
                                        value={ligne.quantite}
                                        onChange={(e) => setLigne(index, { quantite: e.target.value })}
                                        className={`${champLigne} text-right`}
                                    />
                                </div>

                                <div className="min-w-0">
                                    <label htmlFor={`ligne-prix-${ligne.uid}`} className={labelLigne}>
                                        P.U. HT
                                    </label>
                                    <input
                                        id={`ligne-prix-${ligne.uid}`}
                                        type="number"
                                        inputMode="decimal"
                                        step="0.01"
                                        min="0"
                                        required
                                        value={ligne.prix_unitaire}
                                        onChange={(e) => setLigne(index, { prix_unitaire: e.target.value })}
                                        className={`${champLigne} text-right`}
                                    />
                                </div>

                                <div className="min-w-0">
                                    <label htmlFor={`ligne-remise-${ligne.uid}`} className={labelLigne}>
                                        Remise %
                                    </label>
                                    <input
                                        id={`ligne-remise-${ligne.uid}`}
                                        type="number"
                                        inputMode="decimal"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        value={ligne.remise_percent}
                                        onChange={(e) => setLigne(index, { remise_percent: e.target.value })}
                                        className={`${champLigne} text-right`}
                                    />
                                </div>

                                <div className="min-w-0">
                                    <label htmlFor={`ligne-tva-${ligne.uid}`} className={labelLigne}>
                                        TVA
                                    </label>
                                    <select
                                        id={`ligne-tva-${ligne.uid}`}
                                        value={ligne.tva_rate}
                                        onChange={(e) => setLigne(index, { tva_rate: e.target.value })}
                                        className={champLigne}
                                    >
                                        {TVA_RATES.map((rate) => (
                                            <option key={rate} value={rate}>
                                                {rate} %
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                {/* `xl:contents` dissout ce pied de carte : ses deux enfants
                                    redeviennent les 7e et 8e cellules de la rangée, et le
                                    bouton reste le dernier nœud focusable de la ligne.
                                    Le total reste visible en carte : c'est le seul retour
                                    immédiat sur l'effet d'une remise. */}
                                <div className="col-span-2 flex items-center justify-between gap-3 border-t border-slate-100 pt-3 xl:contents">
                                    <div className="min-w-0 xl:text-right">
                                        <span className="text-xs text-slate-500 xl:sr-only">Total HT </span>
                                        <span className="font-medium tabular-nums text-slate-700">
                                            {formatMAD(ligneHt(ligne))}
                                        </span>
                                    </div>
                                    <div className="xl:text-right">
                                        <button
                                            type="button"
                                            onClick={() => setLignes((prev) => prev.filter((_, i) => i !== index))}
                                            disabled={lignes.length === 1}
                                            aria-label="Supprimer la ligne"
                                            title={
                                                lignes.length === 1
                                                    ? 'Un document garde au moins une ligne'
                                                    : 'Supprimer la ligne'
                                            }
                                            className="inline-flex h-10 min-w-10 items-center justify-center gap-1.5 rounded-md px-3 text-sm text-red-500 transition hover:bg-red-50 hover:text-red-700 disabled:opacity-30 xl:px-0"
                                        >
                                            <span aria-hidden="true">✕</span>
                                            <span className="xl:hidden">Supprimer</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>

                    <button
                        type="button"
                        onClick={() => setLignes((prev) => [...prev, nouvelleLigne()])}
                        className="mt-3 w-full rounded-md border border-dashed border-slate-300 px-3 py-2.5 text-sm text-slate-600 transition hover:border-emerald-500 hover:text-emerald-600 xl:w-auto xl:py-1.5"
                    >
                        + Ajouter une ligne
                    </button>

                    <div className="mt-4 flex justify-end">
                        <div className="w-full space-y-1 rounded-md bg-slate-50 p-4 text-sm sm:w-64">
                            <div className="flex justify-between text-slate-600">
                                <span>Total HT</span>
                                <span className="tabular-nums">{formatMAD(totalHt)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600">
                                <span>TVA</span>
                                <span className="tabular-nums">{formatMAD(totalTva)}</span>
                            </div>
                            <div className="flex justify-between border-t border-slate-200 pt-1 font-semibold text-slate-900">
                                <span>Total TTC</span>
                                <span className="tabular-nums">{formatMAD(totalHt + totalTva)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="rounded-xl bg-white p-5 shadow-sm">
                    <label className={label}>Notes</label>
                    <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} className={input} />
                </div>

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={mutation.isPending || !tiersId}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {mutation.isPending ? 'Enregistrement…' : 'Enregistrer le brouillon'}
                    </button>
                    <Link
                        to={isEdit ? `/ventes/${id}` : '/ventes'}
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        Annuler
                    </Link>
                </div>
            </form>
        </div>
    );
}
