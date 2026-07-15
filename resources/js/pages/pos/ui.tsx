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

/**
 * Remise effective d'une ligne (%) = remise de ligne combinée à la remise
 * globale du ticket : rEff = 1 − (1 − rLigne)(1 − rGlobal). C'est cette valeur
 * qui est envoyée au backend comme `remise_percent`, de sorte que les totaux et
 * la comptabilité restent exacts sans champ de remise au niveau document.
 */
export function remiseEffective(line: CartLine, remiseTicket = 0): number {
    const r = 1 - (1 - line.remise / 100) * (1 - remiseTicket / 100);
    return Math.round(r * 100 * 100) / 100; // pourcentage, 2 décimales
}

/** Mêmes arrondis que VenteService::syncLignes (round 2 par ligne). */
export function calcLigne(line: CartLine, remiseTicket = 0): { ht: number; brutHt: number; tva: number; ttc: number } {
    const brutHt = Math.round(line.quantite * line.prix * 100) / 100;
    const ht = Math.round(line.quantite * line.prix * (1 - remiseEffective(line, remiseTicket) / 100) * 100) / 100;
    const tva = Math.round(ht * (line.tva / 100) * 100) / 100;
    return { ht, brutHt, tva, ttc: Math.round((ht + tva) * 100) / 100 };
}

export function calcTotaux(
    lines: CartLine[],
    remiseTicket = 0,
): { ht: number; tva: number; ttc: number; remise: number } {
    let ht = 0;
    let tva = 0;
    let brut = 0;
    for (const line of lines) {
        const c = calcLigne(line, remiseTicket);
        ht = Math.round((ht + c.ht) * 100) / 100;
        tva = Math.round((tva + c.tva) * 100) / 100;
        brut = Math.round((brut + c.brutHt) * 100) / 100;
    }
    return { ht, tva, ttc: Math.round((ht + tva) * 100) / 100, remise: Math.round((brut - ht) * 100) / 100 };
}

export function dh(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    return new Intl.NumberFormat('fr-MA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
        Number.isNaN(n) ? 0 : n,
    );
}

/* ------------------------------------------------------------------ */
/* Sélecteur de remise (chips % rapides)                               */
/* ------------------------------------------------------------------ */

const REMISES_RAPIDES = [5, 10, 15, 20, 25, 50];

interface RemiseChipsProps {
    value: number;
    onChange: (percent: number) => void;
}

/** Rangée de pourcentages rapides + remise nulle, pour une ligne ou le ticket. */
export function RemiseChips({ value, onChange }: RemiseChipsProps) {
    const chip = (active: boolean) =>
        `h-8 min-w-9 rounded-lg border px-2 text-xs font-semibold tabular-nums transition active:scale-95 ${
            active
                ? 'border-amber-400/50 bg-amber-400/20 text-amber-200'
                : 'border-white/10 bg-white/[0.05] text-slate-300 hover:bg-white/[0.1]'
        }`;

    return (
        <div className="flex flex-wrap gap-1.5">
            <button type="button" onClick={() => onChange(0)} className={chip(value === 0)}>
                0
            </button>
            {REMISES_RAPIDES.map((pct) => (
                <button key={pct} type="button" onClick={() => onChange(pct)} className={chip(value === pct)}>
                    {pct}%
                </button>
            ))}
        </div>
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
