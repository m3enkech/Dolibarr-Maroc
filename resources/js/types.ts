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

export interface KitComposant {
    produit_id: number;
    quantite: string;
    name: string | null;
    code: string | null;
    type: string | null;
    unit: string | null;
}

export interface Produit {
    id: number;
    code: string;
    name: string;
    description: string | null;
    type: 'product' | 'service' | 'kit';
    categorie_produit_id?: number | null;
    categorie?: string | null;
    composants?: KitComposant[];
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

export type DocumentType = 'devis' | 'commande' | 'bon_livraison' | 'facture' | 'avoir';
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
    total_remises: string;
    fond_caisse: string;
    especes_theorique: string;
}

export interface BalanceAgeeRow {
    tiers_id: number | null;
    code: string | null;
    name: string;
    total: string;
    t0_30: string;
    t31_60: string;
    t61_90: string;
    t90_plus: string;
}

export interface BalanceAgeeResponse {
    type: 'clients' | 'fournisseurs';
    date_reference: string;
    data: BalanceAgeeRow[];
    totaux: Omit<BalanceAgeeRow, 'tiers_id' | 'code' | 'name'>;
}

export interface Features {
    relances: boolean;
    effets: boolean;
    crm: boolean;
}

export type OpportuniteEtape = 'nouveau' | 'qualifie' | 'proposition' | 'negociation';
export type OpportuniteStatut = 'ouverte' | 'gagnee' | 'perdue';

export interface Opportunite {
    id: number;
    code: string;
    titre: string;
    montant_estime: string;
    probabilite: number;
    etape: OpportuniteEtape;
    statut: OpportuniteStatut;
    date_cloture_prevue: string | null;
    note: string | null;
    tiers_id: number;
    tiers: string | null;
    vendeur: string | null;
    close_at: string | null;
    created_at: string;
}

export type ActiviteType = 'appel' | 'email' | 'reunion' | 'note' | 'tache';

export interface Activite {
    id: number;
    type: ActiviteType;
    sujet: string;
    note: string | null;
    date_prevue: string | null;
    fait: boolean;
    fait_at: string | null;
    en_retard: boolean;
    tiers_id: number;
    tiers: string | null;
    opportunite_id: number | null;
    opportunite: string | null;
    vendeur: string | null;
    created_at: string;
}

export interface TimelineItem {
    kind: 'activite' | 'opportunite' | 'document';
    id: number;
    date: string | null;
    type?: string;
    titre: string;
    detail: string | null;
    statut: string;
}

export interface PipelineBoard {
    etapes: OpportuniteEtape[];
    colonnes: Record<OpportuniteEtape, Opportunite[]>;
    stats: {
        ouvertes: number;
        total_pipeline: string;
        forecast_pondere: string;
        gagnees_montant: string;
    };
}

export interface Societe {
    name: string;
    ice: string | null;
    if: string | null;
    rc: string | null;
    patente: string | null;
    cnss: string | null;
    address: string | null;
    city: string | null;
    postal_code: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
}

export interface Parametres {
    name: string;
    plan: string;
    features: Features;
    societe: Societe;
}

export type PermissionLevel = 'none' | 'read' | 'write';
export type Permissions = Record<string, PermissionLevel>;

export interface EquipeUser {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    is_active: boolean;
    is_superadmin: boolean;
    created_at: string;
}

export interface EquipeInvitation {
    id: number;
    email: string;
    role: string;
    role_label: string;
    token: string;
    expires_at: string;
}

export interface RoleOption {
    value: string;
    label: string;
}

export interface Subscription {
    plan: string;
    plan_label: string;
    included_seats: number;
    extra_seats: number;
    seat_limit: number;
    seats_used: number;
    pending_invitations: number;
    seats_available: number;
    extra_seat_price: number;
}

export interface EquipeData {
    users: EquipeUser[];
    invitations: EquipeInvitation[];
    roles: RoleOption[];
    subscription: Subscription;
}

export interface SuperadminTenant {
    id: number;
    name: string;
    slug: string;
    plan: string;
    plan_label: string;
    included_seats: number;
    extra_seats: number;
    seat_limit: number;
    seats_used: number;
    users_count: number;
    suspended: boolean;
    billing_cycle: string;
    subscription_status: string;
    effective_status: string;
    trial_ends_at: string | null;
    current_period_end: string | null;
    next_due: string | null;
    subscription_amount: number;
    mrr: number;
    payments_count: number;
    last_payment: { amount: number; paid_at: string } | null;
    created_at: string;
}

export interface SuperadminPayment {
    id: number;
    amount: number;
    method: string;
    paid_at: string;
    period_start: string;
    period_end: string;
    reference: string | null;
    note: string | null;
    has_invoice?: boolean;
}

export interface AbonnementFacture {
    id: number;
    amount: number;
    method: string;
    paid_at: string;
    period_start: string;
    period_end: string;
    reference: string | null;
    has_invoice: boolean;
}

export interface AbonnementData {
    subscription: {
        plan: string;
        plan_label: string;
        billing_cycle: string;
        status: string;
        current_period_end: string | null;
        trial_ends_at: string | null;
        amount: number;
    };
    factures: AbonnementFacture[];
}

export interface SuperadminStats {
    tenants_total: number;
    tenants_active: number;
    tenants_suspended: number;
    en_essai: number;
    en_retard: number;
    users_total: number;
    users_active: number;
    by_plan: Record<string, number>;
    extra_seats_sold: number;
    mrr_estimated: number;
    encaisse_mois: number;
}

export interface SuperadminData {
    tenants: SuperadminTenant[];
    stats: SuperadminStats;
    plans: { value: string; label: string; price: number; price_annual: number }[];
    methods: string[];
}

export interface DashboardKpi {
    value: number;
    previous?: number;
    variation_pct?: number | null;
}

export interface DashboardData {
    periode: { mois: string; annee: number };
    capabilities: { ventes: boolean; compta: boolean; achats: boolean; stock: boolean };
    kpis: {
        ca_mois?: DashboardKpi;
        ca_annee?: DashboardKpi;
        encaissements_mois?: DashboardKpi;
        tresorerie?: DashboardKpi;
        resultat?: DashboardKpi;
        creances?: { total: number; echu: number };
        dettes?: { total: number };
    };
    ventes_12_mois: { mois: string; ca: number; achats?: number }[];
    repartition_ventes: { devis: number; commandes: number; factures: number } | null;
    top_clients: { tiers_id: number | null; name: string; total: number }[];
    top_produits: { produit_id: number; name: string; total: number; quantite: number }[];
    alertes: {
        factures_echues?: { count: number; montant: number };
        devis_attente?: { count: number };
        stock_sous_seuil?: { count: number };
    };
}

export interface RelanceLigne {
    document_vente_id: number;
    code: string;
    tiers: string | null;
    tiers_id: number | null;
    date_echeance: string;
    jours_retard: number;
    total_ttc: string;
    reste_a_payer: string;
    dernier_niveau: number | null;
    nb_relances: number;
    derniere_relance: string | null;
}

export type EffetType = 'recevoir' | 'payer';
export type EffetStatut = 'portefeuille' | 'encaisse' | 'paye' | 'impaye';

export interface Effet {
    id: number;
    type: EffetType;
    code: string;
    montant: string;
    date_creation: string;
    date_echeance: string;
    statut: EffetStatut;
    en_retard: boolean;
    tiers: string | null;
    facture: string | null;
    created_at: string;
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
