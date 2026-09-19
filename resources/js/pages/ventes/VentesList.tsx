import { useEffect, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';
import { useDebounce } from '@/lib/useDebounce';
import { useT } from '@/lib/langue';
import Pagination from '@/components/Pagination';
import SelecteurAnnee from '@/components/SelecteurAnnee';
import { etatEcheance } from '@/lib/echeance';
import ApercuVente from '@/pages/ventes/ApercuVente';
import { statutClasses, statutLabel, TYPE_LABELS, TYPE_LABELS_PLURAL } from '@/pages/ventes/common';
import type { DocumentType, DocumentVente, Paginated } from '@/types';

const TABS: DocumentType[] = ['devis', 'commande', 'bon_livraison', 'facture', 'avoir'];

const STATUTS = ['brouillon', 'valide', 'accepte', 'refuse', 'paye'];

/** Colonnes triables : la liste blanche du serveur, et rien d'autre. */
const TRIABLES = ['code', 'date_document', 'date_echeance', 'total_ttc', 'statut'] as const;
type Triable = (typeof TRIABLES)[number];

export default function VentesList() {
    const t = useT();
    const [searchParams, setSearchParams] = useSearchParams();
    const type = (TABS.includes(searchParams.get('type') as DocumentType)
        ? searchParams.get('type')
        : 'devis') as DocumentType;

    const [search, setSearch] = useState('');
    const [annee, setAnnee] = useState<number | null>(null);
    const [statut, setStatut] = useState('');
    const [dateDebut, setDateDebut] = useState('');
    const [dateFin, setDateFin] = useState('');
    const [montantMin, setMontantMin] = useState('');
    const [echeance, setEcheance] = useState('');
    const [filtresOuverts, setFiltresOuverts] = useState(false);
    const [tri, setTri] = useState<Triable>('date_document');
    const [direction, setDirection] = useState<'asc' | 'desc'>('desc');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(15);
    const [apercu, setApercu] = useState<number | null>(null);

    // Sans anti-rebond, chaque frappe part en requête : sur une base d'un
    // millier de pièces, la liste clignote et le serveur travaille pour rien.
    const recherche = useDebounce(search, 250);

    // Toute modification d'un filtre ramène en page 1 : rester en page 40 d'un
    // résultat qui n'en compte plus que 2 affiche une liste vide, et on croit
    // que le filtre n'a rien trouvé.
    useEffect(() => {
        setPage(1);
    }, [recherche, annee, statut, dateDebut, dateFin, montantMin, echeance, type, perPage]);

    const params = {
        type,
        search: recherche || undefined,
        annee: annee ?? undefined,
        statut: statut || undefined,
        date_debut: dateDebut || undefined,
        date_fin: dateFin || undefined,
        montant_min: montantMin || undefined,
        echeance: echeance || undefined,
        tri,
        direction,
        page,
        per_page: perPage,
    };

    const { data, isLoading } = useQuery({
        queryKey: ['ventes', params],
        queryFn: async () => {
            const { data } = await api.get<Paginated<DocumentVente>>('/ventes/documents', { params });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    // Les années qui portent vraiment des pièces — proposer douze années en dur
    // afficherait surtout des zéros.
    const { data: annees } = useQuery({
        queryKey: ['ventes-annees', type],
        queryFn: async () => {
            const { data } = await api.get<{ data: { annee: number; total: number }[] }>(
                '/ventes/documents/annees',
                { params: { type } },
            );
            return data.data;
        },
    });

    const changerTri = (colonne: Triable) => {
        if (tri === colonne) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setTri(colonne);
            setDirection('desc');
        }
        setPage(1);
    };

    const switchTab = (tab: DocumentType) => {
        setSearchParams({ type: tab });
        setApercu(null);
    };

    const filtresActifs = [annee, statut, dateDebut, dateFin, montantMin, echeance].filter(Boolean).length;

    const entete = (colonne: Triable, libelle: string, aDroite = false) => (
        <th className={`px-4 py-3 ${aDroite ? 'text-end' : ''}`}>
            <button
                onClick={() => changerTri(colonne)}
                className="inline-flex items-center gap-1 uppercase tracking-wide transition hover:text-slate-900"
            >
                {t(libelle)}
                <span className={tri === colonne ? 'text-emerald-600' : 'text-slate-300'}>
                    {tri === colonne ? (direction === 'asc' ? '↑' : '↓') : '↕'}
                </span>
            </button>
        </th>
    );

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-semibold text-slate-900">{t('Ventes')}</h1>
                    <p className="mt-1 text-sm text-slate-500">{t('Devis, commandes, factures et avoirs')}</p>
                </div>
                <Link
                    to={`/ventes/nouveau?type=${type}`}
                    className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    + {t(TYPE_LABELS[type])}
                </Link>
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <div className="flex max-w-full overflow-x-auto rounded-lg border border-slate-200 bg-white p-1">
                    {TABS.map((tab) => (
                        <button
                            key={tab}
                            onClick={() => switchTab(tab)}
                            className={`shrink-0 whitespace-nowrap rounded-md px-4 py-1.5 text-sm font-medium transition ${
                                type === tab ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            {t(TYPE_LABELS_PLURAL[tab])}
                        </button>
                    ))}
                </div>

                <input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder={t('Code, client, ICE, bon de commande, article…')}
                    className="w-full min-w-0 flex-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 sm:w-80 sm:flex-none"
                />

                <button
                    onClick={() => setFiltresOuverts((o) => !o)}
                    className={`rounded-md border px-3 py-2 text-sm transition ${
                        filtresActifs > 0
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                            : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                    }`}
                >
                    {t('Filtres')}
                    {filtresActifs > 0 && ` (${filtresActifs})`}
                </button>
            </div>

            {/* L'exercice en premier : c'est le filtre qui rend l'historique
                repris atteignable, et il se pose d'un seul clic. */}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <SelecteurAnnee annees={annees ?? []} valeur={annee} onChange={setAnnee} />

                {/* La question qu'on se pose vraiment devant une liste de
                    factures : lesquelles sont en retard. */}
                {type === 'facture' && (
                    <div className="flex items-center gap-2">
                        {(
                            [
                                ['echue', 'En retard'],
                                ['a_echoir', 'À échoir'],
                            ] as const
                        ).map(([cle, libelle]) => (
                            <button
                                key={cle}
                                onClick={() => setEcheance(echeance === cle ? '' : cle)}
                                className={`rounded-full px-3 py-1 text-sm transition ${
                                    echeance === cle
                                        ? cle === 'echue'
                                            ? 'bg-red-600 text-white'
                                            : 'bg-amber-500 text-white'
                                        : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                                }`}
                            >
                                {t(libelle)}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {filtresOuverts && (
                <div className="grid gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
                    <Champ libelle={t('Statut')}>
                        <select
                            value={statut}
                            onChange={(e) => setStatut(e.target.value)}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                        >
                            <option value="">{t('Tous')}</option>
                            {STATUTS.map((s) => (
                                <option key={s} value={s}>
                                    {t(s)}
                                </option>
                            ))}
                        </select>
                    </Champ>
                    <Champ libelle={t('Du')}>
                        <input
                            type="date"
                            value={dateDebut}
                            onChange={(e) => setDateDebut(e.target.value)}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                        />
                    </Champ>
                    <Champ libelle={t('Au')}>
                        <input
                            type="date"
                            value={dateFin}
                            onChange={(e) => setDateFin(e.target.value)}
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                        />
                    </Champ>
                    <Champ libelle={t('Montant TTC minimum')}>
                        <input
                            type="number"
                            inputMode="decimal"
                            value={montantMin}
                            onChange={(e) => setMontantMin(e.target.value)}
                            placeholder="0"
                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                        />
                    </Champ>

                    {filtresActifs > 0 && (
                        <button
                            onClick={() => {
                                setAnnee(null);
                                setStatut('');
                                setDateDebut('');
                                setDateFin('');
                                setMontantMin('');
                            }}
                            className="justify-self-start text-sm text-slate-500 underline hover:text-slate-800"
                        >
                            {t('Effacer les filtres')}
                        </button>
                    )}
                </div>
            )}

            <div className={`grid gap-4 ${apercu !== null ? 'lg:grid-cols-3' : 'lg:grid-cols-1'}`}>
                <div className={`min-w-0 ${apercu !== null ? 'lg:col-span-2' : ''}`}>
                    <div className="overflow-x-auto rounded-xl bg-white shadow-sm">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    {entete('code', 'Code')}
                                    {entete('date_document', 'Date')}
                                    <th className="px-4 py-3 uppercase tracking-wide">{t('Client')}</th>
                                    {type === 'facture' && entete('date_echeance', 'Échéance')}
                                    {entete('total_ttc', 'Total TTC', true)}
                                    {type === 'facture' && (
                                        <th className="px-4 py-3 text-end uppercase tracking-wide">{t('Reste à payer')}</th>
                                    )}
                                    {entete('statut', 'Statut')}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {isLoading && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-8 text-center text-slate-400">
                                            {t('Chargement…')}
                                        </td>
                                    </tr>
                                )}
                                {!isLoading && data?.data.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-8 text-center text-slate-400">
                                            {filtresActifs > 0 || recherche
                                                ? t('Aucun document ne correspond à ces critères.')
                                                : t('Aucun document. Créez le premier !')}
                                        </td>
                                    </tr>
                                )}
                                {data?.data.map((doc) => (
                                    <tr
                                        key={doc.id}
                                        onClick={() => setApercu(doc.id)}
                                        className={`cursor-pointer transition ${
                                            apercu === doc.id ? 'bg-emerald-50' : 'hover:bg-slate-50'
                                        }`}
                                    >
                                        <td className="px-4 py-3 font-mono text-xs font-medium text-emerald-700">
                                            {doc.code}
                                        </td>
                                        <td className="px-4 py-3 text-slate-600">{doc.date_document}</td>
                                        <td className="px-4 py-3 font-medium text-slate-900">{doc.tiers?.name}</td>
                                        {type === 'facture' && (
                                            <td className="px-4 py-3">
                                                <Echeance doc={doc} />
                                            </td>
                                        )}
                                        <td className="px-4 py-3 text-end tabular-nums text-slate-900">
                                            {formatMAD(doc.total_ttc)}
                                        </td>
                                        {type === 'facture' && (
                                            <td className="px-4 py-3 text-end tabular-nums text-slate-600">
                                                {doc.statut === 'brouillon'
                                                    ? '—'
                                                    : formatMAD(doc.reste_a_payer ?? doc.total_ttc)}
                                            </td>
                                        )}
                                        <td className="px-4 py-3">
                                            <span className={`rounded px-1.5 py-0.5 text-xs ${statutClasses(doc.statut)}`}>
                                                {statutLabel(doc)}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>

                        {data && (
                            <Pagination
                                meta={{ ...data.meta, per_page: perPage }}
                                onPage={setPage}
                                onPerPage={setPerPage}
                            />
                        )}
                    </div>
                </div>

                {apercu !== null && <ApercuVente id={apercu} onFermer={() => setApercu(null)} />}
            </div>
        </div>
    );
}

/**
 * L'échéance d'une facture, et ce qu'il en reste.
 *
 * Le décompte ne s'affiche QUE sur une facture encore due : sur une pièce
 * soldée il ferait croire à un retard qui n'existe pas, et c'est ainsi qu'on
 * relance un client qui ne doit rien.
 */
function Echeance({ doc }: { doc: DocumentVente }) {
    const t = useT();
    const etat = etatEcheance(doc.date_echeance);

    if (!etat) return <span className="text-slate-300">—</span>;

    const due = doc.statut === 'valide';

    return (
        <div className="whitespace-nowrap">
            <div className="text-slate-600">{doc.date_echeance}</div>
            {due && (
                <div
                    className={`text-xs font-medium ${
                        etat.depassee ? 'text-red-600' : etat.proche ? 'text-amber-600' : 'text-slate-400'
                    }`}
                >
                    {t(etat.libelle)}
                </div>
            )}
        </div>
    );
}

function Champ({ libelle, children }: { libelle: string; children: React.ReactNode }) {
    return (
        <label className="block min-w-0">
            <span className="mb-1 block text-xs uppercase tracking-wide text-slate-500">{libelle}</span>
            {children}
        </label>
    );
}
