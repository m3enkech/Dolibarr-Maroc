const mad = new Intl.NumberFormat('fr-MA', {
    style: 'currency',
    currency: 'MAD',
    minimumFractionDigits: 2,
});

export function formatMAD(value: string | number | null): string {
    if (value === null || value === '') {
        return '—';
    }
    const numeric = typeof value === 'string' ? parseFloat(value) : value;
    return Number.isNaN(numeric) ? '—' : mad.format(numeric);
}

export function formatTva(rate: string | number): string {
    const numeric = typeof rate === 'string' ? parseFloat(rate) : rate;
    return `${Number.isInteger(numeric) ? numeric : numeric.toFixed(2)} %`;
}
