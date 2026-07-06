/**
 * Briques partagées du POS : lignes de panier (arrondis identiques au backend),
 * pavé numérique tactile et formatage des montants.
 */

export interface CartLine {
    key: string;
    produit_id: number | null;
    designation: string;
    prix: number; // HT
    tva: number;
    quantite: number;
    remise: number;
    unit: string | null;
}

/** Mêmes arrondis que VenteService::syncLignes (round 2 par ligne). */
export function calcLigne(line: CartLine): { ht: number; tva: number; ttc: number } {
    const ht = Math.round(line.quantite * line.prix * (1 - line.remise / 100) * 100) / 100;
    const tva = Math.round(ht * (line.tva / 100) * 100) / 100;
    return { ht, tva, ttc: Math.round((ht + tva) * 100) / 100 };
}

export function calcTotaux(lines: CartLine[]): { ht: number; tva: number; ttc: number } {
    let ht = 0;
    let tva = 0;
    for (const line of lines) {
        const c = calcLigne(line);
        ht = Math.round((ht + c.ht) * 100) / 100;
        tva = Math.round((tva + c.tva) * 100) / 100;
    }
    return { ht, tva, ttc: Math.round((ht + tva) * 100) / 100 };
}

export function dh(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    return new Intl.NumberFormat('fr-MA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
        Number.isNaN(n) ? 0 : n,
    );
}

/* ------------------------------------------------------------------ */
/* Pavé numérique tactile                                              */
/* ------------------------------------------------------------------ */

interface NumpadProps {
    value: string;
    onChange: (value: string) => void;
    quickAmounts?: number[];
    onQuickAdd?: (amount: number) => void;
}

export function Numpad({ value, onChange, quickAmounts, onQuickAdd }: NumpadProps) {
    const press = (key: string) => {
        if (key === '⌫') {
            onChange(value.slice(0, -1));
            return;
        }
        if (key === 'C') {
            onChange('');
            return;
        }
        if (key === '.' && (value.includes('.') || value === '')) {
            if (value === '') onChange('0.');
            return;
        }
        // Limite à 2 décimales.
        const [, dec] = value.split('.');
        if (dec !== undefined && dec.length >= 2 && key !== '.') return;
        onChange(value + key);
    };

    const keyClass =
        'h-14 rounded-xl border border-white/10 bg-white/[0.05] text-xl font-semibold text-white ' +
        'transition active:scale-95 hover:bg-white/[0.1] hover:border-emerald-400/30 select-none';

    return (
        <div className="space-y-2">
            {quickAmounts && quickAmounts.length > 0 && (
                <div className="grid grid-cols-4 gap-2">
                    {quickAmounts.map((amount) => (
                        <button
                            key={amount}
                            type="button"
                            onClick={() => onQuickAdd?.(amount)}
                            className="h-10 rounded-lg border border-emerald-400/20 bg-emerald-400/10 text-sm font-semibold text-emerald-300 transition active:scale-95 hover:bg-emerald-400/20"
                        >
                            +{amount}
                        </button>
                    ))}
                </div>
            )}
            <div className="grid grid-cols-3 gap-2">
                {['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0', '⌫'].map((key) => (
                    <button key={key} type="button" onClick={() => press(key)} className={keyClass}>
                        {key}
                    </button>
                ))}
            </div>
            <button
                type="button"
                onClick={() => press('C')}
                className="h-10 w-full rounded-lg border border-white/10 bg-white/[0.03] text-xs font-semibold uppercase tracking-widest text-slate-400 transition hover:bg-white/[0.08]"
            >
                Effacer
            </button>
        </div>
    );
}
