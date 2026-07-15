import type { DocumentVente, Tenant } from '@/types';
import { dh } from '@/pages/pos/ui';

const MODE_LABELS: Record<string, string> = {
    especes: 'Espèces',
    carte: 'Carte',
    cheque: 'Chèque',
    virement: 'Virement',
    autre: 'Autre',
};

interface PosTicketProps {
    doc: DocumentVente;
    tenant: Tenant | null;
    vendeur: string | null;
    rendu: string | null;
    donne: number | null;
}

/**
 * Ticket de caisse façon imprimante thermique 80 mm. Sert à la fois d'aperçu
 * (dans l'overlay de succès) et de source d'impression (#pos-ticket, ciblé par
 * les règles @media print de la page caisse).
 */
export default function PosTicket({ doc, tenant, vendeur, rendu, donne }: PosTicketProps) {
    const tvaParTaux = new Map<string, { ht: number; tva: number }>();
    let remiseTotale = 0;
    for (const ligne of doc.lignes ?? []) {
        const taux = `${parseFloat(ligne.tva_rate)}`;
        const entry = tvaParTaux.get(taux) ?? { ht: 0, tva: 0 };
        entry.ht += parseFloat(ligne.montant_ht);
        entry.tva += parseFloat(ligne.montant_tva);
        tvaParTaux.set(taux, entry);
        remiseTotale += parseFloat(ligne.quantite) * parseFloat(ligne.prix_unitaire) - parseFloat(ligne.montant_ht);
    }
    remiseTotale = Math.round(remiseTotale * 100) / 100;

    return (
        <div
            id="pos-ticket"
            className="mx-auto w-[290px] bg-white px-4 py-5 font-mono text-[11px] leading-snug text-slate-900"
        >
            <div className="text-center">
                <div className="text-sm font-bold uppercase">{tenant?.name ?? 'Dolibarr Maroc'}</div>
                <div className="mt-1 text-[10px]">Ticket de caisse</div>
                <div className="mt-2 text-[10px]">
                    {doc.code} — {new Date(doc.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}
                </div>
                {vendeur && <div className="text-[10px]">Vendeur : {vendeur}</div>}
            </div>

            <div className="my-3 border-t border-dashed border-slate-400" />

            {(doc.lignes ?? []).map((ligne) => (
                <div key={ligne.id} className="mb-1.5">
                    <div className="truncate">{ligne.designation}</div>
                    <div className="flex justify-between tabular-nums">
                        <span>
                            {parseFloat(ligne.quantite)} × {dh(ligne.prix_unitaire)}
                            {parseFloat(ligne.remise_percent) > 0 && ` (−${parseFloat(ligne.remise_percent)}%)`}
                        </span>
                        <span>{dh(ligne.montant_ttc)}</span>
                    </div>
                </div>
            ))}

            <div className="my-3 border-t border-dashed border-slate-400" />

            <div className="space-y-0.5 tabular-nums">
                {remiseTotale > 0 && (
                    <div className="flex justify-between">
                        <span>Remise</span>
                        <span>−{dh(remiseTotale)}</span>
                    </div>
                )}
                <div className="flex justify-between">
                    <span>Total HT</span>
                    <span>{dh(doc.total_ht)}</span>
                </div>
                {[...tvaParTaux.entries()].map(([taux, montants]) => (
                    <div key={taux} className="flex justify-between text-[10px] text-slate-600">
                        <span>TVA {taux} %</span>
                        <span>{dh(montants.tva)}</span>
                    </div>
                ))}
                <div className="mt-1 flex justify-between border-t border-slate-300 pt-1 text-[13px] font-bold">
                    <span>TOTAL TTC</span>
                    <span>{dh(doc.total_ttc)} DH</span>
                </div>
            </div>

            <div className="my-3 border-t border-dashed border-slate-400" />

            <div className="space-y-0.5 tabular-nums">
                {(doc.paiements ?? []).map((paiement) => (
                    <div key={paiement.id} className="flex justify-between">
                        <span>{MODE_LABELS[paiement.mode] ?? paiement.mode}</span>
                        <span>{dh(paiement.montant)}</span>
                    </div>
                ))}
                {donne !== null && donne > 0 && (
                    <div className="flex justify-between text-[10px] text-slate-600">
                        <span>Espèces remises</span>
                        <span>{dh(donne)}</span>
                    </div>
                )}
                {rendu !== null && parseFloat(rendu) > 0 && (
                    <div className="flex justify-between font-bold">
                        <span>RENDU</span>
                        <span>{dh(rendu)} DH</span>
                    </div>
                )}
            </div>

            <div className="my-3 border-t border-dashed border-slate-400" />

            <div className="text-center text-[10px]">
                <div>Merci de votre visite !</div>
                <div className="mt-1 text-slate-500">Généré par Dolibarr Maroc</div>
            </div>
        </div>
    );
}
