import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import PosPaiement, { type PaiementSaisi } from '@/pages/pos/PosPaiement';
import { FermerCaisse, OuvrirCaisse, SessionFermee } from '@/pages/pos/PosSessionOverlays';
import PosTicket from '@/pages/pos/PosTicket';
import { calcLigne, calcTotaux, dh, type CartLine } from '@/pages/pos/ui';
import type { DocumentVente, Paginated, PosRapport, PosSession, Produit, StockNiveau } from '@/types';

interface SessionResponse {
    data: PosSession | null;
    rapport: PosRapport | null;
}

interface VenteResponse {
    data: DocumentVente;
    rendu: string | null;
    rapport: PosRapport;
}

const extraireErreur = (err: any): string => {
    const messages = err?.response?.data?.errors;
    return messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Action impossible.';
};

export default function PosPage() {
    const { user, tenant } = useAuth();
    const queryClient = useQueryClient();

    const [cart, setCart] = useState<CartLine[]>([]);
    const [search, setSearch] = useState('');
    const [now, setNow] = useState(new Date());
    const [payOpen, setPayOpen] = useState(false);
    const [closing, setClosing] = useState(false);
    const [success, setSuccess] = useState<{ doc: DocumentVente; rendu: string | null; donne: number | null } | null>(null);
    const [closed, setClosed] = useState<{ session: PosSession; rapport: PosRapport } | null>(null);
    const [venteError, setVenteError] = useState<string | null>(null);
    const [sessionError, setSessionError] = useState<string | null>(null);

    /* Horloge temps réel. */
    useEffect(() => {
        const timer = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(timer);
    }, []);

    /* Raccourcis clavier : F9 = encaisser, Échap = fermer l'encaissement. */
    useEffect(() => {
        const handler = (e: KeyboardEvent) => {
            if (e.key === 'F9' && cart.length > 0) {
                e.preventDefault();
                setPayOpen(true);
            }
            if (e.key === 'Escape') {
                setPayOpen(false);
            }
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [cart.length]);

    /* ------------------------------------------------------------------ */
    /* Données                                                             */
    /* ------------------------------------------------------------------ */

    const { data: sessionData } = useQuery({
        queryKey: ['pos-session'],
        queryFn: async () => {
            const { data } = await api.get<SessionResponse>('/pos/session');
            return data;
        },
    });
    const session = sessionData?.data ?? null;
    const rapport = sessionData?.rapport ?? null;

    const { data: produits } = useQuery({
        queryKey: ['pos-produits'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<Produit>>('/produits', { params: { per_page: 500 } });
            return data.data.filter((p) => p.is_active);
        },
    });

    const { data: niveaux } = useQuery({
        queryKey: ['pos-niveaux'],
        queryFn: async () => {
            const { data } = await api.get<Paginated<StockNiveau>>('/stock/niveaux', {
                params: { per_page: 500 },
            });
            return new Map(data.data.map((n) => [n.produit_id, parseFloat(n.quantite)]));
        },
    });

    /* ------------------------------------------------------------------ */
    /* Mutations                                                           */
    /* ------------------------------------------------------------------ */

    const invalidatePos = () => {
        queryClient.invalidateQueries({ queryKey: ['pos-session'] });
        queryClient.invalidateQueries({ queryKey: ['pos-niveaux'] });
    };

    const ouvrir = useMutation({
        mutationFn: (fondCaisse: number) => api.post('/pos/session/ouvrir', { fond_caisse: fondCaisse }),
        onSuccess: () => {
            setSessionError(null);
            setClosed(null);
            invalidatePos();
        },
        onError: (err: any) => setSessionError(extraireErreur(err)),
    });

    const fermer = useMutation({
        mutationFn: (montantCompte: number) =>
            api.post<{ data: PosSession; rapport: PosRapport }>('/pos/session/fermer', {
                montant_compte: montantCompte,
            }),
        onSuccess: ({ data }) => {
            setSessionError(null);
            setClosing(false);
            setClosed({ session: data.data, rapport: data.rapport });
            setCart([]);
            invalidatePos();
        },
        onError: (err: any) => setSessionError(extraireErreur(err)),
    });

    const vendre = useMutation({
        mutationFn: async (payload: { paiements: PaiementSaisi[]; montantDonne: number | null }) => {
            const { data } = await api.post<VenteResponse>('/pos/ventes', {
                lignes: cart.map((line) => ({
                    produit_id: line.produit_id,
                    designation: line.designation,
                    quantite: line.quantite,
                    prix_unitaire: line.prix,
                    remise_percent: line.remise,
                    tva_rate: line.tva,
                })),
                paiements: payload.paiements,
                montant_donne: payload.montantDonne ?? undefined,
            });
            return { ...data, donne: payload.montantDonne };
        },
        onSuccess: (data) => {
            setVenteError(null);
            setPayOpen(false);
            setSuccess({ doc: data.data, rendu: data.rendu, donne: data.donne });
            setCart([]);
            invalidatePos();
        },
        onError: (err: any) => setVenteError(extraireErreur(err)),
    });

    /* ------------------------------------------------------------------ */
    /* Panier                                                              */
    /* ------------------------------------------------------------------ */

    const addProduit = (produit: Produit) => {
        setCart((lines) => {
            const existing = lines.find((line) => line.produit_id === produit.id);
            if (existing) {
                return lines.map((line) =>
                    line.produit_id === produit.id ? { ...line, quantite: line.quantite + 1 } : line,
                );
            }
            return [
                ...lines,
                {
                    key: `${produit.id}-${Date.now()}`,
                    produit_id: produit.id,
                    designation: produit.name,
                    prix: parseFloat(produit.sell_price),
                    tva: parseFloat(produit.tva_rate),
                    quantite: 1,
                    remise: 0,
                    unit: produit.unit,
                },
            ];
        });
    };

    const changerQuantite = (key: string, delta: number) => {
        setCart((lines) =>
            lines
                .map((line) => (line.key === key ? { ...line, quantite: Math.round((line.quantite + delta) * 1000) / 1000 } : line))
                .filter((line) => line.quantite > 0),
        );
    };

    const filtres = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return produits ?? [];
        return (produits ?? []).filter(
            (p) =>
                p.name.toLowerCase().includes(q) ||
                p.code.toLowerCase().includes(q) ||
                (p.barcode ?? '').toLowerCase() === q,
        );
    }, [produits, search]);

    /** Douchette : Entrée = code-barres exact, sinon résultat unique. */
    const onSearchEnter = () => {
        const q = search.trim().toLowerCase();
        if (!q) return;
        const exact = (produits ?? []).find((p) => (p.barcode ?? '').toLowerCase() === q);
        const cible = exact ?? (filtres.length === 1 ? filtres[0] : null);
        if (cible) {
            addProduit(cible);
            setSearch('');
        }
    };

    const totaux = calcTotaux(cart);

    /* ------------------------------------------------------------------ */

    return (
        <div className="relative min-h-screen overflow-hidden bg-slate-950 text-white">
            {/* Styles : animations + impression ticket 80 mm */}
            <style>{`
                @keyframes pos-pop { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: none; } }
                @keyframes pos-fade { from { opacity: 0; } to { opacity: 1; } }
                @keyframes pos-draw { to { stroke-dashoffset: 0; } }
                @media print {
                    body * { visibility: hidden; }
                    #pos-ticket, #pos-ticket * { visibility: visible; }
                    #pos-ticket { position: fixed; left: 0; top: 0; width: 72mm; }
                    @page { size: 80mm auto; margin: 0; }
                }
            `}</style>

            {/* Fond futuriste : halos + grille */}
            <div
                className="pointer-events-none absolute inset-0"
                style={{
                    background:
                        'radial-gradient(900px 500px at 85% -10%, rgba(16,185,129,0.12), transparent 60%), radial-gradient(700px 400px at -10% 110%, rgba(20,184,166,0.10), transparent 60%)',
                }}
            />
            <div
                className="pointer-events-none absolute inset-0 opacity-40"
                style={{
                    backgroundImage:
                        'linear-gradient(rgba(148,163,184,0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(148,163,184,0.05) 1px, transparent 1px)',
                    backgroundSize: '42px 42px',
                }}
            />

            {/* ---------------------------------------------------------- */}
            {/* Barre supérieure                                            */}
            {/* ---------------------------------------------------------- */}
            <header className="relative z-10 flex items-center justify-between gap-4 border-b border-white/[0.06] bg-white/[0.02] px-5 py-3 backdrop-blur">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-400 to-teal-500 text-lg font-black text-slate-950 shadow-[0_0_24px_rgba(16,185,129,0.45)]">
                        ⌁
                    </span>
                    <div>
                        <div className="text-sm font-bold uppercase tracking-[0.35em] text-white">Caisse</div>
                        <div className="text-xs text-slate-400">{tenant?.name}</div>
                    </div>
                </div>

                <div className="hidden items-center gap-3 md:flex">
                    {session && (
                        <div className="flex items-center gap-2 rounded-full border border-emerald-400/25 bg-emerald-400/10 px-4 py-1.5 text-xs">
                            <span className="h-2 w-2 animate-pulse rounded-full bg-emerald-400 shadow-[0_0_10px_rgba(52,211,153,0.9)]" />
                            <span className="font-semibold text-emerald-300">{session.code}</span>
                            <span className="text-slate-400">· {user?.name}</span>
                            {rapport && (
                                <span className="text-slate-400">
                                    · {rapport.tickets} ticket{rapport.tickets > 1 ? 's' : ''} ·{' '}
                                    <span className="font-semibold text-white">{dh(rapport.total_ttc)} DH</span>
                                </span>
                            )}
                        </div>
                    )}
                    <div className="text-right">
                        <div className="font-mono text-xl font-bold tabular-nums tracking-widest text-emerald-300">
                            {now.toLocaleTimeString('fr-FR')}
                        </div>
                        <div className="text-[10px] uppercase tracking-widest text-slate-500">
                            {now.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}
                        </div>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {session && (
                        <button
                            onClick={() => setClosing(true)}
                            className="rounded-xl border border-white/10 px-4 py-2 text-sm text-slate-300 transition hover:border-amber-400/40 hover:text-amber-300"
                        >
                            Clôturer (Z)
                        </button>
                    )}
                    <Link
                        to="/dashboard"
                        className="rounded-xl border border-white/10 px-4 py-2 text-sm text-slate-300 transition hover:bg-white/[0.06]"
                    >
                        ← Quitter
                    </Link>
                </div>
            </header>

            {/* ---------------------------------------------------------- */}
            {/* Corps : produits + panier                                   */}
            {/* ---------------------------------------------------------- */}
            <main className="relative z-10 flex h-[calc(100vh-65px)] gap-4 p-4">
                {/* Produits */}
                <section className="flex min-w-0 flex-1 flex-col">
                    <input
                        autoFocus
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && onSearchEnter()}
                        placeholder="🔍  Rechercher un produit ou scanner un code-barres…"
                        className="h-14 w-full rounded-2xl border border-white/10 bg-white/[0.04] px-5 text-base text-white placeholder-slate-500 outline-none backdrop-blur transition focus:border-emerald-400/50 focus:shadow-[0_0_30px_rgba(16,185,129,0.15)]"
                    />

                    <div className="mt-4 grid flex-1 auto-rows-min grid-cols-2 gap-3 overflow-y-auto pb-4 sm:grid-cols-3 xl:grid-cols-4">
                        {(filtres ?? []).map((produit) => {
                            const stock = produit.type === 'product' ? (niveaux?.get(produit.id) ?? 0) : null;
                            return (
                                <button
                                    key={produit.id}
                                    onClick={() => addProduit(produit)}
                                    className="group flex h-36 flex-col justify-between rounded-2xl border border-white/[0.08] bg-white/[0.04] p-4 text-left backdrop-blur transition active:scale-95 hover:border-emerald-400/40 hover:bg-white/[0.07] hover:shadow-[0_0_30px_rgba(16,185,129,0.12)]"
                                    style={{ animation: 'pos-fade 0.3s ease-out' }}
                                >
                                    <div>
                                        <div className="line-clamp-2 text-sm font-semibold text-white">
                                            {produit.name}
                                        </div>
                                        <div className="mt-0.5 font-mono text-[10px] text-slate-500">{produit.code}</div>
                                    </div>
                                    <div className="flex items-end justify-between">
                                        <div>
                                            <span className="text-lg font-bold tabular-nums text-emerald-300">
                                                {dh(produit.sell_price_ttc)}
                                            </span>
                                            <span className="ml-1 text-[10px] text-slate-500">DH TTC</span>
                                        </div>
                                        {stock !== null ? (
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                                    stock > 0
                                                        ? 'bg-emerald-400/10 text-emerald-300'
                                                        : 'bg-red-400/10 text-red-300'
                                                }`}
                                            >
                                                {stock}
                                            </span>
                                        ) : produit.type === 'kit' ? (
                                            <span className="rounded-full bg-indigo-400/10 px-2 py-0.5 text-[10px] font-semibold text-indigo-300">
                                                kit
                                            </span>
                                        ) : (
                                            <span className="rounded-full bg-sky-400/10 px-2 py-0.5 text-[10px] font-semibold text-sky-300">
                                                service
                                            </span>
                                        )}
                                    </div>
                                </button>
                            );
                        })}
                        {(filtres ?? []).length === 0 && (
                            <div className="col-span-full py-16 text-center text-slate-500">
                                Aucun produit. Ajoutez vos produits dans le Catalogue.
                            </div>
                        )}
                    </div>
                </section>

                {/* Panier */}
                <aside className="flex w-[360px] shrink-0 flex-col rounded-3xl border border-white/[0.08] bg-white/[0.03] backdrop-blur-xl">
                    <div className="flex items-center justify-between border-b border-white/[0.06] px-5 py-4">
                        <span className="text-xs font-bold uppercase tracking-[0.3em] text-slate-400">Ticket</span>
                        {cart.length > 0 && (
                            <button
                                onClick={() => setCart([])}
                                className="text-xs text-slate-500 transition hover:text-red-400"
                            >
                                Vider
                            </button>
                        )}
                    </div>

                    <div className="flex-1 space-y-2 overflow-y-auto p-4">
                        {cart.length === 0 && (
                            <div className="flex h-full flex-col items-center justify-center text-center text-slate-600">
                                <div className="text-4xl opacity-40">🛒</div>
                                <p className="mt-3 text-sm">
                                    Touchez un produit
                                    <br />
                                    pour l'ajouter au ticket
                                </p>
                            </div>
                        )}
                        {cart.map((line) => {
                            const calc = calcLigne(line);
                            return (
                                <div
                                    key={line.key}
                                    className="rounded-2xl border border-white/[0.07] bg-white/[0.04] p-3"
                                    style={{ animation: 'pos-pop 0.2s ease-out' }}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="text-sm font-medium text-white">{line.designation}</span>
                                        <button
                                            onClick={() => changerQuantite(line.key, -line.quantite)}
                                            className="text-slate-600 transition hover:text-red-400"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                    <div className="mt-2 flex items-center justify-between">
                                        <div className="flex items-center gap-1">
                                            <button
                                                onClick={() => changerQuantite(line.key, -1)}
                                                className="h-8 w-8 rounded-lg border border-white/10 bg-white/[0.05] font-bold text-slate-300 transition active:scale-90 hover:bg-white/[0.1]"
                                            >
                                                −
                                            </button>
                                            <span className="w-10 text-center font-bold tabular-nums text-white">
                                                {line.quantite}
                                            </span>
                                            <button
                                                onClick={() => changerQuantite(line.key, 1)}
                                                className="h-8 w-8 rounded-lg border border-emerald-400/30 bg-emerald-400/10 font-bold text-emerald-300 transition active:scale-90 hover:bg-emerald-400/20"
                                            >
                                                +
                                            </button>
                                        </div>
                                        <span className="font-bold tabular-nums text-emerald-300">
                                            {dh(calc.ttc)} DH
                                        </span>
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <div className="space-y-1 border-t border-white/[0.06] p-5 tabular-nums">
                        <div className="flex justify-between text-xs text-slate-500">
                            <span>Total HT</span>
                            <span>{dh(totaux.ht)} DH</span>
                        </div>
                        <div className="flex justify-between text-xs text-slate-500">
                            <span>TVA</span>
                            <span>{dh(totaux.tva)} DH</span>
                        </div>
                        <div className="flex items-baseline justify-between pt-1">
                            <span className="text-xs font-bold uppercase tracking-[0.3em] text-slate-400">Total</span>
                            <span className="text-4xl font-black text-white">{dh(totaux.ttc)}</span>
                        </div>
                        <button
                            onClick={() => setPayOpen(true)}
                            disabled={cart.length === 0 || !session}
                            className="mt-3 h-16 w-full rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 text-lg font-black uppercase tracking-[0.2em] text-slate-950 shadow-[0_0_40px_rgba(16,185,129,0.35)] transition active:scale-[0.98] hover:shadow-[0_0_60px_rgba(16,185,129,0.5)] disabled:opacity-25 disabled:shadow-none"
                        >
                            Encaisser <span className="ml-1 text-xs font-semibold opacity-60">F9</span>
                        </button>
                    </div>
                </aside>
            </main>

            {/* ---------------------------------------------------------- */}
            {/* Overlays                                                    */}
            {/* ---------------------------------------------------------- */}

            {sessionData && !session && !closed && (
                <OuvrirCaisse
                    pending={ouvrir.isPending}
                    error={sessionError}
                    onOuvrir={(fond) => ouvrir.mutate(fond)}
                />
            )}

            {closing && session && rapport && (
                <FermerCaisse
                    session={session}
                    rapport={rapport}
                    pending={fermer.isPending}
                    error={sessionError}
                    onFermer={(compte) => fermer.mutate(compte)}
                    onCancel={() => setClosing(false)}
                />
            )}

            {closed && (
                <SessionFermee
                    session={closed.session}
                    rapport={closed.rapport}
                    onNouvelleSession={() => setClosed(null)}
                />
            )}

            {payOpen && (
                <PosPaiement
                    total={totaux.ttc}
                    pending={vendre.isPending}
                    error={venteError}
                    onCancel={() => {
                        setPayOpen(false);
                        setVenteError(null);
                    }}
                    onSubmit={(paiements, montantDonne) => vendre.mutate({ paiements, montantDonne })}
                />
            )}

            {success && (
                <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/90 p-4 backdrop-blur">
                    <div
                        className="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900/90 p-6 text-center shadow-2xl shadow-emerald-500/20"
                        style={{ animation: 'pos-pop 0.3s ease-out' }}
                    >
                        <svg viewBox="0 0 52 52" className="mx-auto h-20 w-20">
                            <circle
                                cx="26"
                                cy="26"
                                r="24"
                                fill="none"
                                stroke="rgba(52,211,153,0.25)"
                                strokeWidth="2"
                            />
                            <path
                                d="M14 27l8 8 16-17"
                                fill="none"
                                stroke="#34d399"
                                strokeWidth="4"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                style={{
                                    strokeDasharray: 48,
                                    strokeDashoffset: 48,
                                    animation: 'pos-draw 0.5s ease-out 0.15s forwards',
                                    filter: 'drop-shadow(0 0 8px rgba(52,211,153,0.8))',
                                }}
                            />
                        </svg>
                        <h2 className="mt-3 text-2xl font-bold text-white">Vente encaissée</h2>
                        <p className="mt-1 font-mono text-sm text-slate-400">{success.doc.code}</p>

                        {success.rendu !== null && parseFloat(success.rendu) > 0 && (
                            <div className="mt-4 rounded-2xl border border-amber-400/30 bg-amber-400/10 px-6 py-4">
                                <div className="text-xs font-semibold uppercase tracking-[0.3em] text-amber-300">
                                    Rendu monnaie
                                </div>
                                <div className="text-5xl font-black tabular-nums text-amber-300">
                                    {dh(success.rendu)} <span className="text-2xl">DH</span>
                                </div>
                            </div>
                        )}

                        <div className="mt-5 max-h-[38vh] overflow-y-auto rounded-2xl bg-white/95 py-2 shadow-inner">
                            <PosTicket
                                doc={success.doc}
                                tenant={tenant}
                                vendeur={user?.name ?? null}
                                rendu={success.rendu}
                                donne={success.donne}
                            />
                        </div>

                        <div className="mt-5 grid grid-cols-2 gap-3">
                            <button
                                onClick={() => window.print()}
                                className="h-14 rounded-2xl border border-white/10 bg-white/[0.05] font-semibold text-white transition active:scale-[0.98] hover:bg-white/[0.1]"
                            >
                                🖨 Imprimer
                            </button>
                            <button
                                onClick={() => setSuccess(null)}
                                className="h-14 rounded-2xl bg-gradient-to-r from-emerald-500 to-teal-500 font-bold uppercase tracking-widest text-slate-950 shadow-[0_0_30px_rgba(16,185,129,0.35)] transition active:scale-[0.98]"
                            >
                                Nouvelle vente
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
