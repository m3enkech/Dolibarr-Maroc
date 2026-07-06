import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Numpad, dh } from '@/pages/pos/ui';
import type { PosRapport, PosSession } from '@/types';

const MODE_LABELS: Record<string, string> = {
    especes: 'Espèces',
    carte: 'Carte',
    cheque: 'Chèque',
    virement: 'Virement',
    autre: 'Autre',
};

/* ------------------------------------------------------------------ */
/* Ouverture de caisse                                                 */
/* ------------------------------------------------------------------ */

interface OuvrirCaisseProps {
    pending: boolean;
    error: string | null;
    onOuvrir: (fondCaisse: number) => void;
}

export function OuvrirCaisse({ pending, error, onOuvrir }: OuvrirCaisseProps) {
    const [fond, setFond] = useState('');

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/90 p-4 backdrop-blur">
            <div
                className="w-full max-w-md rounded-3xl border border-white/10 bg-slate-900/90 p-8 text-center shadow-2xl shadow-emerald-500/10"
                style={{ animation: 'pos-pop 0.3s ease-out' }}
            >
                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-400/10 text-3xl shadow-[0_0_40px_rgba(52,211,153,0.2)]">
                    🔓
                </div>
                <h2 className="mt-4 text-2xl font-bold text-white">Ouvrir la caisse</h2>
                <p className="mt-1 text-sm text-slate-400">
                    Saisissez le fond de caisse (espèces présentes dans le tiroir).
                </p>

                {error && (
                    <div className="mt-4 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">
                        {error}
                    </div>
                )}

                <div className="mt-6 flex h-16 items-center justify-between rounded-2xl border border-white/10 bg-black/40 px-5">
                    <span className="text-xs uppercase tracking-widest text-slate-500">Fond de caisse</span>
                    <span className="text-3xl font-bold tabular-nums text-white">{fond === '' ? '0' : fond}</span>
                </div>

                <div className="mt-4">
                    <Numpad value={fond} onChange={setFond} />
                </div>

                <button
                    onClick={() => onOuvrir(fond === '' ? 0 : parseFloat(fond))}
                    disabled={pending}
                    className="mt-5 h-14 w-full rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-base font-bold uppercase tracking-widest text-slate-950 shadow-[0_0_40px_rgba(16,185,129,0.35)] transition active:scale-[0.98] disabled:opacity-40"
                >
                    {pending ? 'Ouverture…' : 'Démarrer la session'}
                </button>

                <Link
                    to="/dashboard"
                    className="mt-4 inline-block text-sm text-slate-500 transition hover:text-slate-300"
                >
                    ← Retour au tableau de bord
                </Link>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Clôture de caisse (Z)                                               */
/* ------------------------------------------------------------------ */

interface FermerCaisseProps {
    session: PosSession;
    rapport: PosRapport;
    pending: boolean;
    error: string | null;
    onFermer: (montantCompte: number) => void;
    onCancel: () => void;
}

export function FermerCaisse({ session, rapport, pending, error, onFermer, onCancel }: FermerCaisseProps) {
    const [compte, setCompte] = useState('');

    const theorique = parseFloat(rapport.especes_theorique);
    const compteNum = compte === '' ? null : parseFloat(compte);
    const ecart = compteNum === null ? null : Math.round((compteNum - theorique) * 100) / 100;

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center overflow-y-auto bg-slate-950/90 p-4 backdrop-blur">
            <div
                className="w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900/90 p-6 shadow-2xl shadow-emerald-500/10"
                style={{ animation: 'pos-pop 0.25s ease-out' }}
            >
                <div className="flex items-start justify-between">
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-400">
                            Clôture de caisse — Z
                        </div>
                        <h2 className="mt-1 text-2xl font-bold text-white">{session.code}</h2>
                    </div>
                    <button
                        onClick={onCancel}
                        className="rounded-xl border border-white/10 px-4 py-2 text-sm text-slate-300 transition hover:bg-white/[0.06]"
                    >
                        ✕ Continuer la vente
                    </button>
                </div>

                {error && (
                    <div className="mt-4 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">
                        {error}
                    </div>
                )}

                <div className="mt-5 grid gap-6 md:grid-cols-2">
                    {/* Récap Z */}
                    <div className="space-y-2 tabular-nums">
                        <div className="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                            <div className="flex justify-between text-sm text-slate-400">
                                <span>Tickets émis</span>
                                <span className="font-bold text-white">{rapport.tickets}</span>
                            </div>
                            <div className="mt-2 flex justify-between text-sm text-slate-400">
                                <span>Chiffre d'affaires TTC</span>
                                <span className="font-bold text-white">{dh(rapport.total_ttc)} DH</span>
                            </div>
                            <div className="mt-1 flex justify-between text-xs text-slate-500">
                                <span>dont TVA</span>
                                <span>{dh(rapport.total_tva)} DH</span>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                            <div className="mb-2 text-xs uppercase tracking-widest text-slate-500">
                                Encaissements par mode
                            </div>
                            {Object.keys(rapport.par_mode).length === 0 && (
                                <div className="text-sm text-slate-500">Aucun encaissement.</div>
                            )}
                            {Object.entries(rapport.par_mode).map(([mode, montant]) => (
                                <div key={mode} className="flex justify-between text-sm text-slate-300">
                                    <span>{MODE_LABELS[mode] ?? mode}</span>
                                    <span>{dh(montant)} DH</span>
                                </div>
                            ))}
                        </div>

                        <div className="rounded-2xl border border-emerald-400/20 bg-emerald-400/[0.06] p-4">
                            <div className="flex justify-between text-sm text-slate-300">
                                <span>Fond de caisse</span>
                                <span>{dh(rapport.fond_caisse)} DH</span>
                            </div>
                            <div className="mt-1 flex justify-between font-bold text-emerald-300">
                                <span>Espèces théoriques</span>
                                <span>{dh(rapport.especes_theorique)} DH</span>
                            </div>
                        </div>
                    </div>

                    {/* Comptage */}
                    <div>
                        <div className="flex h-16 items-center justify-between rounded-2xl border border-white/10 bg-black/40 px-5">
                            <span className="text-xs uppercase tracking-widest text-slate-500">Espèces comptées</span>
                            <span className="text-3xl font-bold tabular-nums text-white">
                                {compte === '' ? '—' : compte}
                            </span>
                        </div>

                        {ecart !== null && (
                            <div
                                className={`mt-3 flex items-center justify-between rounded-2xl border px-5 py-3 tabular-nums ${
                                    Math.abs(ecart) < 0.005
                                        ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300'
                                        : 'border-amber-400/30 bg-amber-400/10 text-amber-300'
                                }`}
                            >
                                <span className="text-xs font-semibold uppercase tracking-widest">Écart</span>
                                <span className="text-2xl font-bold">
                                    {ecart > 0 ? '+' : ''}
                                    {dh(ecart)} DH
                                </span>
                            </div>
                        )}

                        <div className="mt-3">
                            <Numpad value={compte} onChange={setCompte} />
                        </div>

                        <button
                            onClick={() => compteNum !== null && onFermer(compteNum)}
                            disabled={pending || compteNum === null}
                            className="mt-4 h-14 w-full rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-base font-bold uppercase tracking-widest text-slate-950 shadow-[0_0_40px_rgba(16,185,129,0.35)] transition active:scale-[0.98] disabled:opacity-30"
                        >
                            {pending ? 'Clôture…' : 'Clôturer la session'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Session clôturée : récapitulatif final                              */
/* ------------------------------------------------------------------ */

interface SessionFermeeProps {
    session: PosSession;
    rapport: PosRapport;
    onNouvelleSession: () => void;
}

export function SessionFermee({ session, rapport, onNouvelleSession }: SessionFermeeProps) {
    const ecart = session.ecart === null ? 0 : parseFloat(session.ecart);

    return (
        <div className="fixed inset-0 z-40 flex items-center justify-center bg-slate-950/95 p-4 backdrop-blur">
            <div
                className="w-full max-w-md rounded-3xl border border-white/10 bg-slate-900/90 p-8 text-center shadow-2xl"
                style={{ animation: 'pos-pop 0.3s ease-out' }}
            >
                <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-400/10 text-3xl">
                    🔒
                </div>
                <h2 className="mt-4 text-2xl font-bold text-white">Caisse clôturée</h2>
                <p className="mt-1 text-sm text-slate-400">{session.code}</p>

                <div className="mt-6 space-y-2 rounded-2xl border border-white/10 bg-black/30 p-4 text-left tabular-nums">
                    <div className="flex justify-between text-sm text-slate-400">
                        <span>Tickets</span>
                        <span className="text-white">{rapport.tickets}</span>
                    </div>
                    <div className="flex justify-between text-sm text-slate-400">
                        <span>CA TTC</span>
                        <span className="text-white">{dh(rapport.total_ttc)} DH</span>
                    </div>
                    <div className="flex justify-between text-sm text-slate-400">
                        <span>Espèces comptées</span>
                        <span className="text-white">{dh(session.montant_compte)} DH</span>
                    </div>
                    <div
                        className={`flex justify-between border-t border-white/10 pt-2 font-bold ${
                            Math.abs(ecart) < 0.005 ? 'text-emerald-400' : 'text-amber-300'
                        }`}
                    >
                        <span>Écart</span>
                        <span>
                            {ecart > 0 ? '+' : ''}
                            {dh(ecart)} DH
                        </span>
                    </div>
                </div>

                <div className="mt-6 flex flex-col gap-2">
                    <button
                        onClick={onNouvelleSession}
                        className="h-12 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 font-bold uppercase tracking-widest text-slate-950 transition active:scale-[0.98]"
                    >
                        Nouvelle session
                    </button>
                    <Link
                        to="/dashboard"
                        className="h-12 rounded-2xl border border-white/10 leading-[3rem] text-sm text-slate-300 transition hover:bg-white/[0.05]"
                    >
                        Retour au tableau de bord
                    </Link>
                </div>
            </div>
        </div>
    );
}
