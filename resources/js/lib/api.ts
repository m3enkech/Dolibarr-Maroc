import axios from 'axios';
import { langueCourante } from '@/lib/langue';

declare module 'axios' {
    interface AxiosRequestConfig {
        /**
         * Un 401 sur cette requête ne renvoie PAS vers /login : l'appelant le
         * traite lui-même. Sert à la relecture du profil au chargement, qui
         * tourne aussi sur les pages publiques (accueil, inscription) — un
         * vieux jeton ne doit pas en expulser le visiteur.
         */
        silencieux401?: boolean;
    }
}

export const api = axios.create({
    baseURL: '/api/v1',
    headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    // Les messages du serveur (validation, refus) s'affichent tels quels : ils
    // doivent suivre la langue choisie, pas celle du navigateur.
    config.headers['Accept-Language'] = langueCourante();
    return config;
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401 && !error.config?.silencieux401) {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('tenant');
            localStorage.removeItem('permissions');
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    },
);
