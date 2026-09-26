/**
 * Nom de l'application, tel que le serveur le déclare (`APP_NAME`).
 *
 * Le même code sert plusieurs déploiements — Cloud Run (« Dolibarr Maroc »), le
 * VPS (« MediaDesk CRM ») — : le nom ne peut donc être ni écrit en dur, ni
 * figé à la construction des assets, qui sont les mêmes partout.
 * `app.blade.php` le pose dans `<meta name="application-name">`, rendu par
 * Laravel à chaque page : changer `APP_NAME` puis RECRÉER le conteneur suffit
 * (`docker compose … up -d`). Un simple `restart` ne relit pas `.env.vps`.
 */
const declare = document
    .querySelector('meta[name="application-name"]')
    ?.getAttribute('content')
    ?.trim();

export const NOM_APPLICATION = declare || 'Dolibarr Maroc';

/** Adresse des liens « Contact » (APP_CONTACT_EMAIL) : chaque déploiement a la sienne. */
export const EMAIL_CONTACT =
    document.querySelector('meta[name="contact-email"]')?.getAttribute('content')?.trim() ||
    'contact@dolibarr-maroc.ma';

const mots = NOM_APPLICATION.split(/\s+/).filter(Boolean);

/** Pastille du logo : initiales des deux premiers mots (« DM », « MC »). */
export const INITIALES_APPLICATION = mots
    .slice(0, 2)
    .map((mot) => mot.charAt(0).toUpperCase())
    .join('');

/**
 * Le logo met le dernier mot en couleur (« Dolibarr **Maroc** »,
 * « MediaDesk **CRM** »). Un nom d'un seul mot reste d'une seule couleur.
 */
export const NOM_EN_DEUX_PARTIES: [string, string] =
    mots.length > 1 ? [mots.slice(0, -1).join(' '), mots[mots.length - 1]] : [NOM_APPLICATION, ''];
