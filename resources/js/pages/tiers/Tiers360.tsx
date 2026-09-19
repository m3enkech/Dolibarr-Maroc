import { useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { useT } from '@/lib/langue';
import Pagination from '@/components/Pagination';
import ApercuVente from '@/pages/ventes/ApercuVente';
import { statutClasses, statutLabel } from '@/pages/ventes/common';
import ContactsTiers from '@/pages/tiers/ContactsTiers';
import TiersTimeline from '@/pages/tiers/TiersTimeline';
import type { DocumentAchat, DocumentVente, Paginated, Tiers } from '@/types';

type Synthese = {
    ventes: { devis: number; commandes: number; bons_livraison: number; factures: number; avoirs: number };
    achats: number;
    contacts: number;
    ca_ttc: string;
    ca_12_mois: string;
    impaye: string;
    achats_ttc: string;
    premier_document: string | null;
    dernier_document: string | null;
};

type ProduitEchange = {
    produit_id: number;
    code: string | null;
    name: string | null;
    unit: string | null;
    quantite: string;
    montant_ht: string;
    occurrences: number;
};

type Onglet = 'devis' | 'commande' | 'bon_livraison' | 'facture' | 'avoir' | 'achats' | 'produits' | 'contacts' | 'historique';

/**
 * La fiche d'un tiers, en CONSULTATION.
 *
 * Elle répond aux questions qu'on se pose devant un client avant de l'appeler :
 * combien il pèse, ce qu'il doit, ce qu'il prend d'habitude, à qui parler. Il
 * fallait jusqu'ici ouvrir quatre écrans et recouper de tête.
 *
 * TROIS PARTIS PRIS.
 *
 * 1. CONSULTATION PAR DÉFAUT, ÉDITION SUR DEMANDE. `/tiers/:id` ouvrait le
 *    FORMULAIRE : on venait regarder l'historique d'un client, on se retrouvait
 *    à pouvoir modifier son ICE. L'édition vit maintenant sur
 *    `/tiers/:id/modifier`, derrière un bouton.
 *
 * 2. LES ONGLETS CHARGENT CE QU'ON OUVRE, ET RIEN D'AUTRE. Les compteurs
 *    viennent d'une synthèse d'agrégats ; les pièces, de l'endpoint de liste
 *    déjà paginé et filtré. Charger les quatre cents factures d'un gros client
 *    pour n'en afficher que le nombre, c'est payer la page pour un chiffre.
 *
 * 3. MÊME APERÇU QUE LA LISTE DES VENTES. Cliquer une facture ici ou là-bas
 *    donne exactement la même chose — un composant, pas deux.
 */
export default function Tiers360() {
    const t = useT();
    const { id } = useParams<{ id: string }>();
    const [onglet, setOnglet] = useState<Onglet>('facture');
    const [page, setPage] = useState(1);
    const [apercu, setApercu] = useState<number | null>(null);

    const { data: tiers } = useQuery({
        queryKey: ['tiers', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Tiers }>(`/tiers/${id}`);
            return data.data;
        },
    });

    const { data: synthese } = useQuery({
        queryKey: ['tiers-synthese', id],
        queryFn: async () => {
            const { data } = await api.get<{ data: Synthese }>(`/tiers/${id}/synthese`);
            return data.data;
        },
    });

    const estDocument = ['devis', 'commande', 'bon_livraison', 'facture', 'avoir'].includes(onglet);

    const { data: documents, isLoading: chargeDocs } = useQuery({
        queryKey: ['tiers-documents', id, onglet, page],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentVente>>('/ventes/documents', {
                params: { tiers_id: id, type: onglet, page, per_page: 15 },
            });
            return data;
        },
        enabled: estDocument,
        placeholderData: keepPreviousData,
    });

    const { data: achats, isLoading: chargeAchats } = useQuery({
        queryKey: ['tiers-achats', id, page],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentAchat>>('/achats/documents', {
                params: { tiers_id: id, page, per_page: 15 },
            });
            return data;
        },
        enabled: onglet === 'achats',
        placeholderData: keepPreviousData,
    });

    const { data: produits, isLoading: chargeProduits } = useQuery({
        queryKey: ['tiers-produits', id, tiers?.is_supplier],
        queryFn: async () => {
            const { data } = await api.get<{ data: ProduitEchange[] }>(`/tiers/${id}/produits`, {
                params: { sens: tiers?.is_client ? 'ventes' : 'achats' },
            });
            return data.data;
        },
        enabled: onglet === 'produits',
    });

    const changerOnglet = (o: Onglet) => {
        setOnglet(o);
        setPage(1);
        setApercu(null);
    };

    const ONGLETS: { cle: Onglet; label: string; compte?: number }[] = [
        { cle: 'facture', label: 'Factures', compte: synthese?.ventes.factures },
        { cle: 'commande', label: 'Commandes', compte: synthese?.ventes.commandes },
        { cle: 'devis', label: 'Devis', compte: synthese?.ventes.devis },
        { cle: 'bon_livraison', label: 'Bons de livraison', compte: synthese?.ventes.bons_livraison },
        { cle: 'avoir', label: 'Avoirs', compte: synthese?.ventes.avoirs },
        ...(tiers?.is_supplier ? [{ cle: 'achats' as Onglet, label: 'Achats', compte: synthese?.achats }] : []),
        { cle: 'produits', label: 'Articles' },
        { cle: 'contacts', label: 'Contacts', compte: synthese?.contacts },
        { cle: 'historique', label: 'Historique' },
    ];

    if (!tiers) {
        return <div className="py-12 text-center text-sm text-slate-400">{t('Chargement…')}</div>;
    }

    return (
        <div className="space-y-4">
            {/* ---------------------------- identité ---------------------------- */}
            <div className="rounded-xl bg-white p-5 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <Link to="/tiers" className="text-sm text-emerald-700 hover:underline">
                            ← {t('Tous les tiers')}
                        </Link>
                        <h1 className="mt-1 text-xl font-semibold text-slate-900">{tiers.name}</h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                            <span className="font-mono text-xs">{tiers.code}</span>
                            {tiers.is_client && <Etiquette couleur="emerald">{t('Client')}</Etiquette>}
                            {tiers.is_supplier && <Etiquette couleur="sky">{t('Fournisseur')}</Etiquette>}
                            {tiers.is_prospect && <Etiquette couleur="violet">{t('Prospect')}</Etiquette>}
                            {!tiers.is_active && <Etiquette couleur="slate">{t('Inactif')}</Etiquette>}
                        </div>
                        <div className="mt-2 space-y-0.5 text-sm text-slate-600">
                            {tiers.ice && <div>ICE : {tiers.ice}</div>}
                            {tiers.address && <div>{tiers.address}</div>}
                            {(tiers.city || tiers.postal_code) && (
                                <div>{[tiers.postal_code, tiers.city].filter(Boolean).join(' ')}</div>
                            )}
                            {tiers.phone && <div>{tiers.phone}</div>}
                            {tiers.email && <div>{tiers.email}</div>}
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Link
                            to={`/ventes/nouveau?type=devis&tiers_id=${tiers.id}`}
                            className="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                        >
                            + {t('Devis')}
                        </Link>
                        <Link
                            to={`/ventes/nouveau?type=facture&tiers_id=${tiers.id}`}
                            className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                        >
                            + {t('Facture')}
                        </Link>
                        <Link
                            to={`/tiers/${tiers.id}/modifier`}
                            className="rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                        >
                            {t('Modifier')}
                        </Link>
                    </div>
                </div>

                {synthese && (
                    <div className="mt-5 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Kpi libelle={t('Chiffre d\'affaires')} valeur={formatMAD(synthese.ca_ttc)} />
                        <Kpi libelle={t('Sur 12 mois')} valeur={formatMAD(synthese.ca_12_mois)} />
                        <Kpi
                            libelle={t('Impayé')}
                            valeur={formatMAD(synthese.impaye)}
                            alerte={Number(synthese.impaye) > 0}
                        />
                        <Kpi
                            libelle={t('Relation depuis')}
                            valeur={synthese.premier_document ?? '—'}
                            detail={synthese.dernier_document ? `${t('dernière pièce')} ${synthese.dernier_document}` : undefined}
                        />
                    </div>
                )}
            </div>

            {/* ---------------------------- onglets ----------------------------- */}
            <div className="flex max-w-full gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1">
                {ONGLETS.map((o) => (
                    <button
                        key={o.cle}
                        onClick={() => changerOnglet(o.cle)}
                        className={`shrink-0 whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition ${
                            onglet === o.cle ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        {t(o.label)}
                        {o.compte !== undefined && (
                            <span className={onglet === o.cle ? 'ms-1.5 text-emerald-100' : 'ms-1.5 text-slate-400'}>
                                {o.compte}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* ---------------------------- contenu ----------------------------- */}
            {onglet === 'historique' && <TiersTimeline tiersId={String(tiers.id)} />}

            {onglet === 'contacts' && <ContactsTiers tiersId={tiers.id} />}

            {onglet === 'produits' && (
                <Tableau
                    vide={chargeProduits ? t('Chargement…') : t('Aucun article facturé à ce tiers.')}
                    montrerVide={!produits || produits.length === 0}
                    entetes={[t('Référence'), t('Article'), t('Quantité'), t('Total HT'), t('Pièces')]}
                >
                    {produits?.map((p) => (
                        <tr key={p.produit_id} className="hover:bg-slate-50">
                            <td className="px-4 py-3 font-mono text-xs text-slate-500">{p.code}</td>
                            <td className="px-4 py-3 font-medium text-slate-900">{p.name}</td>
                            <td className="px-4 py-3 text-end tabular-nums text-slate-600">
                                {Number(p.quantite).toLocaleString('fr-MA')} {p.unit}
                            </td>
                            <td className="px-4 py-3 text-end tabular-nums text-slate-900">{formatMAD(p.montant_ht)}</td>
                            <td className="px-4 py-3 text-end tabular-nums text-slate-500">{p.occurrences}</td>
                        </tr>
                    ))}
                </Tableau>
            )}

            {onglet === 'achats' && (
                <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                    <Tableau
                        vide={chargeAchats ? t('Chargement…') : t('Aucun achat auprès de ce fournisseur.')}
                        montrerVide={!achats || achats.data.length === 0}
                        entetes={[t('Code'), t('Date'), t('Total TTC'), t('Statut')]}
                        sansCadre
                    >
                        {achats?.data.map((doc) => (
                            <tr key={doc.id} className="hover:bg-slate-50">
                                <td className="px-4 py-3">
                                    <Link to={`/achats/${doc.id}`} className="font-mono text-xs text-emerald-700 hover:underline">
                                        {doc.code}
                                    </Link>
                                </td>
                                <td className="px-4 py-3 text-slate-600">{doc.date_document}</td>
                                <td className="px-4 py-3 text-end tabular-nums text-slate-900">{formatMAD(doc.total_ttc)}</td>
                                <td className="px-4 py-3 text-slate-600">{doc.statut}</td>
                            </tr>
                        ))}
                    </Tableau>
                    {achats && <Pagination meta={achats.meta} onPage={setPage} />}
                </div>
            )}

            {estDocument && (
                <div className={`grid gap-4 ${apercu !== null ? 'lg:grid-cols-3' : 'lg:grid-cols-1'}`}>
                    <div className={`min-w-0 ${apercu !== null ? 'lg:col-span-2' : ''}`}>
                        <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                            <Tableau
                                vide={chargeDocs ? t('Chargement…') : t('Aucune pièce de ce type pour ce tiers.')}
                                montrerVide={!documents || documents.data.length === 0}
                                entetes={[t('Code'), t('Date'), t('Total TTC'), t('Statut')]}
                                sansCadre
                            >
                                {documents?.data.map((doc) => (
                                    <tr
                                        key={doc.id}
                                        onClick={() => setApercu(doc.id)}
                                        className={`cursor-pointer transition ${
                                            apercu === doc.id ? 'bg-emerald-50' : 'hover:bg-slate-50'
                                        }`}
                                    >
                                        <td className="px-4 py-3 font-mono text-xs font-medium text-emerald-700">{doc.code}</td>
                                        <td className="px-4 py-3 text-slate-600">{doc.date_document}</td>
                                        <td className="px-4 py-3 text-end tabular-nums text-slate-900">
                                            {formatMAD(doc.total_ttc)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`rounded px-1.5 py-0.5 text-xs ${statutClasses(doc.statut)}`}>
                                                {statutLabel(doc)}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </Tableau>
                            {documents && <Pagination meta={documents.meta} onPage={setPage} />}
                        </div>
                    </div>

                    {apercu !== null && <ApercuVente id={apercu} onFermer={() => setApercu(null)} />}
                </div>
            )}
        </div>
    );
}

/* ---------------------------------------------------------------------- */

function Kpi({
    libelle,
    valeur,
    detail,
    alerte,
}: {
    libelle: string;
    valeur: string;
    detail?: string;
    alerte?: boolean;
}) {
    return (
        <div className="min-w-0 rounded-lg bg-slate-50 px-4 py-3">
            <div className="text-xs uppercase tracking-wide text-slate-500">{libelle}</div>
            <div className={`mt-0.5 truncate text-lg font-semibold ${alerte ? 'text-amber-700' : 'text-slate-900'}`}>
                {valeur}
            </div>
            {detail && <div className="truncate text-xs text-slate-400">{detail}</div>}
        </div>
    );
}

function Etiquette({ couleur, children }: { couleur: string; children: React.ReactNode }) {
    const couleurs: Record<string, string> = {
        emerald: 'bg-emerald-100 text-emerald-700',
        sky: 'bg-sky-100 text-sky-700',
        violet: 'bg-violet-100 text-violet-700',
        slate: 'bg-slate-200 text-slate-600',
    };

    return <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${couleurs[couleur]}`}>{children}</span>;
}

function Tableau({
    entetes,
    children,
    vide,
    montrerVide,
    sansCadre,
}: {
    entetes: string[];
    children: React.ReactNode;
    vide: string;
    montrerVide: boolean;
    sansCadre?: boolean;
}) {
    return (
        <div className={sansCadre ? '' : 'overflow-x-auto rounded-xl bg-white shadow-sm'}>
            <table className="w-full text-left text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        {entetes.map((e, i) => (
                            <th key={e} className={`px-4 py-3 ${i >= 2 ? 'text-end' : ''}`}>
                                {e}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {montrerVide ? (
                        <tr>
                            <td colSpan={entetes.length} className="px-4 py-8 text-center text-slate-400">
                                {vide}
                            </td>
                        </tr>
                    ) : (
                        children
                    )}
                </tbody>
            </table>
        </div>
    );
}
