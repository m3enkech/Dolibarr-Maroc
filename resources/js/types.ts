export interface Tenant {
    id: number;
    name: string;
    slug: string;
    plan: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    is_superadmin?: boolean;
    tenant_id: number;
}

export interface Tiers {
    id: number;
    code: string;
    name: string;
    is_client: boolean;
    is_supplier: boolean;
    ice: string | null;
    if_number: string | null;
    rc: string | null;
    patente: string | null;
    cnss: string | null;
    address: string | null;
    city: string | null;
    postal_code: string | null;
    country: string;
    phone: string | null;
    email: string | null;
    website: string | null;
    contact_name: string | null;
    notes: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export interface CategorieProduit {
    id: number;
    name: string;
    compte_vente_id: number | null;
    compte_vente: string | null;
    compte_achat_id: number | null;
    compte_achat: string | null;
    is_immobilisation: boolean;
    compte_amortissement_id: number | null;
    compte_amortissement: string | null;
    duree_amortissement: number | null;
    produits_count?: number;
}

export interface Produit {
    id: number;
    code: string;
    name: string;
    description: string | null;
    type: 'product' | 'service';
    categorie_produit_id?: number | null;
    categorie?: string | null;
    sell_price: string;
    sell_price_ttc: string;
    buy_price: string | null;
    tva_rate: string;
    unit: string | null;
    stock_min: string | null;
    stock_reappro: string | null;
    barcode: string | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

export type DocumentType = 'devis' | 'commande' | 'facture';
export type DocumentStatut = 'brouillon' | 'valide' | 'accepte' | 'refuse' | 'paye';

export interface DocumentVenteLigne {
    id: number;
    produit_id: number | null;
    designation: string;
    quantite: string;
    prix_unitaire: string;
    remise_percent: string;
    tva_rate: string;
    montant_ht: string;
    montant_tva: string;
    montant_ttc: string;
    position: number;
}

export interface Paiement {
    id: number;
    date_paiement: string;
    montant: string;
    mode: string;
    reference: string | null;
    note: string | null;
}

export interface DocumentVente {
    id: number;
    type: DocumentType;
    code: string;
    statut: DocumentStatut;
    tiers_id: number;
    tiers?: { id: number; code: string; name: string; ice: string | null; city: string | null };
    date_document: string;
    date_echeance: string | null;
    total_ht: string;
    total_tva: string;
    total_ttc: string;
    notes: string | null;
    validated_at: string | null;
    lignes?: DocumentVenteLigne[];
    paiements?: Paiement[];
    montant_paye?: string;
    reste_a_payer?: string;
    source?: { id: number; type: DocumentType; code: string } | null;
    created_at: string;
    updated_at: string;
}

export interface Entrepot {
    id: number;
    code: string;
    name: string;
    address: string | null;
    is_default: boolean;
    is_active: boolean;
}

export interface MouvementStock {
    id: number;
    type: 'entree' | 'sortie' | 'ajustement' | 'vente' | 'achat' | 'transfert';
    quantite: string;
    quantite_apres: string;
    reference: string | null;
    note: string | null;
    produit: { id: number; code: string; name: string; unit: string | null } | null;
    entrepot: { id: number; name: string } | null;
    created_at: string;
}

export interface StockNiveau {
    produit_id: number;
    code: string;
    name: string;
    unit: string | null;
    quantite: string;
    en_commande: string;
    stock_min: string | null;
    sous_seuil: boolean;
    valeur_achat: string | null;
}

export interface StockAlerte {
    produit_id: number;
    code: string;
    name: string;
    unit: string | null;
    quantite: string;
    stock_min: string;
    stock_reappro: string | null;
    en_commande: string;
    suggestion: string;
}

export type InventaireStatut = 'brouillon' | 'valide';

export interface InventaireLigne {
    id: number;
    produit_id: number;
    quantite_theorique: string;
    quantite_comptee: string | null;
    ecart: string | null;
    produit?: { id: number; code: string; name: string; unit: string | null } | null;
}

export interface Inventaire {
    id: number;
    code: string;
    statut: InventaireStatut;
    note: string | null;
    entrepot?: { id: number; name: string } | null;
    lignes?: InventaireLigne[];
    validated_at: string | null;
    created_at: string;
}

export type AchatType = 'commande' | 'reception' | 'facture';
export type AchatStatut = 'brouillon' | 'valide' | 'recue_partielle' | 'recue' | 'paye';

export interface DocumentAchatLigne {
    id: number;
    produit_id: number | null;
    source_ligne_id: number | null;
    designation: string;
    quantite: string;
    quantite_recue: string;
    reste_a_recevoir: string;
    prix_unitaire: string;
    remise_percent: string;
    tva_rate: string;
    montant_ht: string;
    montant_tva: string;
    montant_ttc: string;
    position: number;
}

export interface DocumentAchat {
    id: number;
    type: AchatType;
    code: string;
    statut: AchatStatut;
    tiers_id: number;
    tiers?: { id: number; code: string; name: string; ice: string | null };
    entrepot_id: number | null;
    entrepot?: { id: number; name: string } | null;
    ref_fournisseur: string | null;
    date_document: string;
    date_echeance: string | null;
    total_ht: string;
    total_tva: string;
    total_ttc: string;
    notes: string | null;
    validated_at: string | null;
    lignes?: DocumentAchatLigne[];
    paiements?: Paiement[];
    montant_paye?: string;
    reste_a_payer?: string;
    source?: { id: number; type: AchatType; code: string } | null;
    created_at: string;
    updated_at: string;
}

export interface Compte {
    id: number;
    code: string;
    label: string;
    classe: number;
    classe_label: string;
    is_system: boolean;
    is_active: boolean;
}

export interface ComptaMappingRow {
    cle: string;
    label: string;
    compte_id: number | null;
    compte_code: string | null;
    compte_label: string | null;
}

export interface EcritureLigne {
    id: number;
    compte_code: string | null;
    compte_label: string | null;
    libelle: string | null;
    debit: string;
    credit: string;
}

export interface Ecriture {
    id: number;
    journal: 'VT' | 'AC' | 'BQ' | 'OD' | 'AN';
    numero: string;
    date_ecriture: string;
    libelle: string;
    reference: string | null;
    is_auto: boolean;
    lignes: EcritureLigne[];
    total_debit: string;
    created_at: string;
}

export interface BalanceRow {
    compte_id: number;
    code: string;
    label: string;
    classe: number;
    total_debit: string;
    total_credit: string;
    solde_debiteur: string;
    solde_crediteur: string;
}

export type PosSessionStatut = 'ouverte' | 'fermee';

export interface PosSession {
    id: number;
    code: string;
    statut: PosSessionStatut;
    fond_caisse: string;
    montant_compte: string | null;
    ecart: string | null;
    note: string | null;
    vendeur?: string | null;
    opened_at: string;
    closed_at: string | null;
}

export interface PosRapport {
    tickets: number;
    total_ht: string;
    total_tva: string;
    total_ttc: string;
    par_mode: Record<string, string>;
    fond_caisse: string;
    especes_theorique: string;
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}
