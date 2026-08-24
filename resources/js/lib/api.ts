import axios from 'axios';
import { langueCourante } from '@/lib/langue';

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
        if (error.response?.status === 401) {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('tenant');
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }
        return Promise.reject(error);
    },
);
