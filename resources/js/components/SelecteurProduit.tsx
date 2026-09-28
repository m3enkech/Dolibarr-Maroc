import ComboboxRecherche, {
    TAILLE_PAGE,
    useTraduction,
    type ElementConnu,
    type StyleOption,
    type TextesCombobox,
} from '@/components/ComboboxRecherche';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import type { Paginated, Produit } from '@/types';

/** Types d'article compris par GET /produits (`type`) — voir ProduitsController::index. */
export type TypeProduit = Produit['type'];

/** Le strict nécessaire pour AFFICHER un article choisi, quand l'appelant le connaît déjà. */
export type ProduitChoisi = ElementConnu;

/**
 * La recherche d'articles. Même contrat d'adresse que celle des tiers (voir
 * optionsRechercheTiers) : paramètres dans un ordre fixe, `search` vide omis,
 * `networkMode: 'always'` pour que l'échec hors ligne se voie.
 *
 * Plusieurs types passent en une liste (`product,service`) : le serveur filtre
 * lui-même, sans quoi vingt résultats pourraient n'être que des kits écartés
 * ensuite, et la liste se viderait alors que des articles correspondent.
 *
 * AUCUNE durée de fraîcheur, comme pour les tiers : chaque ouverture relit.
 * Les prix affichés sont ceux que le choix REPORTE sur la ligne, et ils
 * changent hors de tout formulaire — la validation d'une facture fournisseur
 * met à jour le prix d'achat. Une réponse gardée trente secondes faisait
 * partir l'ancien prix sur la commande suivante. La réponse en mémoire reste
 * affichée pendant la relecture (pas de clignotement), comme l'ancien <select>
 * relu à chaque ouverture du formulaire.
 */
export function optionsRechercheProduits(types: TypeProduit[] | undefined, terme: string) {
    const filtre = types !== undefined && types.length > 0 ? types.join(',') : undefined;

    return {
        queryKey: ['selecteur-produit', filtre ?? 'tous', terme],
        queryFn: async () =>
            (
                await api.get<Paginated<Produit>>('/produits', {
                    params: { search: terme === '' ? undefined : terme, type: filtre, per_page: TAILLE_PAGE },
                })
            ).data,
        networkMode: 'always' as const,
    };
}

/*
 * Largeur minimale de la liste. Nom, référence et prix tiennent mal dans les
 * 16 rem d'une liste de tiers : celle-ci déborde d'une colonne de document
 * étroite — mais jamais de l'écran. Un champ commence jusqu'à ~50 px du bord
 * (ligne de facture en carte : page, carte, ligne), et 20 rem de plus
 * dépassaient un téléphone de 360 px : la page défilait de côté tant que la
 * liste restait ouverte. `min-width` l'emportant sur `max-width`, c'est la
 * largeur minimale elle-même qu'il faut borner par l'écran (100 px de marge).
 */
const LARGEUR_LISTE = 'min-w-[min(20rem,calc(100vw-6.25rem))]';

/** Relecture d'un article venu d'ailleurs, quand l'appelant n'a pas son libellé. */
function optionsFicheProduit(id: number | null) {
    return {
        queryKey: ['selecteur-produit-fiche', id],
        queryFn: async () => (await api.get<{ data: Produit }>(`/produits/${id}`)).data.data,
    };
}

interface Props {
    /** Identifiant de l'article choisi, `null` s'il n'y en a pas. */
    value: number | null;
    /**
     * L'article complet (prix, TVA, unité…) est fourni quand il vient d'être
     * pris dans la liste : c'est lui qui remplit une ligne de document.
     * `null` quand on efface ou qu'on choisit l'option « aucun ».
     */
    onChange: (id: number | null, produit: Produit | null) => void;
    /** Restreint la recherche à un ou plusieurs types (les stocks ne veulent que des produits). */
    type?: TypeProduit | TypeProduit[];
    /** Libellé déjà connu de l'appelant (ligne de document, composant de kit…) : épargne un aller-retour. */
    produitConnu?: ProduitChoisi | null;
    /**
     * Prix montré à côté de chaque article : celui que le choix va reporter
     * (vente sur un devis, achat sur une commande fournisseur), ou aucun.
     */
    prix?: 'vente' | 'achat' | false;
    /** Précision propre à l'écran, affichée sur l'option (« déjà dans l'inventaire »). */
    mention?: (produit: Produit) => string | null;
    /** Option « pas d'article » (valeur `null`) en tête de liste — « Ligne libre » sur un document. */
    aucun?: string;
    id?: string;
    required?: boolean;
    disabled?: boolean;
    placeholder?: string;
    className?: string;
    compact?: boolean;
    /** Voir ComboboxRecherche : espacement aligné sur des champs voisins particuliers. */
    classesHauteur?: string;
    /** `false` sur les écrans restés en français par décision (facturation). */
    traduire?: boolean;
    title?: string;
    'aria-label'?: string;
}

/**
 * Choix d'un article par recherche CÔTÉ SERVEUR.
 *
 * Les écrans chargeaient les 200 ou 500 premiers articles dans un <select>.
 * Media Desk en compte 1 698 : au-delà du rang 200, un article n'était pas
 * choisissable sur un devis, une facture ou une commande fournisseur — sans
 * rien qui le signale, la liste avait l'air complète.
 *
 * On interroge donc GET /produits à la frappe : nom, référence ou code-barres
 * (le serveur cherche dans les trois, sans égard à la casse). Le comportement
 * du champ vit dans ComboboxRecherche, partagé avec SelecteurTiers.
 */
export default function SelecteurProduit({
    type,
    produitConnu = null,
    prix = 'vente',
    mention,
    aucun,
    traduire = true,
    ...reste
}: Props) {
    const t = useTraduction(traduire);
    const types = type === undefined ? undefined : Array.isArray(type) ? type : [type];

    const textes: TextesCombobox = {
        placeholder: t('Rechercher un article (nom, référence, code-barres)…'),
        requis: t('Choisissez un article dans la liste.'),
        introuvable: (id) => t('Article introuvable (n° {id})', { id }),
        numero: (id) => t('Article n° {id}', { id }),
        aucunResultat: (terme) => t('Aucun article ne correspond à « {terme} ».', { terme }),
        aucunAProposer: t('Aucun article à proposer.'),
        tropDeResultats: (total) =>
            t('{total} articles trouvés : précisez la recherche pour voir les autres.', { total }),
        nomListe: t('Articles proposés'),
        annonce: (n) => t('{n} articles proposés.', { n }),
    };

    const rendreOption = (option: Produit, s: StyleOption) => {
        const montant = prix === 'vente' ? option.sell_price : prix === 'achat' ? option.buy_price : null;
        const precision = mention?.(option) ?? null;

        return (
            <>
                {/* Deux lignes : le nom se lit en entier (ou presque) même dans
                    une colonne de document étroite ; dessous, la référence et
                    les précisions (« déjà dans l'inventaire », « Inactif »).
                    Posées sur la ligne du nom, elles ne lui laissaient que
                    quatre ou cinq lettres sur un téléphone — précisément sur
                    l'article qu'il fallait reconnaître. Elles passent à la
                    ligne plutôt que de rogner la référence. */}
                <span className="min-w-0 flex-1">
                    <span dir="auto" className="block truncate font-medium">
                        {option.name}
                    </span>
                    <span className={`flex min-w-0 flex-wrap gap-x-2 text-xs ${s.secondaire}`}>
                        <span className="max-w-full truncate font-mono">{option.code}</span>
                        {precision !== null && <span>{precision}</span>}
                        {!option.is_active && <span>{t('Inactif')}</span>}
                    </span>
                </span>
                {/* HT : c'est le prix que reçoit la ligne (colonne « P.U. HT »). */}
                {montant != null && (
                    <span className="shrink-0 text-xs tabular-nums">
                        {t('{montant} HT', { montant: formatMAD(montant) })}
                    </span>
                )}
            </>
        );
    };

    return (
        <ComboboxRecherche<Produit>
            {...reste}
            aucun={aucun}
            recherche={(terme) => optionsRechercheProduits(types, terme)}
            fiche={optionsFicheProduit}
            rendreOption={rendreOption}
            textes={textes}
            connu={produitConnu}
            traduire={traduire}
            largeurListe={LARGEUR_LISTE}
            // Ce champ remplace des <select>, dans des formulaires de document
            // où il se répète à chaque ligne : Entrée n'y soumet jamais rien.
            entreeOuvre
            // L'option « aucun » (« Ligne libre ») efface déjà au clavier : le
            // ✕ n'ajoute pas un arrêt de tabulation par ligne entre l'article
            // et la désignation. Sans elle, il reste le seul moyen d'effacer.
            effacerTabulable={aucun === undefined}
        />
    );
}
