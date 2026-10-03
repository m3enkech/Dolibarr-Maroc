import { useEffect, useRef, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import SelecteurProduit, { type ProduitChoisi } from '@/components/SelecteurProduit';
import SelecteurTiers from '@/components/SelecteurTiers';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { TYPE_LABELS } from '@/pages/ventes/common';
import { libelleTarif, lireTarifs, type TarifLigne } from '@/pages/ventes/tarifs';
import type { DocumentType, DocumentVente, DocumentVenteLigne, Produit } from '@/types';

const TVA_RATES = ['20', '14', '10', '7', '0'];

/** Attente après la dernière frappe dans « Qté » avant de redemander le tarif. */
const DELAI_QUANTITE_MS = 350;

/**
 * D'où vient le prix affiché d'une ligne — et donc s'il suit encore le tarif
 * du client. LOCAL, jamais envoyé : le serveur enregistre le prix tel quel.
 */
type EtatPrix =
    /** Ligne libre : aucun tarif ne s'y applique. */
    | { mode: 'libre' }
    /** Tarif demandé au serveur ; l'enregistrement attend la réponse. */
    | { mode: 'calcul' }
    /** Prix du tarif du client : suit la quantité et le client. */
    | { mode: 'tarif'; tarif: TarifLigne }
    /** Le serveur n'a pas répondu : le prix affiché n'est pas garanti. */
    | { mode: 'indisponible' }
    /** Tapé par le vendeur : plus aucun recalcul automatique sur cette ligne. */
    | { mode: 'saisi' }
    /**
     * Chargé d'une pièce existante : intouché.
     * - `verifie` : la comparaison avec le tarif actuel est faite, ou sans
     *   objet. Tant qu'elle court, la mention reste neutre et sans bouton :
     *   « Appliquer le tarif » sur chaque ligne, puis replié sur la plupart
     *   une fois la réponse arrivée, faisait sauter la page sous le doigt.
     * - `origine` : pièce issue d'une autre (avoir, bon de livraison,
     *   facture…), dont le prix est celui de la pièce d'origine.
     */
    | { mode: 'document'; verifie: boolean; origine: boolean };

/** Un prix figé ne bouge plus tout seul : ni la quantité ni le client n'y touchent. */
const prixFige = (prix: EtatPrix) => prix.mode === 'saisi' || prix.mode === 'document';

/** Demande de tarif pour une ligne ; le jeton dit si sa réponse vaut encore. */
interface DemandeTarif {
    uid: string;
    produit_id: number;
    quantite: number;
    jeton: number;
}

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
    /**
     * Libellé de l'article (référence, nom), LOCAL lui aussi : le sélecteur
     * l'affiche sans relire l'article. Vient du document chargé en
     * modification, du choix fait dans la liste sinon.
     */
    produit: ProduitChoisi | null;
    designation: string;
    quantite: string;
    prix_unitaire: string;
    remise_percent: string;
    tva_rate: string;
    /*
     * Ce que le serveur a confié à la ligne et que cet écran ne SAISIT pas.
     * Tout repart à l'enregistrement : le serveur supprime puis recrée les
     * lignes, si bien qu'un champ omis est un champ perdu. C'est ainsi qu'un
     * bon de livraison retouché ne soldait plus sa commande (reliquat réclamé
     * une seconde fois) et qu'une vente au carton s'imprimait en pièces.
     */
    /** Ligne existante : permet au serveur de reprendre ce qu'un envoi aurait oublié. */
    id: number | null;
    /**
     * Ligne de commande que cette ligne de bon de livraison solde, avec
     * l'article commandé : le lien ne part que si la ligne vend TOUJOURS cet
     * article (voir soldeLaCommande). Gardé tel quel quand on change d'article,
     * pour que revenir à l'article commandé rétablisse le lien.
     */
    source_ligne: { id: number; produit_id: string } | null;
    /** Vente au colis, telle que chargée ; `null` pour une ligne à l'unité. */
    colis: ColisLigne | null;
    etatPrix: EtatPrix;
}

interface ColisLigne {
    conditionnement_id: number;
    nom: string;
    /** Unités de stock par colis. */
    base: number;
    /** Nombre de colis chargé, et la quantité (en unités) qu'il donnait. */
    nombre: number;
    quantite: number;
}

// Une fabrique, pas un objet partagé : un uid figé dans une constante recopiée
// donnerait le même identifiant à toutes les lignes.
let compteurLigne = 0;
const nouvelleLigne = (): LigneForm => ({
    uid: `v${++compteurLigne}`,
    produit_id: '',
    produit: null,
    designation: '',
    quantite: '1',
    prix_unitaire: '0',
    remise_percent: '0',
    tva_rate: '20',
    id: null,
    source_ligne: null,
    colis: null,
    etatPrix: { mode: 'libre' },
});

/**
 * Vrai si la ligne solde encore sa ligne de commande. La quantité livrée est
 * imputée telle quelle sur la commande : un article de remplacement, ou une
 * ligne libre, la dirait livrée d'une marchandise qui n'est jamais partie. Le
 * serveur refuse ce lien ; l'écran ne l'envoie donc pas, et la ligne part
 * comme une livraison à part — le reliquat reste ouvert sur la commande.
 */
const soldeLaCommande = (ligne: LigneForm) =>
    ligne.source_ligne !== null && ligne.source_ligne.produit_id === ligne.produit_id;

/**
 * Colis d'une ligne chargée, ou `null` si elle repart à l'unité.
 *
 * Le serveur exige un nombre de colis avec le colis. Un colis SANS nombre (bon
 * de livraison partiel créé avant que le serveur ne le compte) est recompté
 * quand la quantité tombe juste sur des colis entiers ; sinon la ligne reste ce
 * qu'elle est en quantité, à l'unité.
 */
function colisCharge(l: DocumentVenteLigne): ColisLigne | null {
    const base = parseFloat(l.conditionnement_quantite_base ?? '');
    const quantite = parseFloat(l.quantite);
    if (!l.conditionnement_id || !(base > 0) || !(quantite > 0)) return null;

    let nombre = parseFloat(l.quantite_colis ?? '');
    if (!(nombre > 0)) {
        nombre = Math.round(quantite / base);
        if (!(nombre >= 1) || Math.abs(nombre * base - quantite) >= 0.0005) return null;
    }

    return { conditionnement_id: l.conditionnement_id, nom: l.conditionnement ?? 'colis', base, nombre, quantite };
}

/**
 * Colis sous lequel la ligne repart, ou `null` si elle part à l'unité.
 *
 * Le serveur RECALCULE la quantité à partir du colis : renvoyer le colis
 * d'origine sous une quantité retouchée effacerait en silence ce que le
 * vendeur vient de taper. D'où trois cas :
 * - quantité inchangée : le colis repart tel quel (même 2,5 cartons) ;
 * - retouchée sur un nombre ENTIER de colis : 120 → 144 = 12 cartons de 12 ;
 * - sinon : la ligne passe à l'unité, avec exactement la quantité tapée.
 */
function colisDeLigne(ligne: LigneForm): { conditionnement_id: number; quantite_colis: number } | null {
    const colis = ligne.colis;
    const quantite = parseFloat(ligne.quantite || '0');
    if (!colis || !(quantite > 0)) return null;

    if (Math.abs(quantite - colis.quantite) < 0.0005) {
        return { conditionnement_id: colis.conditionnement_id, quantite_colis: colis.nombre };
    }

    const nombre = Math.round(quantite / colis.base);
    return nombre >= 1 && Math.abs(nombre * colis.base - quantite) < 0.0005
        ? { conditionnement_id: colis.conditionnement_id, quantite_colis: nombre }
        : null;
}

/** Ce que la quantité représente sur une ligne vendue au colis : « 5 × Carton de 12 ». */
function libelleColis(ligne: LigneForm, origine: ColisLigne): string {
    const colis = colisDeLigne(ligne);
    return colis
        ? `${colis.quantite_colis.toLocaleString('fr-FR', { maximumFractionDigits: 3 })} × ${origine.nom}`
        : 'À l’unité';
}

function ligneHt(ligne: LigneForm): number {
    const qty = parseFloat(ligne.quantite || '0');
    const pu = parseFloat(ligne.prix_unitaire || '0');
    const remise = parseFloat(ligne.remise_percent || '0');
    return Math.round(qty * pu * (1 - remise / 100) * 100) / 100;
}

const quantiteValable = (ligne: LigneForm) => parseFloat(ligne.quantite) > 0;

/**
 * Mention discrète sous le prix : d'où il vient, et ce qui le fera bouger. Sans
 * elle, un vendeur qui voit 72,00 au lieu des 100,00 du catalogue « corrige »
 * un prix négocié qu'il croit faux — ou ne comprend pas pourquoi un prix tapé
 * ne suit plus la quantité.
 */
function MentionPrix({
    ligne,
    id,
    prixActif,
    onTarif,
}: {
    ligne: LigneForm;
    id: string;
    /** Le champ prix de CETTE ligne a le focus. */
    prixActif: boolean;
    onTarif: () => void;
}) {
    const classes = 'mt-1 text-xs xl:text-end';
    // Revenir au tarif : seulement si l'article existe encore (un article
    // supprimé depuis n'a plus de tarif à demander).
    //
    // Hors du trajet clavier P.U. → Remise tant que le prix a le focus : le
    // bouton suit le champ dans le DOM, si bien que « Tab puis Entrée » pour
    // passer à la remise remplaçait en silence le prix qu'on venait de taper
    // (même piège que le ✕ de ComboboxRecherche). L'ordre de tabulation se
    // décide à la frappe de Tab, avant le blur : le bouton est sauté. Il reste
    // cliquable, et Maj+Tab depuis la remise l'atteint, geste délibéré.
    const retour = (texte: string) =>
        ligne.produit !== null && (
            <>
                {' · '}
                <button
                    type="button"
                    onClick={onTarif}
                    tabIndex={prixActif ? -1 : undefined}
                    className="text-emerald-700 underline hover:text-emerald-900"
                >
                    {texte}
                </button>
            </>
        );

    switch (ligne.etatPrix.mode) {
        case 'libre':
            return null;
        case 'calcul':
            return (
                <p id={id} className={`${classes} text-slate-500`}>
                    {quantiteValable(ligne) ? 'Calcul du tarif…' : 'Tarif à la saisie de la quantité'}
                </p>
            );
        case 'tarif': {
            const { texte, detail } = libelleTarif(ligne.etatPrix.tarif);
            return (
                <p id={id} className={`${classes} text-slate-500`} title={`${texte} : ${detail}`}>
                    {texte}
                    <span className="sr-only"> : {detail}</span>
                </p>
            );
        }
        case 'indisponible':
            return (
                <p id={id} className={`${classes} text-amber-700`}>
                    Tarif indisponible, prix à vérifier{retour('Réessayer')}
                </p>
            );
        case 'saisi':
            return (
                <p id={id} className={`${classes} text-slate-500`}>
                    Prix saisi{retour('Appliquer le tarif')}
                </p>
            );
        case 'document':
            // Le bouton n'arrive qu'une fois la comparaison faite, et seulement
            // sur les lignes restées différentes du tarif : voir EtatPrix.
            return ligne.etatPrix.origine ? (
                <p
                    id={id}
                    className={`${classes} text-slate-500`}
                    title="Prix repris de la pièce d'origine : ni la quantité ni le client ne le changent."
                >
                    Prix d’origine{ligne.etatPrix.verifie && retour('Appliquer le tarif')}
                </p>
            ) : (
                <p id={id} className={`${classes} text-slate-500`}>
                    Prix du document{ligne.etatPrix.verifie && retour('Appliquer le tarif')}
                </p>
            );
    }
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
    // Client présélectionné par ?tiers_id= (boutons « + Devis / + Facture » de
    // la fiche tiers) — pour un NOUVEAU document seulement : en modification,
    // c'est le document chargé qui fait foi. Un paramètre qui n'est pas un
    // entier est ignoré plutôt qu'envoyé tel quel au serveur.
    const [tiersId, setTiersId] = useState(() => {
        const demande = searchParams.get('tiers_id') ?? '';
        return !isEdit && /^[1-9]\d*$/.test(demande) ? demande : '';
    });
    const [dateDocument, setDateDocument] = useState(() => new Date().toISOString().slice(0, 10));
    const [dateEcheance, setDateEcheance] = useState('');
    const [notes, setNotes] = useState('');
    const [lignes, setLignes] = useState<LigneForm[]>(() => [nouvelleLigne()]);
    const [error, setError] = useState<string | null>(null);
    /** Ce que le changement de client a fait aux prix — dit, pas deviné. */
    const [avisClient, setAvisClient] = useState<{ texte: string; alerte: boolean } | null>(null);
    /** Ligne (uid) dont le champ prix a le focus : voir MentionPrix. */
    const [prixActif, setPrixActif] = useState<string | null>(null);

    /*
     * Réponses de tarif qui arrivent dans le désordre. Chaque demande prend un
     * jeton pour sa ligne ; toute action qui la rend caduque (prix tapé, autre
     * article, autre quantité, autre client, ligne supprimée) retire ou
     * remplace ce jeton. Une réponse dont le jeton n'est plus celui de la ligne
     * n'écrit rien : la frappe « 1 → 10 → 1 » ne laisse pas le prix de 10.
     */
    const jetons = useRef(new Map<string, number>());
    const compteurJetons = useRef(0);
    const minuteurs = useRef(new Map<string, ReturnType<typeof setTimeout>>());
    const compteurAvis = useRef(0);
    // Le client au moment où une demande différée part — pas celui de la frappe.
    const tiersCourant = useRef(tiersId);

    useEffect(() => {
        const enAttente = minuteurs.current;
        return () => {
            enAttente.forEach((m) => clearTimeout(m));
            enAttente.clear();
        };
    }, []);

    /** Rend caduque toute demande en vol ou programmée pour cette ligne. */
    const oublierDemande = (uid: string) => {
        jetons.current.delete(uid);
        const minuteur = minuteurs.current.get(uid);
        if (minuteur !== undefined) {
            clearTimeout(minuteur);
            minuteurs.current.delete(uid);
        }
    };

    const prendreJeton = (uid: string) => {
        oublierDemande(uid);
        const jeton = ++compteurJetons.current;
        jetons.current.set(uid, jeton);
        return jeton;
    };

    /** Vrai si la réponse à cette demande peut encore s'appliquer ; la consomme. */
    const consommer = (d: DemandeTarif) => {
        if (jetons.current.get(d.uid) !== d.jeton) return false;
        jetons.current.delete(d.uid);
        return true;
    };

    /**
     * Demande le tarif de ces lignes et le pose sur celles que rien n'a rendues
     * caduques. Renvoie le nombre de prix posés, `null` si le serveur n'a pas
     * répondu (les lignes concernées passent alors « tarif indisponible »).
     */
    const appliquerTarifs = async (demandes: DemandeTarif[], tiers: string): Promise<number | null> => {
        if (demandes.length === 0) return 0;
        try {
            const tarifs = await lireTarifs(tiers, demandes);
            const retenus = new Map<string, TarifLigne>();
            demandes.forEach((d, i) => {
                if (consommer(d)) retenus.set(d.uid, tarifs[i]);
            });
            setLignes((prev) =>
                prev.map((l) => {
                    const tarif = retenus.get(l.uid);
                    return tarif ? { ...l, prix_unitaire: tarif.prix, etatPrix: { mode: 'tarif', tarif } } : l;
                }),
            );
            return retenus.size;
        } catch {
            const echecs = new Set(demandes.filter(consommer).map((d) => d.uid));
            setLignes((prev) => prev.map((l) => (echecs.has(l.uid) ? { ...l, etatPrix: { mode: 'indisponible' } } : l)));
            return null;
        }
    };

    /**
     * Met en route le tarif d'une ligne. L'appelant la passe en « calcul » dans
     * la même mise à jour. Sans quantité valable, rien ne part : la saisie de
     * la quantité relancera la demande.
     */
    const demanderTarif = (uid: string, produitId: string, quantite: number, delai: number) => {
        const jeton = prendreJeton(uid);
        if (!(quantite > 0)) return;
        const demande: DemandeTarif = { uid, produit_id: Number(produitId), quantite, jeton };
        if (delai === 0) {
            void appliquerTarifs([demande], tiersCourant.current);
            return;
        }
        minuteurs.current.set(
            uid,
            setTimeout(() => {
                minuteurs.current.delete(uid);
                if (jetons.current.get(uid) === jeton) void appliquerTarifs([demande], tiersCourant.current);
            }, delai),
        );
    };

    /**
     * Pièce chargée : chaque prix reste celui du document. On demande seulement
     * le tarif actuel pour savoir lequel des deux il est — un prix égal au
     * tarif le suivra ensuite (quantité, client), un prix différent a été
     * négocié ou fixé à la main et n'est plus jamais recalculé tout seul.
     */
    const reconnaitreTarifs = async (demandes: (DemandeTarif & { prix: string })[], tiers: string) => {
        if (demandes.length === 0) return;
        const conformes = new Map<string, TarifLigne>();
        try {
            const tarifs = await lireTarifs(tiers, demandes);
            demandes.forEach((d, i) => {
                if (consommer(d) && Math.abs(parseFloat(tarifs[i].prix) - parseFloat(d.prix)) < 0.005) {
                    conformes.set(d.uid, tarifs[i]);
                }
            });
        } catch {
            // Sans réponse, tout reste « prix du document » : figé, donc sûr.
            demandes.forEach(consommer);
        }
        // Succès comme échec, la comparaison est terminée : les lignes restées
        // « prix du document » reçoivent enfin leur « Appliquer le tarif », et
        // le choix du client se déverrouille.
        const demandees = new Set(demandes.map((d) => d.uid));
        setLignes((prev) =>
            prev.map((l) => {
                if (!demandees.has(l.uid) || l.etatPrix.mode !== 'document') return l;
                const tarif = conformes.get(l.uid);
                // Le prix n'est PAS réécrit : seule l'étiquette change.
                return tarif
                    ? { ...l, etatPrix: { mode: 'tarif', tarif } }
                    : { ...l, etatPrix: { ...l.etatPrix, verifie: true } };
            }),
        );
    };

    // Plus de catalogue chargé d'avance : il s'arrêtait aux 200 premiers
    // articles (Media Desk en compte 1 698). Chaque ligne CHERCHE son article
    // (SelecteurProduit), et n'interroge le serveur qu'une fois sa liste
    // ouverte — ajouter une ligne ne coûte aucune requête.

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
            tiersCourant.current = String(existing.tiers_id);
            setDateDocument(existing.date_document);
            setDateEcheance(existing.date_echeance ?? '');
            setNotes(existing.notes ?? '');
            /*
             * Pièce issue d'une autre (bon de livraison, facture, avoir) : ses
             * prix sont ceux de la pièce d'origine, et ils ne sont PAS comparés
             * au tarif. Reconnus comme « tarif », ils suivraient la quantité :
             * un avoir de 50 × 80 (palier « dès 50 ») ramené à un retour de 10
             * remonterait à 90, et rembourserait plus que ce qui a été facturé ;
             * une commande livrée à 30 par « Transformer en BL » sortirait à
             * 90 quand « Livrer » la sort à 80. Ils restent figés : seul un clic
             * sur « Appliquer le tarif » les recalcule.
             */
            const derivee = existing.source != null || existing.type === 'avoir';
            const chargees: LigneForm[] = (existing.lignes ?? []).map((l) => {
                const quantite = String(parseFloat(l.quantite));
                // Comparé au tarif seulement si l'article existe encore (un
                // article supprimé n'a plus de tarif) et la quantité est valable.
                const aComparer = !derivee && l.produit != null && parseFloat(quantite) > 0;
                return {
                    uid: nouvelleLigne().uid,
                    produit_id: l.produit_id ? String(l.produit_id) : '',
                    // Livré par show() avec le document : aucune requête par
                    // ligne pour afficher les articles. `null` (article
                    // supprimé depuis) : le sélecteur le relit et le signale.
                    produit: l.produit ?? null,
                    designation: l.designation,
                    quantite,
                    prix_unitaire: l.prix_unitaire,
                    remise_percent: String(parseFloat(l.remise_percent)),
                    tva_rate: String(parseFloat(l.tva_rate)),
                    id: l.id,
                    source_ligne: l.source_ligne_id
                        ? { id: l.source_ligne_id, produit_id: l.produit_id ? String(l.produit_id) : '' }
                        : null,
                    colis: colisCharge(l),
                    // Ouvrir une pièce ne change AUCUN prix : chacun part figé,
                    // reconnaitreTarifs() dira ensuite lesquels sont le tarif.
                    etatPrix: l.produit_id
                        ? { mode: 'document', verifie: !aComparer, origine: derivee }
                        : { mode: 'libre' },
                };
            });
            setLignes(chargees);
            void reconnaitreTarifs(
                chargees
                    .filter((l) => l.etatPrix.mode === 'document' && !l.etatPrix.verifie)
                    .map((l) => ({
                        uid: l.uid,
                        produit_id: Number(l.produit_id),
                        quantite: parseFloat(l.quantite),
                        prix: l.prix_unitaire,
                        jeton: prendreJeton(l.uid),
                    })),
                String(existing.tiers_id),
            );
        }
        // Ne relire la pièce que quand elle change : les fonctions de tarif
        // sont recréées à chaque rendu mais ne lisent que des refs.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [existing]);

    const setLigne = (index: number, patch: Partial<LigneForm>) =>
        setLignes((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    const onProduitChange = (index: number, produit: Produit | null) => {
        const ligne = lignes[index];
        if (!ligne) return;
        if (produit) {
            // Reprendre l'article déjà choisi ne réécrit rien : le <select>
            // d'avant ne signalait pas un choix inchangé, et un prix négocié
            // ou une désignation retouchée ne doivent pas repartir au catalogue.
            if (ligne.produit_id === String(produit.id)) return;
            setLigne(index, {
                produit_id: String(produit.id),
                produit: { id: produit.id, name: produit.name, code: produit.code },
                designation: produit.name,
                // Prix catalogue le temps que le tarif du client arrive — il
                // ne peut pas partir tel quel : l'enregistrement attend la fin
                // du calcul. Un autre article remet le prix au tarif, même
                // après un prix tapé : celui-ci valait pour l'article d'avant.
                prix_unitaire: produit.sell_price,
                etatPrix: { mode: 'calcul' },
                tva_rate: String(parseFloat(produit.tva_rate)),
                // Le colis appartient à l'article qu'on quitte : un « carton de
                // 12 » d'eau n'a pas de sens sous un autre article. Le lien vers
                // la ligne de commande ne part plus non plus (soldeLaCommande) :
                // un article de remplacement ne solde pas ce qui était commandé.
                colis: null,
            });
            demanderTarif(ligne.uid, String(produit.id), parseFloat(ligne.quantite), 0);
        } else {
            // « Ligne libre » : l'article s'en va — et son colis avec lui —, la
            // saisie de la ligne reste, prix compris, désormais libre.
            oublierDemande(ligne.uid);
            setLigne(index, { produit_id: '', produit: null, colis: null, etatPrix: { mode: 'libre' } });
        }
    };

    /** La quantité ouvre ou ferme des paliers : un prix qui suit le tarif suit aussi la quantité. */
    const onQuantiteChange = (index: number, valeur: string) => {
        const ligne = lignes[index];
        if (!ligne) return;
        // Même figée, une ligne dont la quantité change rend caduque toute
        // réponse attendue pour l'ancienne quantité — y compris la comparaison
        // au tarif d'une pièce qu'on vient d'ouvrir, qui ne viendra donc plus.
        oublierDemande(ligne.uid);
        const suit = ligne.produit_id !== '' && !prixFige(ligne.etatPrix);
        setLignes((prev) =>
            prev.map((l, i) => {
                if (i !== index) return l;
                if (suit) return { ...l, quantite: valeur, etatPrix: { mode: 'calcul' } };
                return l.etatPrix.mode === 'document'
                    ? { ...l, quantite: valeur, etatPrix: { ...l.etatPrix, verifie: true } }
                    : { ...l, quantite: valeur };
            }),
        );
        if (suit) demanderTarif(ligne.uid, ligne.produit_id, parseFloat(valeur), DELAI_QUANTITE_MS);
    };

    /** Prix tapé : c'est une décision du vendeur, plus rien ne le recalcule. */
    const onPrixChange = (index: number, valeur: string) => {
        const ligne = lignes[index];
        if (!ligne) return;
        oublierDemande(ligne.uid);
        setLigne(index, { prix_unitaire: valeur, etatPrix: ligne.produit_id ? { mode: 'saisi' } : { mode: 'libre' } });
    };

    /** « Appliquer le tarif » / « Réessayer » : la ligne repart sur le tarif du client. */
    const reprendreTarif = (index: number) => {
        const ligne = lignes[index];
        if (!ligne || !ligne.produit_id) return;
        setLigne(index, { etatPrix: { mode: 'calcul' } });
        demanderTarif(ligne.uid, ligne.produit_id, parseFloat(ligne.quantite), 0);
    };

    const supprimerLigne = (index: number) => {
        const ligne = lignes[index];
        if (ligne) oublierDemande(ligne.uid);
        setLignes((prev) => prev.filter((_, i) => i !== index));
    };

    /**
     * Autre client : les prix qui suivent le tarif passent à celui du nouveau
     * client, les prix figés restent — et un message dit ce qui a bougé, pour
     * qu'aucun prix ne change sans que le vendeur le sache.
     */
    const onTiersChange = (choisi: number | null) => {
        const nouveau = choisi === null ? '' : String(choisi);
        if (nouveau === tiersId) return;
        const premier = tiersId === '';
        setTiersId(nouveau);
        tiersCourant.current = nouveau;

        // Tout ce qui était demandé pour l'ancien client est périmé, y compris
        // la reconnaissance des prix d'une pièce qu'on vient d'ouvrir.
        lignes.forEach((l) => oublierDemande(l.uid));
        const avis = ++compteurAvis.current;

        // Client effacé : on ne touche à rien, l'enregistrement exige un client.
        if (nouveau === '') {
            setAvisClient(null);
            return;
        }

        const articles = lignes.filter((l) => l.produit_id !== '');
        const aRecalculer = articles.filter((l) => !prixFige(l.etatPrix));
        const figes = articles.length - aRecalculer.length;
        if (articles.length === 0) {
            setAvisClient(null);
            return;
        }

        const quoi = premier ? 'Client choisi' : 'Client modifié';
        const conserves =
            figes === 0
                ? ''
                : figes === 1
                  ? ' 1 prix fixé à la main ou repris du document reste inchangé.'
                  : ` ${figes} prix fixés à la main ou repris du document restent inchangés.`;

        if (aRecalculer.length === 0) {
            setAvisClient({ texte: `${quoi} : aucun prix recalculé.${conserves}`, alerte: false });
            return;
        }

        const uids = new Set(aRecalculer.map((l) => l.uid));
        setLignes((prev) => prev.map((l) => (uids.has(l.uid) ? { ...l, etatPrix: { mode: 'calcul' } } : l)));
        const demandes: DemandeTarif[] = aRecalculer
            .map((l) => ({ uid: l.uid, produit_id: Number(l.produit_id), quantite: parseFloat(l.quantite), jeton: prendreJeton(l.uid) }))
            // Une ligne sans quantité valable attend la sienne (onQuantiteChange).
            .filter((d) => d.quantite > 0);

        setAvisClient({ texte: `${quoi} : recalcul des prix selon son tarif…`, alerte: false });
        void appliquerTarifs(demandes, nouveau).then((poses) => {
            if (compteurAvis.current !== avis) return; // un autre changement de client a pris la main
            setAvisClient(
                poses === null
                    ? { texte: `${quoi} : son tarif n'a pas pu être lu. Vérifiez les prix des lignes.`, alerte: true }
                    : { texte: `${quoi} : ${poses} prix ${poses > 1 ? 'recalculés' : 'recalculé'} selon son tarif.${conserves}`, alerte: false },
            );
        });
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

    /*
     * Le prix enregistré est le prix AFFICHÉ. Tant qu'un tarif est en route, le
     * prix affiché est provisoire (le catalogue, ou celui de l'ancienne
     * quantité) : on attend la réponse plutôt que de l'enregistrer. Une ligne
     * sans quantité valable ne compte pas — le navigateur refuse déjà l'envoi
     * et montre le champ fautif, ce que ne ferait pas un bouton grisé.
     */
    const tarifsEnCours = lignes.some((l) => l.etatPrix.mode === 'calcul' && quantiteValable(l));

    /*
     * Le client ne se change pas pendant que la pièce ouverte compare ses prix
     * au tarif : changer de client écarte cette comparaison, et ses lignes,
     * restées « prix du document », n'étaient alors pas recalculées — deux
     * secondes plus tard, elles l'auraient été. Le résultat d'un même geste
     * dépendait du temps de réponse du serveur.
     */
    const reconnaissanceEnCours = lignes.some((l) => l.etatPrix.mode === 'document' && !l.etatPrix.verifie);

    // Pièce issue d'une autre : le serveur refuse un autre client (une
    // livraison au client B solderait la commande du client A).
    const pieceOrigine = isEdit ? (existing?.source ?? null) : null;

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (tarifsEnCours) return;
        setError(null);
        const payload: Record<string, unknown> = {
            tiers_id: parseInt(tiersId, 10),
            date_document: dateDocument,
            date_echeance: dateEcheance || null,
            notes: notes || null,
            lignes: lignes.map((l) => {
                const colis = colisDeLigne(l);
                return {
                    // Envoyés même à null : un champ PRÉSENT est une décision,
                    // que le serveur ne complète pas à partir de l'ancienne ligne.
                    id: l.id,
                    source_ligne_id: soldeLaCommande(l) ? l.source_ligne!.id : null,
                    produit_id: l.produit_id ? parseInt(l.produit_id, 10) : null,
                    designation: l.designation || null,
                    quantite: parseFloat(l.quantite || '0'),
                    conditionnement_id: colis?.conditionnement_id ?? null,
                    quantite_colis: colis?.quantite_colis ?? null,
                    prix_unitaire: parseFloat(l.prix_unitaire || '0'),
                    remise_percent: parseFloat(l.remise_percent || '0'),
                    tva_rate: parseFloat(l.tva_rate),
                };
            }),
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
    //
    // Cellules calées en HAUT, pas centrées : les mentions sous la quantité et
    // sous le prix (colis, origine du prix) allongent leur cellule, et un
    // centrage remontait ces champs-là au-dessus des autres — un prix décalé
    // sur chaque ligne, qui bougeait pendant la frappe.
    const gabaritLigne =
        'xl:grid xl:grid-cols-[minmax(0,1.6fr)_minmax(0,2.2fr)_5.5rem_6.5rem_5rem_5rem_6.5rem_2.5rem] xl:items-start xl:gap-2';

    // Hauteur d'un champ en rangée (texte 20 px + 2 × 6 px + bordures) : le
    // total et le ✕, calés en haut eux aussi, se centrent sur elle pour rester
    // alignés sur le texte des champs.
    const celluleChamp = 'xl:flex xl:h-[34px] xl:items-center xl:justify-end';

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
                        {/* Recherche côté serveur : la liste chargée d'avance
                            s'arrêtait aux 200 premiers tiers. Pas de filtre de
                            rôle, comme avant — un devis part aussi vers un
                            prospect. Écran resté en français : traduire={false}. */}
                        <div className="min-w-0">
                            <label htmlFor="vente-tiers" className={label}>
                                Client *
                            </label>
                            <SelecteurTiers
                                id="vente-tiers"
                                required
                                compact
                                traduire={false}
                                disabled={pieceOrigine !== null || reconnaissanceEnCours}
                                value={tiersId ? Number(tiersId) : null}
                                onChange={(choisi) => onTiersChange(choisi)}
                                tiersConnu={existing?.tiers ?? null}
                                placeholder="Rechercher un client (nom, code, ICE)…"
                            />
                            {pieceOrigine && (
                                <p className="mt-1 text-xs text-slate-500">
                                    Client de {pieceOrigine.code} : une pièce issue d’une autre garde son client.
                                </p>
                            )}
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

                    {/* Région toujours présente : un lecteur d'écran n'annonce
                        que ce qui change dans une région qu'il connaît déjà. */}
                    <div role="status" aria-live="polite">
                        {avisClient && (
                            <div
                                className={`mb-4 flex items-start justify-between gap-3 rounded-md px-3 py-2 text-sm ${
                                    avisClient.alerte ? 'bg-amber-50 text-amber-800' : 'bg-sky-50 text-sky-800'
                                }`}
                            >
                                <span>{avisClient.texte}</span>
                                {/* Cible carrée : réduit au glyphe, le ✕ faisait 19 px de
                                    large au doigt. `-me-2` le garde visuellement calé
                                    contre le bord du bandeau. */}
                                <button
                                    type="button"
                                    onClick={() => setAvisClient(null)}
                                    aria-label="Fermer le message"
                                    className="-my-1 -me-2 inline-flex h-8 min-w-10 shrink-0 items-center justify-center rounded opacity-70 hover:opacity-100"
                                >
                                    <span aria-hidden="true">✕</span>
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="space-y-3 xl:space-y-0">
                        {/* En-tête de colonnes : n'existe qu'en mode rangée. */}
                        <div className={`hidden pb-2 text-xs uppercase tracking-wide text-slate-500 ${gabaritLigne}`}>
                            {/* `text-end` et non `text-right`, ici comme dans les
                                champs : en arabe (dir=rtl), valeur, en-tête et
                                mention restent du même côté de la colonne. */}
                            <span>Produit</span>
                            <span>Désignation *</span>
                            <span className="text-end">Qté</span>
                            <span className="text-end">P.U. HT</span>
                            <span className="text-end">Remise %</span>
                            <span className="text-end">TVA</span>
                            <span className="text-end">Total HT</span>
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
                                    {/* Recherche côté serveur (nom, référence, code-barres) :
                                        la liste chargée d'avance s'arrêtait aux 200 premiers
                                        articles. « Ligne libre » reste l'option de tête.
                                        Écran resté en français : traduire={false}.
                                        Sans prix dans la liste (`prix={false}`) : elle
                                        montrait le catalogue, alors que le choix pose le
                                        tarif du client — deux prix pour un même geste. */}
                                    <SelecteurProduit
                                        id={`ligne-produit-${ligne.uid}`}
                                        traduire={false}
                                        prix={false}
                                        aucun="Ligne libre"
                                        placeholder="Ligne libre — rechercher un article…"
                                        classesHauteur="py-2.5 xl:py-1.5"
                                        value={ligne.produit_id ? Number(ligne.produit_id) : null}
                                        produitConnu={ligne.produit}
                                        onChange={(_, produit) => onProduitChange(index, produit)}
                                    />
                                    {ligne.source_ligne && !soldeLaCommande(ligne) && (
                                        <p className="mt-1 text-xs text-amber-700">
                                            Autre article que celui commandé : cette ligne ne solde pas la commande.
                                        </p>
                                    )}
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
                                        onChange={(e) => onQuantiteChange(index, e.target.value)}
                                        aria-describedby={ligne.colis ? `ligne-colis-${ligne.uid}` : undefined}
                                        className={`${champLigne} text-end`}
                                    />
                                    {/* Lecture seule : le colis ne se saisit pas ici, mais le
                                        vendeur doit voir qu'une ligne part au carton — et
                                        qu'une quantité retouchée hors multiple la remet à
                                        l'unité, puisque c'est ce qui sera facturé. */}
                                    {ligne.colis && (
                                        <p id={`ligne-colis-${ligne.uid}`} className="mt-1 text-xs text-slate-500 xl:text-end">
                                            {libelleColis(ligne, ligne.colis)}
                                        </p>
                                    )}
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
                                        onChange={(e) => onPrixChange(index, e.target.value)}
                                        onFocus={() => setPrixActif(ligne.uid)}
                                        onBlur={() => setPrixActif((actif) => (actif === ligne.uid ? null : actif))}
                                        aria-describedby={ligne.etatPrix.mode !== 'libre' ? `ligne-prix-info-${ligne.uid}` : undefined}
                                        className={`${champLigne} text-end`}
                                    />
                                    <MentionPrix
                                        ligne={ligne}
                                        id={`ligne-prix-info-${ligne.uid}`}
                                        prixActif={prixActif === ligne.uid}
                                        onTarif={() => reprendreTarif(index)}
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
                                        className={`${champLigne} text-end`}
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
                                    <div className={`min-w-0 ${celluleChamp}`}>
                                        <span className="text-xs text-slate-500 xl:sr-only">Total HT </span>
                                        <span className="font-medium tabular-nums text-slate-700">
                                            {formatMAD(ligneHt(ligne))}
                                        </span>
                                    </div>
                                    <div className={celluleChamp}>
                                        <button
                                            type="button"
                                            onClick={() => supprimerLigne(index)}
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
                        disabled={mutation.isPending || !tiersId || tarifsEnCours}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {mutation.isPending
                            ? 'Enregistrement…'
                            : tarifsEnCours
                              ? 'Calcul des tarifs…'
                              : 'Enregistrer le brouillon'}
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
