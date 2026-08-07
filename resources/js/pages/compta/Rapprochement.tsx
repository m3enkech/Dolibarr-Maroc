import { useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { formatMAD } from '@/lib/format';

interface CompteRapprochable {
    id: number;
    code: string;
    label: string;
}

interface ReleveResume {
    id: number;
    libelle: string;
    compte: { code: string; label: string };
    date_fin: string | null;
    solde_final: string;
    statut: string;
    lignes_count: number;
    lignes_rapprochees_count: number;
}

interface EcritureNonPointee {
    id: number;
    date: string | null;
    libelle: string | null;
    flux: number;
    montant: string;
}

interface LigneReleve {
    id: number;
    date_operation: string;
    libelle: string;
    reference: string | null;
    montant: string;
    rapprochee: boolean;
    ecriture: { id: number; date: string | null; libelle: string | null } | null;
}

interface Etat {
    statement: {
        id: number;
        libelle: string;
        compte: { code: string; label: string };
        date_debut: string | null;
        date_fin: string | null;
        solde_initial: string;
        solde_final: string;
        statut: string;
    };
    lignes: LigneReleve[];
    ecritures_non_pointees: EcritureNonPointee[];
    soldes: {
        comptable: string;
        releve: string;
        rapproche: string;
        ecart: string;
        releve_non_pointe: string;
        compta_non_pointe: string;
    };
}

const errMsg = (err: any, fallback: string): string => {
    const messages = err?.response?.data?.errors;
    return messages ? (Object.values(messages).flat() as string[]).join(' ') : fallback;
};

const Montant = ({ value }: { value: string }) => {
    const n = parseFloat(value);
    return (
        <span className={`tabular-nums ${n < 0 ? 'text-red-600' : 'text-emerald-700'}`}>
            {n > 0 ? '+' : ''}
            {formatMAD(value)}
        </span>
    );
};

export default function Rapprochement() {
    const queryClient = useQueryClient();
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [info, setInfo] = useState<string | null>(null);

    // Formulaire d'import
    const [compteId, setCompteId] = useState('');
    const [soldeFinal, setSoldeFinal] = useState('');
    const [dateFin, setDateFin] = useState('');
    const [libelle, setLibelle] = useState('');
    const [fichier, setFichier] = useState<File | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const { data: index } = useQuery({
        queryKey: ['rapprochement-index'],
        queryFn: async () =>
            (await api.get<{ data: { comptes: CompteRapprochable[]; releves: ReleveResume[] } }>('/compta/rapprochement'))
                .data.data,
    });

    const { data: etat } = useQuery({
        queryKey: ['rapprochement', selectedId],
        queryFn: async () =>
            (await api.get<{ data: Etat }>(`/compta/rapprochement/${selectedId}`)).data.data,
        enabled: selectedId !== null,
    });

    const appliquerEtat = (e: Etat) => queryClient.setQueryData(['rapprochement', e.statement.id], e);
    const rafraichirIndex = () => queryClient.invalidateQueries({ queryKey: ['rapprochement-index'] });

    const importer = useMutation({
        mutationFn: () => {
            const form = new FormData();
            form.append('compte_id', compteId);
            form.append('fichier', fichier as File);
            if (soldeFinal) form.append('solde_final', soldeFinal);
            if (dateFin) form.append('date_fin', dateFin);
            if (libelle) form.append('libelle', libelle);
            return api.post<{ data: Etat }>('/compta/rapprochement/import', form);
        },
        onSuccess: ({ data }) => {
            setError(null);
            setFichier(null);
            setSoldeFinal('');
            setDateFin('');
            setLibelle('');
            if (inputRef.current) inputRef.current.value = '';
            appliquerEtat(data.data);
            setSelectedId(data.data.statement.id);
            rafraichirIndex();
        },
        onError: (err) => setError(errMsg(err, 'Import du relevé impossible (colonnes : Date, Libellé, Débit, Crédit — ou Montant).')),
    });

    const auto = useMutation({
        mutationFn: (id: number) => api.post<{ data: Etat; pointees: number }>(`/compta/rapprochement/${id}/auto`),
        onSuccess: ({ data }) => {
            setError(null);
            setInfo(`${data.pointees} ligne(s) pointée(s) automatiquement.`);
            appliquerEtat(data.data);
            rafraichirIndex();
        },
        onError: (err) => setError(errMsg(err, 'Pointage automatique impossible.')),
    });

    const pointer = useMutation({
        mutationFn: (v: { ligneId: number; ecritureLigneId: number }) =>
            api.post<{ data: Etat }>(`/compta/rapprochement/lignes/${v.ligneId}/pointer`, {
                ecriture_ligne_id: v.ecritureLigneId,
            }),
        onSuccess: ({ data }) => {
            setError(null);
            appliquerEtat(data.data);
            rafraichirIndex();
        },
        onError: (err) => setError(errMsg(err, 'Pointage impossible.')),
    });

    const depointer = useMutation({
        mutationFn: (ligneId: number) => api.post<{ data: Etat }>(`/compta/rapprochement/lignes/${ligneId}/depointer`),
        onSuccess: ({ data }) => {
            appliquerEtat(data.data);
            rafraichirIndex();
        },
    });

    const supprimer = useMutation({
        mutationFn: (id: number) => api.delete(`/compta/rapprochement/${id}`),
        onSuccess: () => {
            setSelectedId(null);
            rafraichirIndex();
        },
    });

    return (
        <div className="space-y-4">
            {/* Import d'un relevé */}
            <div className="rounded-xl bg-white p-5 shadow-sm">
                <h2 className="font-medium text-slate-900">Importer un relevé bancaire</h2>
                <p className="mt-1 text-sm text-slate-500">
                    Choisissez le compte de trésorerie, puis un fichier Excel/CSV du relevé
                    (colonnes <strong>Date, Libellé, Débit, Crédit</strong> — ou une colonne <strong>Montant</strong> signée).
                    Le solde final permet de contrôler le rapprochement.
                </p>
                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <select
                        value={compteId}
                        onChange={(e) => setCompteId(e.target.value)}
                        className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                    >
                        <option value="">Compte de trésorerie…</option>
                        {(index?.comptes ?? []).map((c) => (
                            <option key={c.id} value={c.id}>{c.code} — {c.label}</option>
                        ))}
                    </select>
                    <input
                        type="number"
                        step="0.01"
                        value={soldeFinal}
                        onChange={(e) => setSoldeFinal(e.target.value)}
                        placeholder="Solde final du relevé"
                        className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        type="date"
                        value={dateFin}
                        onChange={(e) => setDateFin(e.target.value)}
                        title="Date de fin du relevé"
                        className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                    />
                    <input
                        value={libelle}
                        onChange={(e) => setLibelle(e.target.value)}
                        placeholder="Libellé (ex. Relevé mars 2026)"
                        className="rounded-md border border-slate-300 px-3 py-2 text-sm"
                    />
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-3">
                    <input
                        ref={inputRef}
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        onChange={(e) => setFichier(e.target.files?.[0] ?? null)}
                        className="text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-emerald-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-emerald-700"
                    />
                    <button
                        onClick={() => importer.mutate()}
                        disabled={!compteId || !fichier || importer.isPending}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {importer.isPending ? 'Import…' : 'Importer le relevé'}
                    </button>
                </div>
            </div>

            {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}
            {info && <div className="rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{info}</div>}

            {/* Relevés existants */}
            {(index?.releves.length ?? 0) > 0 && (
                <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                    <div className="border-b border-slate-200 px-4 py-3 text-sm font-medium text-slate-900">Relevés</div>
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-4 py-2">Libellé</th>
                                <th className="px-4 py-2">Compte</th>
                                <th className="px-4 py-2">Fin</th>
                                <th className="px-4 py-2 text-right">Solde final</th>
                                <th className="px-4 py-2 text-center">Pointées</th>
                                <th className="px-4 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {(index?.releves ?? []).map((r) => (
                                <tr
                                    key={r.id}
                                    className={`cursor-pointer hover:bg-slate-50 ${selectedId === r.id ? 'bg-emerald-50/60' : ''}`}
                                    onClick={() => { setSelectedId(r.id); setInfo(null); setError(null); }}
                                >
                                    <td className="px-4 py-2 text-slate-800">{r.libelle}</td>
                                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{r.compte.code}</td>
                                    <td className="px-4 py-2 text-slate-600">{r.date_fin ?? '—'}</td>
                                    <td className="px-4 py-2 text-right tabular-nums text-slate-700">{formatMAD(r.solde_final)}</td>
                                    <td className="px-4 py-2 text-center text-xs text-slate-600">
                                        {r.lignes_rapprochees_count}/{r.lignes_count}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <button
                                            onClick={(e) => { e.stopPropagation(); if (confirm('Supprimer ce relevé ?')) supprimer.mutate(r.id); }}
                                            className="text-xs text-slate-400 hover:text-red-600"
                                        >
                                            Supprimer
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Détail du rapprochement */}
            {etat && (
                <div className="space-y-4">
                    {/* Soldes */}
                    <div className="grid gap-3 rounded-xl bg-white p-4 shadow-sm sm:grid-cols-4">
                        <Solde label="Solde comptable" value={etat.soldes.comptable} />
                        <Solde label="Solde du relevé" value={etat.soldes.releve} />
                        <Solde label="Solde rapproché" value={etat.soldes.rapproche} />
                        <div className="rounded-lg border border-slate-100 p-3">
                            <div className="text-xs text-slate-500">Écart</div>
                            <div
                                className={`mt-1 text-lg font-semibold tabular-nums ${
                                    Math.abs(parseFloat(etat.soldes.ecart)) < 0.005 ? 'text-emerald-600' : 'text-red-600'
                                }`}
                            >
                                {formatMAD(etat.soldes.ecart)}
                                {Math.abs(parseFloat(etat.soldes.ecart)) < 0.005 && ' ✓'}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => auto.mutate(etat.statement.id)}
                            disabled={auto.isPending}
                            className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {auto.isPending ? 'Pointage…' : '⚡ Pointer automatiquement'}
                        </button>
                        <span className="text-xs text-slate-500">
                            Relie chaque ligne de relevé à l'écriture de même montant.
                        </span>
                    </div>

                    {/* Lignes du relevé */}
                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="border-b border-slate-200 px-4 py-3 text-sm font-medium text-slate-900">
                            Lignes du relevé — {etat.statement.compte.code} {etat.statement.compte.label}
                        </div>
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-4 py-2">Date</th>
                                    <th className="px-4 py-2">Libellé</th>
                                    <th className="px-4 py-2 text-right">Montant</th>
                                    <th className="px-4 py-2">Rapprochement</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {etat.lignes.map((l) => {
                                    const candidates = etat.ecritures_non_pointees.filter(
                                        (e) => Math.abs(e.flux - parseFloat(l.montant)) < 0.005,
                                    );
                                    return (
                                        <tr key={l.id} className={l.rapprochee ? 'bg-emerald-50/40' : ''}>
                                            <td className="px-4 py-2 text-slate-600">{l.date_operation}</td>
                                            <td className="px-4 py-2 text-slate-700">
                                                {l.libelle || '—'}
                                                {l.reference && <span className="ml-1 text-xs text-slate-400">({l.reference})</span>}
                                            </td>
                                            <td className="px-4 py-2 text-right"><Montant value={l.montant} /></td>
                                            <td className="px-4 py-2">
                                                {l.rapprochee ? (
                                                    <div className="flex items-center gap-2">
                                                        <span className="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">
                                                            ✓ {l.ecriture?.date} · {l.ecriture?.libelle || 'écriture'}
                                                        </span>
                                                        <button
                                                            onClick={() => depointer.mutate(l.id)}
                                                            className="text-xs text-slate-400 hover:text-red-600"
                                                        >
                                                            dépointer
                                                        </button>
                                                    </div>
                                                ) : candidates.length > 0 ? (
                                                    <PointerLigne
                                                        candidates={candidates}
                                                        onPointer={(ecritureLigneId) => pointer.mutate({ ligneId: l.id, ecritureLigneId })}
                                                    />
                                                ) : (
                                                    <span className="text-xs text-slate-400">aucune écriture de même montant</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Écritures comptables non pointées */}
                    <div className="overflow-hidden rounded-xl bg-white shadow-sm">
                        <div className="border-b border-slate-200 px-4 py-3 text-sm font-medium text-slate-900">
                            Écritures du compte non pointées ({etat.ecritures_non_pointees.length})
                        </div>
                        {etat.ecritures_non_pointees.length === 0 ? (
                            <div className="px-4 py-6 text-center text-sm text-slate-400">
                                Toutes les écritures du compte sont pointées.
                            </div>
                        ) : (
                            <table className="w-full text-left text-sm">
                                <tbody className="divide-y divide-slate-100">
                                    {etat.ecritures_non_pointees.map((e) => (
                                        <tr key={e.id}>
                                            <td className="px-4 py-2 text-slate-600">{e.date}</td>
                                            <td className="px-4 py-2 text-slate-700">{e.libelle || '—'}</td>
                                            <td className="px-4 py-2 text-right"><Montant value={e.montant} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function Solde({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border border-slate-100 p-3">
            <div className="text-xs text-slate-500">{label}</div>
            <div className="mt-1 text-lg font-semibold tabular-nums text-slate-800">{formatMAD(value)}</div>
        </div>
    );
}

function PointerLigne({
    candidates,
    onPointer,
}: {
    candidates: EcritureNonPointee[];
    onPointer: (ecritureLigneId: number) => void;
}) {
    const [choix, setChoix] = useState(String(candidates[0]?.id ?? ''));
    return (
        <div className="flex items-center gap-2">
            <select
                value={choix}
                onChange={(e) => setChoix(e.target.value)}
                className="rounded border border-slate-300 px-2 py-1 text-xs"
            >
                {candidates.map((c) => (
                    <option key={c.id} value={c.id}>{c.date} · {c.libelle || 'écriture'}</option>
                ))}
            </select>
            <button
                onClick={() => choix && onPointer(Number(choix))}
                className="rounded bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700"
            >
                Pointer
            </button>
        </div>
    );
}
