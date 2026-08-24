import { createContext, useContext, useState, type ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { CLE_ACHETEUR, CLE_JETON, portailApi } from '@/lib/portail-api';

export interface Acheteur {
    id: number;
    name: string;
    email: string;
    phone: string | null;
}

interface PortailAuthState {
    acheteur: Acheteur | null;
    estConnecte: boolean;
    connexion: (email: string, password: string) => Promise<void>;
    inscription: (data: { name: string; email: string; password: string; phone?: string }) => Promise<void>;
    deconnexion: () => Promise<void>;
}

const PortailAuthContext = createContext<PortailAuthState | null>(null);

function lireAcheteur(): Acheteur | null {
    const brut = localStorage.getItem(CLE_ACHETEUR);
    try {
        return brut ? (JSON.parse(brut) as Acheteur) : null;
    } catch {
        return null;
    }
}

export function PortailAuthProvider({ children }: { children: ReactNode }) {
    const [acheteur, setAcheteur] = useState<Acheteur | null>(lireAcheteur);

    const enregistrerSession = (data: { token: string; acheteur: Acheteur }) => {
        localStorage.setItem(CLE_JETON, data.token);
        localStorage.setItem(CLE_ACHETEUR, JSON.stringify(data.acheteur));
        setAcheteur(data.acheteur);
    };

    const connexion = async (email: string, password: string) => {
        const { data } = await portailApi.post('/auth/connexion', { email, password });
        enregistrerSession(data);
    };

    const inscription = async (payload: { name: string; email: string; password: string; phone?: string }) => {
        const { data } = await portailApi.post('/auth/inscription', payload);
        enregistrerSession(data);
    };

    const deconnexion = async () => {
        try {
            await portailApi.post('/auth/deconnexion');
        } finally {
            localStorage.removeItem(CLE_JETON);
            localStorage.removeItem(CLE_ACHETEUR);
            setAcheteur(null);
        }
    };

    return (
        <PortailAuthContext.Provider
            value={{
                acheteur,
                estConnecte: acheteur !== null && localStorage.getItem(CLE_JETON) !== null,
                connexion,
                inscription,
                deconnexion,
            }}
        >
            {children}
        </PortailAuthContext.Provider>
    );
}

export function usePortailAuth(): PortailAuthState {
    const ctx = useContext(PortailAuthContext);
    if (ctx === null) {
        throw new Error('usePortailAuth doit être utilisé dans PortailAuthProvider.');
    }
    return ctx;
}

export function RequirePortailAuth({ children }: { children: ReactNode }) {
    const { estConnecte } = usePortailAuth();
    const location = useLocation();

    if (!estConnecte) {
        return <Navigate to="/portail/connexion" state={{ from: location }} replace />;
    }

    return <>{children}</>;
}
