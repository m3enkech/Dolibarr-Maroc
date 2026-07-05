import type { AchatStatut, AchatType, DocumentAchat } from '@/types';

export const ACHAT_TYPE_LABELS: Record<AchatType, string> = {
    commande: 'Commande',
    reception: 'Réception',
    facture: 'Facture',
};

export const ACHAT_TYPE_LABELS_PLURAL: Record<AchatType, string> = {
    commande: 'Commandes',
    reception: 'Réceptions',
    facture: 'Factures',
};

export function achatStatutLabel(doc: Pick<DocumentAchat, 'type' | 'statut'>): string {
    if (doc.type === 'facture' && doc.statut === 'valide') {
        return 'À payer';
    }
    const labels: Record<AchatStatut, string> = {
        brouillon: 'Brouillon',
        valide: 'Validée',
        recue_partielle: 'Reçue partiellement',
        recue: 'Reçue',
        paye: 'Payée',
    };
    return labels[doc.statut];
}

export function achatStatutClasses(statut: AchatStatut): string {
    const classes: Record<AchatStatut, string> = {
        brouillon: 'bg-slate-200 text-slate-700',
        valide: 'bg-sky-100 text-sky-700',
        recue_partielle: 'bg-amber-100 text-amber-700',
        recue: 'bg-emerald-100 text-emerald-700',
        paye: 'bg-emerald-100 text-emerald-700',
    };
    return classes[statut];
}
