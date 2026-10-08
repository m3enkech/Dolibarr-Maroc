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
        // Un instant (horodatage du serveur, en UTC) se lit à l'heure LOCALE
        // du poste : « 14:32 » au Maroc pour un commentaire écrit à 13:32 UTC.
        const dateHeure = new Intl.DateTimeFormat(arabe ? 'ar-MA' : 'fr', { dateStyle: 'medium', timeStyle: 'short' });
        // `numeric: 'auto'` : « hier », « la semaine dernière », « أمس » — et
        // pas « il y a 1 jour ».
        const relatif = new Intl.RelativeTimeFormat(arabe ? 'ar-MA' : 'fr', { numeric: 'auto' });

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
            /** « 8 oct. 2026, 14:32 » : l'instant exact, à l'heure du poste. */
            dateHeure: (iso: string): string => dateHeure.format(new Date(iso)),
            /**
             * « il y a 5 minutes », « hier », « il y a 3 mois » — par rapport à
             * `maintenant`, passé par l'appelant pour que tout un fil se date
             * depuis le même instant. `null` sous la minute : l'appelant dit
             * « à l'instant » dans ses mots, Intl n'ayant que « dans 0 minute ».
             * `null` aussi pour un instant à VENIR : l'horloge du serveur peut
             * avancer sur celle du poste, et un commentaire tout juste publié
             * s'affichait « dans 2 minutes ».
             */
            ilYA: (iso: string, maintenant: number): string | null => {
                const instant = new Date(iso).getTime();
                const ecart = Math.round((maintenant - instant) / 1000);
                if (ecart < 60) return null;
                if (ecart < 3600) return relatif.format(-Math.trunc(ecart / 60), 'minute');

                // Au-delà de l'heure, on compte en JOURS DU CALENDRIER, pas en
                // tranches de 24 h : écrit avant-hier à 23 h et lu à 1 h, un
                // commentaire de 26 h se disait « hier » à côté d'une date
                // absolue qui disait l'inverse.
                const jour = (ms: number) => {
                    const d = new Date(ms);

                    return Math.round(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()) / 86_400_000);
                };
                const jours = jour(maintenant) - jour(instant);
                if (jours <= 0) return relatif.format(-Math.trunc(ecart / 3600), 'hour');
                if (jours < 7) return relatif.format(-jours, 'day');
                if (jours < 30) return relatif.format(-Math.trunc(jours / 7), 'week');
                if (jours < 365) return relatif.format(-Math.trunc(jours / 30), 'month');

                return relatif.format(-Math.trunc(jours / 365), 'year');
            },
        };
    }, [langue]);
}
