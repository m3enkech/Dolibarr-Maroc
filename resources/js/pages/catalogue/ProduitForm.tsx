import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import SelecteurProduit, { type ProduitChoisi } from '@/components/SelecteurProduit';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatMAD } from '@/lib/format';
import type { CategorieProduit, Entrepot, Paginated, Produit, ProduitDepots } from '@/types';
import { useT } from '@/lib/langue';

const TVA_RATES = [20, 14, 10, 7, 0] as const;

/**
 * Champs du stock initial : leurs refus s'affichent sous eux, juste au-dessus
 * du bouton qu'on vient de presser, et non dans le bandeau du haut de page —
 * hors de vue sur un téléphone.
 */
const CHAMPS_STOCK = ['stock_initial', 'entrepot_id'];

interface ComposantRow {
    produit_id: string;
    quantite: string;
    /** Libellé du composant (référence, nom) : local, jamais envoyé. */
    connu: ProduitChoisi | null;
}

interface ProduitFormData {
    name: string;
    description: string;
    type: 'product' | 'service' | 'kit';
    categorie_produit_id: string;
    sell_price: string;
    buy_price: string;
    tva_rate: string;
    unit: string;
    stock_min: string;
    stock_reappro: string;
    barcode: string;
    is_active: boolean;
}

const emptyForm: ProduitFormData = {
    name: '',
    description: '',
    type: 'product',
    categorie_produit_id: '',
    sell_price: '',
    buy_price: '',
    tva_rate: '20',
    unit: '',
    stock_min: '',
    stock_reappro: '',
    barcode: '',
    is_active: true,
};

function toPayload(form: ProduitFormData, isEdit: boolean) {
    const payload: Record<string, unknown> = {
        name: form.name,
        description: form.description || null,
        sell_price: parseFloat(form.sell_price || '0'),
        buy_price: form.buy_price === '' ? null : parseFloat(form.buy_price),
        tva_rate: parseFloat(form.tva_rate),
        categorie_produit_id: form.categorie_produit_id ? parseInt(form.categorie_produit_id, 10) : null,
        unit: form.unit || null,
        // Seuils de réappro : uniquement pertinents pour les produits stockables.
        stock_min: form.type === 'product' && form.stock_min !== '' ? parseFloat(form.stock_min) : null,
        stock_reappro: form.type === 'product' && form.stock_reappro !== '' ? parseFloat(form.stock_reappro) : null,
        barcode: form.barcode || null,
        is_active: form.is_active,
    };
    if (!isEdit) {
        payload.type = form.type;
    }
    return payload;
}

export default function ProduitForm() {
    const t = useT();
    const { id } = useParams();
    const isEdit = id !== undefined;
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [form, setForm] = useState<ProduitFormData>(emptyForm);
    const [composants, setComposants] = useState<ComposantRow[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [erreursChamps, setErreursChamps] = useState<Record<string, string[]>>({});
    const { can } = useAuth();

    // Stock de départ : à la création seulement — ensuite, le stock se corrige
    // par un mouvement ou un inventaire, qui laissent une trace — et pour qui
    // peut écrire le stock. Le serveur refuse la quantité aux autres : autant
    // ne pas leur proposer un champ voué au refus.
    const peutAlimenter = !isEdit && can('stock', 'write');
    const [stockInitial, setStockInitial] = useState('');
    const [depotChoisi, setDepotChoisi] = useState('');
    /** Enregistrement retenu faute de liste des dépôts (voir handleSubmit). */
    const [envoiRetenu, setEnvoiRetenu] = useState(false);
    const idAideStock = useId();
    const idQuantite = useId();
    const idUnite = useId();
    const idErreurQuantite = useId();
    const idDepot = useId();
    const idErreurDepot = useId();
    const refQuantite = useRef<HTMLInputElement>(null);
    const refDepot = useRef<HTMLSelectElement>(null);

    // Même clé que l'écran Stock : la liste des dépôts y est déjà en cache.
    const {
        data: entrepots,
        isError: depotsEnEchec,
        isFetching: depotsEnLecture,
        refetch: relireDepots,
    } = useQuery({
        queryKey: ['entrepots'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Entrepot[] }>('/stock/entrepots');
            return data.data;
        },
        enabled: peutAlimenter,
    });

    const depotsActifs = (entrepots ?? []).filter((e) => e.is_active);
    // Présélection : le dépôt par défaut s'il est actif, sinon le premier actif.
    // Le dépôt affiché est TOUJOURS envoyé : laissé au serveur, un dépôt par
    // défaut désactivé serait refusé alors que l'écran en proposait un autre.
    const depotPropose = depotsActifs.find((e) => e.is_default) ?? depotsActifs[0] ?? null;
    const depotRetenu = depotChoisi !== '' ? depotChoisi : depotPropose ? String(depotPropose.id) : '';
    // Des dépôts, tous désactivés : le champ de quantité cède la place à une
    // consigne. Seul ce qui est à l'écran part au serveur — une quantité tapée
    // avant que la liste ne bascule ne doit pas partir en cachette.
    const tousDepotsInactifs = entrepots !== undefined && entrepots.length > 0 && depotsActifs.length === 0;
    const champStockAffiche = peutAlimenter && form.type === 'product' && !tousDepotsInactifs;

    // Une erreur du stock initial s'affiche sous son champ, en bas de page :
    // le focus y va, pour qu'un lecteur d'écran lise le champ et son message
    // (aria-describedby, posé par le rendu qui précède cet effet).
    useEffect(() => {
        if (erreursChamps.stock_initial) {
            refQuantite.current?.focus();
        } else if (erreursChamps.entrepot_id) {
            refDepot.current?.focus();
        }
    }, [erreursChamps]);

    const { data: existing } = useQuery({
        queryKey: ['produit-detail', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Produit }>(`/produits/${id}`);
            return data.data;
        },
        enabled: isEdit,
    });

    // En modification, le stock se lit sans se saisir. Même clé que le détail
    // par dépôt de l'écran de suivi, qui lit la même route.
    const { data: stockActuel } = useQuery({
        queryKey: ['pilotage', 'depots', Number(id)],
        queryFn: async () => {
            const { data } = await api.get<{ data: ProduitDepots }>(`/stock/produits/${id}/depots`);
            return data.data;
        },
        enabled: isEdit && existing?.type === 'product' && can('stock', 'read'),
        staleTime: 30_000,
    });

    const { data: categories } = useQuery({
        queryKey: ['categories-produit'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<CategorieProduit>>('/categories-produit');
            return data.data;
        },
    });

    // Composition d'un kit : produits et services, jamais un kit. Ils se
    // CHERCHENT désormais (SelecteurProduit, filtre `product,service` côté
    // serveur) : la liste chargée d'avance s'arrêtait aux 500 premiers
    // articles. Le kit lui-même est exclu d'office, puisque c'est un kit.

    useEffect(() => {
        if (existing) {
            setForm({
                name: existing.name,
                description: existing.description ?? '',
                type: existing.type,
                categorie_produit_id: existing.categorie_produit_id ? String(existing.categorie_produit_id) : '',
                sell_price: existing.sell_price,
                buy_price: existing.buy_price ?? '',
                tva_rate: String(parseFloat(existing.tva_rate)),
                unit: existing.unit ?? '',
                stock_min: existing.stock_min !== null ? String(parseFloat(existing.stock_min)) : '',
                stock_reappro: existing.stock_reappro !== null ? String(parseFloat(existing.stock_reappro)) : '',
                barcode: existing.barcode ?? '',
                is_active: existing.is_active,
            });
            setComposants(
                (existing.composants ?? []).map((c) => ({
                    produit_id: String(c.produit_id),
                    quantite: String(parseFloat(c.quantite)),
                    // Nom et référence arrivent avec le kit : aucun composant
                    // n'est relu un par un.
                    connu: c.name !== null ? { id: c.produit_id, name: c.name, code: c.code } : null,
                })),
            );
        }
    }, [existing]);

    const mutation = useMutation({
        mutationFn: (payload: Record<string, unknown>) =>
            isEdit ? api.put(`/produits/${id}`, payload) : api.post('/produits', payload),
        onSuccess: (_reponse, payload) => {
            queryClient.invalidateQueries({ queryKey: ['produits'] });
            queryClient.invalidateQueries({ queryKey: ['produits-count'] });
            // Les recherches des sélecteurs d'article (et la fiche relue d'un
            // article, gardée une minute) : un article créé ou renommé doit y
            // paraître tout de suite, même dans une liste déjà ouverte.
            queryClient.invalidateQueries({ queryKey: ['selecteur-produit'] });
            queryClient.invalidateQueries({ queryKey: ['selecteur-produit-fiche'] });
            // Un stock initial a bougé les niveaux, l'historique, le suivi — et
            // peut-être créé le dépôt principal d'une entreprise qui n'en avait pas.
            if ('stock_initial' in payload) {
                for (const cle of ['stock-niveaux', 'stock-mouvements', 'stock-alertes', 'pilotage', 'entrepots']) {
                    queryClient.invalidateQueries({ queryKey: [cle] });
                }
            }
            navigate('/catalogue');
        },
        onError: (err: any) => {
            const erreurs: Record<string, string[]> = err?.response?.data?.errors ?? {};
            setErreursChamps(erreurs);
            // Un refus du stock dont le champ n'est plus à l'écran (la liste
            // des dépôts a basculé pendant l'envoi) revient au bandeau : un
            // message qui ne s'affiche nulle part serait un clic sans réponse.
            const autres = Object.entries(erreurs)
                .filter(([champ]) => !(champStockAffiche && CHAMPS_STOCK.includes(champ)))
                .flatMap(([, messages]) => messages);
            if (autres.length > 0) {
                setError(autres.join(' '));
            } else if (Object.keys(erreurs).length === 0) {
                setError(t('Enregistrement impossible.'));
            }
        },
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        setErreursChamps({});
        setEnvoiRetenu(false);
        const payload = toPayload(form, isEdit);
        if (form.type === 'kit') {
            payload.composants = composants
                .filter((c) => c.produit_id !== '' && parseFloat(c.quantite) > 0)
                .map((c) => ({ produit_id: parseInt(c.produit_id, 10), quantite: parseFloat(c.quantite) }));
        }
        // Revenu à « service » ou « kit », la quantité saisie reste en mémoire
        // (comme les seuils de réappro) mais ne part pas : le serveur la
        // refuserait, un service n'ayant pas de stock. Idem quand le champ a
        // cédé la place à la consigne « aucun dépôt actif ».
        if (champStockAffiche && stockInitial !== '') {
            // Liste des dépôts pas encore là (lente, ou en échec) : sans
            // entrepot_id, le serveur rangerait la quantité dans le dépôt par
            // défaut, que l'écran n'a jamais montré. On retient l'envoi.
            // Le message en découle au rendu, et s'efface seul dès que la
            // liste arrive.
            if (entrepots === undefined) {
                setEnvoiRetenu(true);
                return;
            }
            payload.stock_initial = parseFloat(stockInitial);
            // Aucun dépôt du tout : rien d'envoyé, le serveur crée le dépôt principal.
            if (depotRetenu !== '') {
                payload.entrepot_id = parseInt(depotRetenu, 10);
            }
        }
        mutation.mutate(payload);
    };

    /**
     * Sous un champ du stock initial : son refus, lié au champ pour les
     * lecteurs d'écran, et annoncé dès qu'il paraît — le bandeau du haut ne
     * le reprend pas.
     */
    const erreurSous = (champ: string, idErreur: string) =>
        erreursChamps[champ] ? (
            <p id={idErreur} role="alert" className="mt-1 text-xs text-red-600">
                {erreursChamps[champ].join(' ')}
            </p>
        ) : null;

    const decritPar = (champ: string, idErreur: string, ...autres: (string | null)[]) =>
        [...autres, idAideStock, erreursChamps[champ] ? idErreur : null].filter(Boolean).join(' ');

    const unite = form.unit.trim();

    const text =
        (key: keyof ProduitFormData) =>
        (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
            setForm((f) => ({ ...f, [key]: e.target.value }));

    const sellPrice = parseFloat(form.sell_price || '0');
    const tva = parseFloat(form.tva_rate);
    const ttc = Math.round(sellPrice * (1 + tva / 100) * 100) / 100;

    const input =
        'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';
    const label = 'mb-1 block text-sm font-medium text-slate-700';

    return (
        <div className="max-w-3xl space-y-4">
            <div>
                <Link to="/catalogue" className="text-sm text-emerald-600 hover:underline">
                    {t('← Retour au catalogue')}
                </Link>
                <h1 className="mt-2 text-xl font-semibold text-slate-900">
                    {isEdit ? `Modifier ${existing?.name ?? ''}` : 'Nouveau produit ou service'}
                </h1>
                {isEdit && existing && (
                    <p className="mt-1 font-mono text-xs text-slate-500">{existing.code}</p>
                )}
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}

            <form onSubmit={handleSubmit} className="space-y-4">
                <fieldset className="min-w-0 rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">{t('Identité')}</legend>
                    <h2 className="mb-4 font-medium text-slate-900">{t('Identité')}</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className={label}>{t('Désignation *')}</label>
                            <input required value={form.name} onChange={text('name')} className={input} />
                        </div>
                        <div>
                            <span className={label}>Type {isEdit && '(immuable)'}</span>
                            <div className="flex gap-4 pt-2">
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="product"
                                        checked={form.type === 'product'}
                                        onChange={text('type')}
                                        disabled={isEdit}
                                    />
                                    {t('Produit')}
                                </label>
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="service"
                                        checked={form.type === 'service'}
                                        onChange={text('type')}
                                        disabled={isEdit}
                                    />
                                    {t('Service')}
                                </label>
                                <label className="flex items-center gap-2 text-sm text-slate-700">
                                    <input
                                        type="radio"
                                        name="type"
                                        value="kit"
                                        checked={form.type === 'kit'}
                                        onChange={text('type')}
                                        disabled={isEdit}
                                    />
                                    {t('Kit')}
                                </label>
                            </div>
                        </div>
                        <div>
                            <label className={label}>
                                Catégorie comptable{' '}
                                <span className="font-normal text-slate-400">{t('(comptes GL)')}</span>
                            </label>
                            <select
                                value={form.categorie_produit_id}
                                onChange={text('categorie_produit_id')}
                                className={input}
                            >
                                <option value="">{t('— Aucune (comptes par défaut) —')}</option>
                                {categories?.map((cat) => (
                                    <option key={cat.id} value={cat.id}>
                                        {cat.name}
                                        {cat.is_immobilisation ? ' (immobilisation)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className={label}>
                                Unité <span className="font-normal text-slate-400">{t('(kg, m³, heure…)')}</span>
                            </label>
                            <input value={form.unit} onChange={text('unit')} className={input} />
                        </div>
                        <div>
                            <label className={label}>{t('Code-barres')}</label>
                            <input value={form.barcode} onChange={text('barcode')} className={input} />
                        </div>
                        <div className="flex items-end pb-2">
                            <label className="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
                                    className="rounded border-slate-300"
                                />
                                {t('Actif')}
                            </label>
                        </div>
                        <div className="sm:col-span-2">
                            <label className={label}>{t('Description')}</label>
                            <textarea
                                value={form.description}
                                onChange={text('description')}
                                rows={3}
                                className={input}
                            />
                        </div>
                    </div>
                </fieldset>

                <fieldset className="min-w-0 rounded-xl bg-white p-5 shadow-sm">
                    <legend className="sr-only">{t('Prix et TVA')}</legend>
                    <h2 className="mb-4 font-medium text-slate-900">{t('Prix & TVA')}</h2>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label className={label}>{t('Prix de vente HT (MAD) *')}</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                required
                                value={form.sell_price}
                                onChange={text('sell_price')}
                                className={input}
                            />
                        </div>
                        <div>
                            <label className={label}>{t("Prix d'achat HT (MAD)")}</label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value={form.buy_price}
                                onChange={text('buy_price')}
                                className={input}
                            />
                        </div>
                        <div>
                            <label className={label}>{t('Taux de TVA')}</label>
                            <select value={form.tva_rate} onChange={text('tva_rate')} className={input}>
                                {TVA_RATES.map((rate) => (
                                    <option key={rate} value={rate}>
                                        {rate === 0 ? 'Exonéré (0 %)' : `${rate} %`}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="mt-4 rounded-md bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                        Prix de vente TTC :{' '}
                        <span className="font-semibold tabular-nums">{formatMAD(ttc)}</span>
                    </div>
                </fieldset>

                {form.type === 'kit' && (
                    <fieldset className="min-w-0 rounded-xl bg-white p-5 shadow-sm">
                        <legend className="sr-only">{t('Composition du kit')}</legend>
                        <h2 className="mb-1 font-medium text-slate-900">{t('Composition du kit')}</h2>
                        <p className="mb-4 text-xs text-slate-500">
                            La vente du kit sort du stock chaque composant physique (quantité vendue ×
                            quantité du composant). Les services inclus ne bougent pas le stock.
                        </p>
                        <div className="space-y-2">
                            {composants.map((composant, index) => (
                                <div key={index} className="flex flex-wrap items-center gap-3">
                                    <SelecteurProduit
                                        required
                                        type={['product', 'service']}
                                        className="w-full flex-1"
                                        value={composant.produit_id === '' ? null : Number(composant.produit_id)}
                                        produitConnu={composant.connu}
                                        onChange={(choisi, produit) =>
                                            setComposants((list) =>
                                                list.map((c, i) =>
                                                    i === index
                                                        ? {
                                                              ...c,
                                                              produit_id: choisi === null ? '' : String(choisi),
                                                              connu: produit
                                                                  ? { id: produit.id, name: produit.name, code: produit.code }
                                                                  : null,
                                                          }
                                                        : c,
                                                ),
                                            )
                                        }
                                        placeholder={t('Rechercher un produit ou un service…')}
                                        aria-label={t('Composant du kit')}
                                    />
                                    <input
                                        type="number"
                                        step="0.001"
                                        min="0.001"
                                        required
                                        value={composant.quantite}
                                        onChange={(e) =>
                                            setComposants((list) =>
                                                list.map((c, i) => (i === index ? { ...c, quantite: e.target.value } : c)),
                                            )
                                        }
                                        className={`${input} w-28`}
                                        placeholder={t('Qté')}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setComposants((list) => list.filter((_, i) => i !== index))}
                                        className="text-red-500 hover:underline"
                                    >
                                        {t('Retirer')}
                                    </button>
                                </div>
                            ))}
                            {composants.length === 0 && (
                                <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-500">
                                    Aucun composant. Un kit sans composant se vend comme un service (aucun
                                    mouvement de stock).
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => setComposants((list) => [...list, { produit_id: '', quantite: '1', connu: null }])}
                            className="mt-3 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            {t('+ Ajouter un composant')}
                        </button>
                    </fieldset>
                )}

                {form.type === 'product' && (
                    <fieldset className="min-w-0 rounded-xl bg-white p-5 shadow-sm">
                        <legend className="sr-only">{t('Réapprovisionnement')}</legend>
                        <h2 className="mb-1 font-medium text-slate-900">{t('Réapprovisionnement')}</h2>
                        <p className="mb-4 text-xs text-slate-500">
                            Le seuil déclenche une alerte quand le stock passe en dessous. La quantité
                            cible sert à suggérer la quantité à commander. Laissez vide pour ne pas suivre.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className={label}>
                                    Seuil d'alerte{' '}
                                    <span className="font-normal text-slate-400">{t('(stock minimum)')}</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.stock_min}
                                    onChange={text('stock_min')}
                                    className={input}
                                />
                            </div>
                            <div>
                                <label className={label}>
                                    Quantité cible{' '}
                                    <span className="font-normal text-slate-400">{t('(à réapprovisionner)')}</span>
                                </label>
                                <input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    value={form.stock_reappro}
                                    onChange={text('stock_reappro')}
                                    className={input}
                                />
                            </div>
                        </div>
                    </fieldset>
                )}

                {form.type === 'product' && peutAlimenter && (
                    <fieldset className="min-w-0 rounded-xl bg-white p-5 shadow-sm">
                        <legend className="sr-only">{t('Stock initial')}</legend>
                        <h2 className="mb-1 font-medium text-slate-900">{t('Stock initial')}</h2>
                        <p id={idAideStock} className="mb-4 text-xs text-slate-500">
                            {t('Crée une entrée de stock. Ensuite, le stock se corrige par un mouvement ou un inventaire.')}
                        </p>
                        {tousDepotsInactifs ? (
                            // Des dépôts, tous désactivés : le serveur refuserait
                            // chacun d'eux. On le dit plutôt que d'offrir un champ
                            // voué au refus — et l'on indique le seul geste que
                            // l'écran Stock permet : aucun n'y réactive un dépôt.
                            <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                {t('Aucun dépôt actif : ajoutez-en un dans Stock, onglet Entrepôts, pour saisir un stock initial.')}{' '}
                                <Link to="/stock" className="font-medium underline">
                                    {t('Ouvrir le stock →')}
                                </Link>
                            </p>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="min-w-0">
                                    <label htmlFor={idQuantite} className={label}>
                                        {t('Quantité')}{' '}
                                        <span className="font-normal text-slate-400">{t('(facultatif)')}</span>
                                    </label>
                                    <div className="flex items-center gap-2">
                                        <input
                                            ref={refQuantite}
                                            id={idQuantite}
                                            type="number"
                                            inputMode="decimal"
                                            step="0.001"
                                            min="0.001"
                                            max="999999999"
                                            value={stockInitial}
                                            onChange={(e) => setStockInitial(e.target.value)}
                                            dir="ltr"
                                            aria-invalid={erreursChamps.stock_initial ? true : undefined}
                                            // L'unité d'abord : c'est elle qui donne son sens au nombre.
                                            aria-describedby={decritPar(
                                                'stock_initial',
                                                idErreurQuantite,
                                                unite !== '' ? idUnite : null,
                                            )}
                                            className={`${input} min-w-0 flex-1 text-end tabular-nums`}
                                        />
                                        {unite !== '' && (
                                            <span id={idUnite} className="max-w-24 shrink-0 truncate text-sm text-slate-500">
                                                <bdi>{unite}</bdi>
                                            </span>
                                        )}
                                    </div>
                                    {erreurSous('stock_initial', idErreurQuantite)}
                                </div>
                                <div className="min-w-0">
                                    {depotsActifs.length > 1 ? (
                                        <>
                                            <label htmlFor={idDepot} className={label}>
                                                {t('Dépôt')}
                                            </label>
                                            <select
                                                ref={refDepot}
                                                id={idDepot}
                                                value={depotRetenu}
                                                onChange={(e) => setDepotChoisi(e.target.value)}
                                                aria-invalid={erreursChamps.entrepot_id ? true : undefined}
                                                aria-describedby={decritPar('entrepot_id', idErreurDepot)}
                                                className={input}
                                            >
                                                {depotsActifs.map((depot) => (
                                                    <option key={depot.id} value={depot.id}>
                                                        {depot.is_default
                                                            ? t('{depot} (par défaut)', { depot: depot.name })
                                                            : depot.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </>
                                    ) : (
                                        // Un seul dépôt (ou aucun encore) : rien à choisir,
                                        // on dit seulement où la quantité entrera.
                                        <>
                                            <span className={label}>{t('Dépôt')}</span>
                                            <p className="break-words py-2 text-sm text-slate-700">
                                                {entrepots === undefined && depotsEnEchec ? (
                                                    // Sans liste, aucun dépôt à montrer : l'envoi
                                                    // d'une quantité est retenu (handleSubmit).
                                                    <span className="text-red-600">
                                                        {t('Liste des dépôts indisponible.')}{' '}
                                                        <button
                                                            type="button"
                                                            onClick={() => relireDepots()}
                                                            disabled={depotsEnLecture}
                                                            className="font-medium text-emerald-600 hover:underline disabled:opacity-60"
                                                        >
                                                            {depotsEnLecture ? t('Chargement…') : t('Réessayer')}
                                                        </button>
                                                    </span>
                                                ) : entrepots === undefined ? (
                                                    t('Chargement…')
                                                ) : depotPropose ? (
                                                    <bdi>{depotPropose.name}</bdi>
                                                ) : (
                                                    t("Entrepôt principal, créé à l'enregistrement")
                                                )}
                                            </p>
                                        </>
                                    )}
                                    {erreurSous('entrepot_id', idErreurDepot)}
                                    {envoiRetenu && entrepots === undefined && (
                                        <p role="alert" className="mt-1 text-xs text-red-600">
                                            {depotsEnEchec
                                                ? t(
                                                      "La liste des dépôts n'a pas pu être chargée : réessayez, ou videz la quantité pour créer l'article sans stock.",
                                                  )
                                                : t('La liste des dépôts se charge encore : enregistrez dans un instant.')}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}
                    </fieldset>
                )}

                {isEdit && form.type === 'product' && stockActuel && (
                    <fieldset className="min-w-0 rounded-xl bg-white p-5 shadow-sm">
                        <legend className="sr-only">{t('Stock')}</legend>
                        <h2 className="mb-1 font-medium text-slate-900">{t('Stock')}</h2>
                        <p className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="text-sm text-slate-500">{t('Stock actuel')}</span>
                            <span className="text-lg font-semibold tabular-nums text-slate-900">
                                {parseFloat(stockActuel.total.quantite)}
                                {stockActuel.produit.unit && (
                                    <span className="ms-1 text-sm font-normal text-slate-400">
                                        <bdi>{stockActuel.produit.unit}</bdi>
                                    </span>
                                )}
                            </span>
                        </p>
                        <p className="mt-2 text-xs text-slate-500">
                            {t("Le stock d'un article existant se corrige par un mouvement ou un inventaire.")}{' '}
                            <Link to="/stock" className="font-medium text-emerald-600 hover:underline">
                                {t('Ouvrir le stock →')}
                            </Link>
                        </p>
                    </fieldset>
                )}

                <div className="flex gap-3">
                    <button
                        type="submit"
                        disabled={mutation.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {mutation.isPending ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                    <Link
                        to="/catalogue"
                        className="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        {t('Annuler')}
                    </Link>
                </div>
            </form>
        </div>
    );
}
