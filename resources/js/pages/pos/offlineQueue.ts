/**
 * File d'attente des ventes de caisse hors-ligne.
 *
 * Quand le réseau tombe, une vente est stockée localement (localStorage) avec
 * un `client_uuid`. Dès le retour en ligne, `syncQueue()` la rejoue vers
 * `POST /pos/ventes` : le backend est idempotent sur `client_uuid`, donc un
 * rejeu après un accusé de réception perdu ne crée jamais de doublon.
 */

import { api } from '@/lib/api';
import { calcLigne, calcTotaux, remiseEffective, type CartLine } from '@/pages/pos/ui';
import type { DocumentVente } from '@/types';
import type { PaiementSaisi } from '@/pages/pos/PosPaiement';

export interface QueuedSale {
    client_uuid: string;
    body: Record<string, unknown>;
    created_at: string;
}

const KEY = 'pos-offline-queue';
const FAILED_KEY = 'pos-offline-failed';

function read(key: string): QueuedSale[] {
    try {
        const raw = localStorage.getItem(key);
        return raw ? (JSON.parse(raw) as QueuedSale[]) : [];
    } catch {
        return [];
    }
}

function write(key: string, items: QueuedSale[]): void {
    localStorage.setItem(key, JSON.stringify(items));
}

export function enqueueSale(sale: QueuedSale): void {
    write(KEY, [...read(KEY), sale]);
}

export function queueCount(): number {
    return read(KEY).length;
}

export function failedCount(): number {
    return read(FAILED_KEY).length;
}

let syncing = false;

/**
 * Rejoue les ventes en file, dans l'ordre. Renvoie le nombre synchronisé.
 * - succès (2xx) → retirée de la file ;
 * - rejet serveur définitif (4xx) → mise en « échec » (dead-letter) pour ne pas boucler ;
 * - erreur réseau / 5xx → conservée, on s'arrête (nouvel essai plus tard).
 */
export async function syncQueue(): Promise<number> {
    if (syncing) return 0;
    syncing = true;
    let synced = 0;

    try {
        for (const sale of read(KEY)) {
            try {
                await api.post('/pos/ventes', sale.body);
                write(KEY, read(KEY).filter((s) => s.client_uuid !== sale.client_uuid));
                synced++;
            } catch (err: unknown) {
                const status = (err as { response?: { status?: number } })?.response?.status;
                if (status !== undefined && status >= 400 && status < 500) {
                    write(KEY, read(KEY).filter((s) => s.client_uuid !== sale.client_uuid));
                    write(FAILED_KEY, [...read(FAILED_KEY), sale]);
                } else {
                    break; // réseau ou serveur indisponible : on réessaiera
                }
            }
        }
    } finally {
        syncing = false;
    }

    return synced;
}

/**
 * Construit un document de vente provisoire pour l'aperçu/ticket d'une vente
 * encaissée hors-ligne (mêmes arrondis que le backend). Le code définitif
 * (FA-…) sera attribué à la synchronisation.
 */
export function buildLocalDoc(
    cart: CartLine[],
    paiements: PaiementSaisi[],
    remiseTicket: number,
    clientUuid: string,
): DocumentVente {
    const totaux = calcTotaux(cart, remiseTicket);

    const doc = {
        id: -1,
        code: 'HORS-LIGNE',
        client_uuid: clientUuid,
        type: 'facture',
        statut: 'paye',
        created_at: new Date().toISOString(),
        total_ht: totaux.ht.toFixed(2),
        total_tva: totaux.tva.toFixed(2),
        total_ttc: totaux.ttc.toFixed(2),
        tiers: { name: 'Client comptoir' },
        lignes: cart.map((line, i) => {
            const c = calcLigne(line, remiseTicket);
            return {
                id: i,
                designation: line.designation,
                quantite: String(line.quantite),
                prix_unitaire: line.prix.toFixed(2),
                remise_percent: remiseEffective(line, remiseTicket).toFixed(2),
                tva_rate: line.tva.toFixed(2),
                montant_ht: c.ht.toFixed(2),
                montant_tva: c.tva.toFixed(2),
                montant_ttc: c.ttc.toFixed(2),
            };
        }),
        paiements: paiements.map((p, i) => ({
            id: i,
            mode: p.mode,
            montant: String(p.montant),
            reference: null,
        })),
    };

    return doc as unknown as DocumentVente;
}
