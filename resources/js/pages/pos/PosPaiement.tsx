import { useMemo, useState } from 'react';
import { Numpad, dh } from '@/pages/pos/ui';

export interface PaiementSaisi {
    mode: string;
    montant: number;
}

const MODES: { key: string; label: string; icon: string }[] = [
    { key: 'especes', label: 'Espèces', icon: '💵' },
    { key: 'carte', label: 'Carte', icon: '💳' },
    { key: 'cheque', label: 'Chèque', icon: '🖊' },
];

interface PosPaiementProps {
    total: number;
    pending: boolean;
    error: string | null;
    onCancel: () => void;
    onSubmit: (paiements: PaiementSaisi[], montantDonne: number | null) => void;
}

/**
 * Overlay d'encaissement : le vendeur choisit un mode, saisit le montant reçu
 * au pavé numérique (espèces) et peut mixer plusieurs paiements. Le rendu de
 * monnaie s'affiche en direct dès que les espèces dépassent le reste dû.
 */
export default function PosPaiement({ total, pending, error, onCancel, onSubmit }: PosPaiementProps) {
    const [paiements, setPaiements] = useState<PaiementSaisi[]>([]);
    const [donne, setDonne] = useState(0); // espèces réellement remises
    const [input, setInput] = useState('');
    const [mode, setMode] = useState('especes');

    const paye = useMemo(
        () => Math.round(paiements.reduce((sum, paiement) => sum + paiement.montant, 0) * 100) / 100,
        [paiements],
    );
    const reste = Math.max(0, Math.round((total - paye) * 100) / 100);
    const especesPayees = paiements.filter((p) => p.mode === 'especes').reduce((s, p) => s + p.montant, 0);
    const rendu = Math.max(0, Math.round((donne - especesPayees) * 100) / 100);
    const complet = reste <= 0.009;

    const encaisser = () => {
        if (reste <= 0.009) return;
        const saisi = input === '' ? reste : Math.round(parseFloat(input) * 100) / 100;
        if (!saisi || saisi <= 0) return;

        // Un paiement ne dépasse jamais le reste dû ; l'excédent en espèces = rendu.
        const montant = Math.min(saisi, reste);
        setPaiements((list) => [...list, { mode, montant }]);
        if (mode === 'especes') {
            setDonne((d) => Math.round((d + saisi) * 100) / 100);
        }
        setInput('');
    };

    const retirer = (index: number) => {
        const paiement = paiements[index];
        setPaiements((list) => list.filter((_, i) => i !== index));
        if (paiement.mode === 'especes') {
            // On ne sait pas quelle part du « donné » correspondait : on retire le montant encaissé.
            setDonne((d) => Math.max(0, Math.round((d - paiement.montant) * 100) / 100));
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm">
            <div
                className="w-full max-w-3xl rounded-3xl border border-white/10 bg-slate-900/90 p-6 shadow-2xl shadow-emerald-500/10"
                style={{ animation: 'pos-pop 0.25s ease-out' }}
            >
                <div className="flex items-start justify-between">
                    <div>
                        <div className="text-xs font-semibold uppercase tracking-[0.3em] text-emerald-400">
                            Encaissement
                        </div>
                        <div className="mt-1 flex items-baseline gap-2">
                            <span className="text-5xl font-bold tabular-nums text-white">{dh(total)}</span>
                            <span className="text-lg text-slate-400">DH</span>
                        </div>
                    </div>
                    <button
                        onClick={onCancel}
                        className="rounded-xl border border-white/10 px-4 py-2 text-sm text-slate-300 transition hover:bg-white/[0.06]"
                    >
                        ✕ Annuler
                    </button>
                </div>

                {error && (
                    <div className="mt-4 rounded-xl border border-red-400/30 bg-red-500/10 px-4 py-2 text-sm text-red-300">
                        {error}
                    </div>
                )}

                <div className="mt-6 grid gap-6 md:grid-cols-2">
                    {/* Colonne gauche : modes + montant + numpad */}
                    <div>
                        <div className="grid grid-cols-3 gap-2">
                            {MODES.map((m) => (
                                <button
                                    key={m.key}
                                    onClick={() => setMode(m.key)}
                                    className={`h-16 rounded-2xl border text-sm font-semibold transition active:scale-95 ${
                                        mode === m.key
                                            ? 'border-emerald-400/60 bg-emerald-400/15 text-emerald-300 shadow-[0_0_24px_rgba(52,211,153,0.25)]'
                                            : 'border-white/10 bg-white/[0.04] text-slate-300 hover:bg-white/[0.08]'
                                    }`}
                                >
                                    <div className="text-xl">{m.icon}</div>
                                    {m.label}
                                </button>
                            ))}
                        </div>

                        <div className="mt-4 flex h-16 items-center justify-between rounded-2xl border border-white/10 bg-black/40 px-5">
                            <span className="text-xs uppercase tracking-widest text-slate-500">
                                {mode === 'especes' ? 'Montant reçu' : 'Montant'}
                            </span>
                            <span className="text-3xl font-bold tabular-nums text-white">
                                {input === '' ? dh(reste) : input}
                            </span>
                        </div>

                        <div className="mt-3">
                            <Numpad
                                value={input}
                                onChange={setInput}
                                quickAmounts={mode === 'especes' ? [20, 50, 100, 200] : undefined}
                                onQuickAdd={(amount) =>
                                    setInput((v) => {
                                        const current = v === '' ? 0 : parseFloat(v) || 0;
                                        return String(Math.round((current + amount) * 100) / 100);
                                    })
                                }
                            />
                        </div>

                        <button
                            onClick={encaisser}
                            disabled={complet}
                            className="mt-3 h-14 w-full rounded-2xl border border-emerald-400/40 bg-emerald-500/15 text-base font-bold uppercase tracking-widest text-emerald-300 transition active:scale-[0.98] hover:bg-emerald-500/25 disabled:opacity-30"
                        >
                            + Ajouter ce paiement
                        </button>
                    </div>

                    {/* Colonne droite : état de l'encaissement */}
                    <div className="flex flex-col">
                        <div className="flex-1 space-y-2">
                            {paiements.length === 0 && (
                                <div className="rounded-2xl border border-dashed border-white/10 p-6 text-center text-sm text-slate-500">
                                    Aucun paiement saisi.
                                    <br />
                                    Astuce : montant vide = <span className="text-slate-300">exact</span>.
                                </div>
                            )}
                            {paiements.map((paiement, index) => (
                                <div
                                    key={index}
                                    className="flex items-center justify-between rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-3"
                                    style={{ animation: 'pos-pop 0.2s ease-out' }}
                                >
                                    <span className="text-sm text-slate-300">
                                        {MODES.find((m) => m.key === paiement.mode)?.icon}{' '}
                                        {MODES.find((m) => m.key === paiement.mode)?.label ?? paiement.mode}
                                    </span>
                                    <div className="flex items-center gap-3">
                                        <span className="font-bold tabular-nums text-white">{dh(paiement.montant)}</span>
                                        <button
                                            onClick={() => retirer(index)}
                                            className="text-slate-500 transition hover:text-red-400"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="mt-4 space-y-2 rounded-2xl border border-white/10 bg-black/30 p-4 tabular-nums">
                            <div className="flex justify-between text-sm text-slate-400">
                                <span>Encaissé</span>
                                <span>{dh(paye)} DH</span>
                            </div>
                            {rendu > 0 ? (
                                <div className="flex items-baseline justify-between">
                                    <span className="text-xs font-semibold uppercase tracking-[0.3em] text-amber-300">
                                        Rendu monnaie
                                    </span>
                                    <span className="text-4xl font-bold text-amber-300">{dh(rendu)} DH</span>
                                </div>
                            ) : (
                                <div className="flex items-baseline justify-between">
                                    <span className="text-xs font-semibold uppercase tracking-[0.3em] text-slate-500">
                                        Reste dû
                                    </span>
                                    <span
                                        className={`text-4xl font-bold ${complet ? 'text-emerald-400' : 'text-white'}`}
                                    >
                                        {dh(reste)} DH
                                    </span>
                                </div>
                            )}
                        </div>

                        <button
                            onClick={() => onSubmit(paiements, donne > 0 ? donne : null)}
                            disabled={!complet || pending}
                            className={`mt-4 h-16 rounded-2xl text-lg font-bold uppercase tracking-widest transition active:scale-[0.98] disabled:opacity-30 ${
                                complet
                                    ? 'bg-gradient-to-r from-emerald-500 to-teal-500 text-slate-950 shadow-[0_0_40px_rgba(16,185,129,0.4)]'
                                    : 'border border-white/10 bg-white/[0.04] text-slate-500'
                            }`}
                        >
                            {pending ? 'Validation…' : '✓ Valider la vente'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
