import { useMemo } from 'react';
import { useLangue } from '@/lib/langue';

/**
 * Montants et mois écrits dans la langue de l'écran, par Intl — sans table de
 * mois recopiée à la main (l'ancienne MOIS_COURTS du tableau de bord restait
 * en français sur l'écran arabe).
 *
 * DEUX LOCALES EN FRANÇAIS, À DESSEIN. Les montants gardent `fr-MA`, celui de
 * formatMAD : « 22.189,20 MAD », le format que l'application affiche partout
 * depuis toujours. Les MOIS prennent `fr` : `fr-MA` les abrège à sa façon
 * (« jan. », « jui. » — juin ou juillet ?), quand `fr` écrit « janv. »,
 * « juin », « juil. ». En arabe, `ar-MA` donne les noms en usage au Maroc
 * (ماي، يوليوز، غشت، شتنبر) et des chiffres latins, ceux des pièces.
 *
 * Les clés de mois 'YYYY-MM' sont lues en UTC : un `new Date('2026-10')` local
 * glisse au mois précédent sur un poste réglé à l'ouest de Greenwich.
 */
export function useFormats() {
    const { langue } = useLangue();

    return useMemo(() => {
        const arabe = langue === 'ar';
        const montant = new Intl.NumberFormat(arabe ? 'ar-MA' : 'fr-MA', {
            style: 'currency',
            currency: 'MAD',
            minimumFractionDigits: 2,
        });
        const moisCourt = new Intl.DateTimeFormat(arabe ? 'ar-MA' : 'fr', { month: 'short', timeZone: 'UTC' });
        const moisLong = new Intl.DateTimeFormat(arabe ? 'ar-MA' : 'fr', {
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        });
        const date = (cle: string) => Date.UTC(Number(cle.slice(0, 4)), Number(cle.slice(5, 7)) - 1, 1);

        return {
            /** Montant en dirhams ; « — » pour une valeur absente ou illisible, comme formatMAD. */
            montant: (valeur: string | number | null | undefined): string => {
                if (valeur === null || valeur === undefined || valeur === '') return '—';
                const nombre = typeof valeur === 'string' ? parseFloat(valeur) : valeur;

                return Number.isNaN(nombre) ? '—' : montant.format(nombre);
            },
            /** « oct. » / « أكتوبر » pour la clé '2026-10'. */
            moisCourt: (cle: string): string => moisCourt.format(date(cle)),
            /** « octobre 2026 » / « أكتوبر 2026 » : l'axe abrège, l'info-bulle et le tableau précisent. */
            moisLong: (cle: string): string => moisLong.format(date(cle)),
        };
    }, [langue]);
}
