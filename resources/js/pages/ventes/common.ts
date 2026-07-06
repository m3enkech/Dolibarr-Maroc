import type { DocumentStatut, DocumentType, DocumentVente } from '@/types';

export const TYPE_LABELS: Record<DocumentType, string> = {
    devis: 'Devis',
    commande: 'Commande',
    bon_livraison: 'Bon de livraison',
    facture: 'Facture',
    avoir: 'Avoir',
};

export const TYPE_LABELS_PLURAL: Record<DocumentType, string> = {
    devis: 'Devis',
    commande: 'Commandes',
    bon_livraison: 'Bons de livraison',
    facture: 'Factures',
    avoir: 'Avoirs',
};

export const MODES_PAIEMENT: Record<string, string> = {
    especes: 'Espèces',
    cheque: 'Chèque',
    virement: 'Virement',
    carte: 'Carte',
    autre: 'Autre',
};

export function statutLabel(doc: Pick<DocumentVente, 'type' | 'statut'>): string {
    if (doc.type === 'facture' && doc.statut === 'valide') {
        return 'À encaisser';
    }
    if (doc.type === 'avoir' && doc.statut === 'valide') {
        return 'À rembourser';
    }
    if (doc.type === 'avoir' && doc.statut === 'paye') {
        return 'Remboursé';
    }
    if (doc.type === 'bon_livraison' && doc.statut === 'valide') {
        return 'Livré';
    }
    const labels: Record<DocumentStatut, string> = {
        brouillon: 'Brouillon',
        valide: 'Validé',
        accepte: 'Accepté',
        refuse: 'Refusé',
        paye: 'Payée',
    };
    return labels[doc.statut];
}

export function statutClasses(statut: DocumentStatut): string {
    const classes: Record<DocumentStatut, string> = {
        brouillon: 'bg-slate-200 text-slate-700',
        valide: 'bg-sky-100 text-sky-700',
        accepte: 'bg-emerald-100 text-emerald-700',
        refuse: 'bg-red-100 text-red-700',
        paye: 'bg-emerald-100 text-emerald-700',
    };
    return classes[statut];
}
