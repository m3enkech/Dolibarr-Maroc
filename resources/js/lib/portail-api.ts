import axios from 'axios';
import { langueCourante } from '@/lib/langue';

/**
 * Client HTTP du portail acheteur — volontairement séparé de celui de l'ERP.
 *
 * Deux mondes cohabitent dans le même navigateur : un grossiste peut être
 * connecté à son ERP dans un onglet pendant qu'un acheteur teste le portail
 * dans un autre. Les jetons sont donc rangés sous des clés distinctes, et un
 * rejet d'authentification renvoie vers la connexion du PORTAIL, jamais vers
 * celle de l'ERP.
 */

export const CLE_JETON = 'portail_token';
export const CLE_ACHETEUR = 'portail_acheteur';

export const portailApi = axios.create({
    baseURL: '/api/portail/v1',
    headers: { Accept: 'application/json' },
});

portailApi.interceptors.request.use((config) => {
    const token = localStorage.getItem(CLE_JETON);
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    config.headers['Accept-Language'] = langueCourante();
    return config;
});

portailApi.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem(CLE_JETON);
            localStorage.removeItem(CLE_ACHETEUR);
            if (!window.location.pathname.startsWith('/portail/connexion')) {
                window.location.href = '/portail/connexion';
            }
        }
        return Promise.reject(error);
    },
);

/** Message d'erreur lisible, quelle que soit la forme de la réponse. */
export function messageErreur(err: unknown, defaut: string): string {
    const e = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } };
    const erreurs = e?.response?.data?.errors;
    if (erreurs) return Object.values(erreurs).flat().join(' ');
    return e?.response?.data?.message ?? defaut;
}
