import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { api } from '@/lib/api';
import type { PermissionLevel, Permissions, Tenant, User } from '@/types';

interface AuthState {
    user: User | null;
    tenant: Tenant | null;
    permissions: Permissions;
    isAuthenticated: boolean;
    login: (email: string, password: string) => Promise<void>;
    register: (payload: RegisterPayload) => Promise<void>;
    acceptInvitation: (token: string, name: string, password: string) => Promise<void>;
    updateUser: (user: User) => void;
    logout: () => Promise<void>;
    /** L'utilisateur a-t-il ce niveau d'accès sur un module ? */
    can: (domaine: string, action?: PermissionLevel) => boolean;
}

export interface RegisterPayload {
    company_name: string;
    name: string;
    email: string;
    password: string;
}

interface ProfilResponse {
    user: User;
    tenant: Tenant;
    permissions: Permissions;
}

interface SessionResponse extends ProfilResponse {
    token: string;
}

const AuthContext = createContext<AuthState | null>(null);

function readJson<T>(key: string): T | null {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : null;
}

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(() => readJson<User>('user'));
    const [tenant, setTenant] = useState<Tenant | null>(() => readJson<Tenant>('tenant'));
    const [permissions, setPermissions] = useState<Permissions>(
        () => readJson<Permissions>('permissions') ?? {},
    );

    const appliquerProfil = (data: ProfilResponse) => {
        localStorage.setItem('user', JSON.stringify(data.user));
        localStorage.setItem('tenant', JSON.stringify(data.tenant));
        localStorage.setItem('permissions', JSON.stringify(data.permissions ?? {}));
        setUser(data.user);
        setTenant(data.tenant);
        setPermissions(data.permissions ?? {});
    };

    const oublierSession = () => {
        localStorage.removeItem('token');
        localStorage.removeItem('user');
        localStorage.removeItem('tenant');
        localStorage.removeItem('permissions');
        setUser(null);
        setTenant(null);
        setPermissions({});
    };

    const persist = (data: SessionResponse) => {
        localStorage.setItem('token', data.token);
        appliquerProfil(data);
    };

    // Le profil gardé depuis la connexion vieillit : un rôle, des permissions,
    // le nom de l'entreprise ou le statut superadmin changés depuis ne se
    // voyaient qu'après une reconnexion. Constaté le 2026-09-26 — superadmin
    // accordé une minute après l'inscription, menu « Plateforme » introuvable.
    // On relit donc le profil à chaque chargement de l'application.
    useEffect(() => {
        const jeton = localStorage.getItem('token');
        if (!jeton) {
            return;
        }
        let abandonne = false;
        // Une réponse qui arrive après une déconnexion, ou après une connexion
        // sous un autre compte, ne doit pas rétablir l'ancienne session.
        const perimee = () => abandonne || localStorage.getItem('token') !== jeton;

        api.get<ProfilResponse>('/auth/me', { silencieux401: true })
            .then(({ data }) => {
                // Un jeton n'appartient qu'à un compte : un autre identifiant
                // trahit une réponse étrangère (un cache, un proxy). On ne
                // l'installe pas — le profil connu, lui, est au moins le bon.
                const connu = readJson<User>('user');
                if (!perimee() && (!connu || connu.id === data.user?.id)) {
                    appliquerProfil(data);
                }
            })
            .catch((error) => {
                // Jeton révoqué ou expiré : la session n'existe plus côté serveur.
                // Une page protégée renvoie alors vers la connexion (RequireAuth) ;
                // une page publique reste affichée. Tout autre échec — réseau,
                // entreprise suspendue — laisse le profil connu en place.
                if (!perimee() && error?.response?.status === 401) {
                    oublierSession();
                }
            });

        return () => {
            abandonne = true;
        };
    }, []);

    const login = async (email: string, password: string) => {
        const { data } = await api.post<SessionResponse>('/auth/login', { email, password });
        persist(data);
    };

    const register = async (payload: RegisterPayload) => {
        const { data } = await api.post<SessionResponse>('/auth/register', payload);
        persist(data);
    };

    const acceptInvitation = async (token: string, name: string, password: string) => {
        const { data } = await api.post<SessionResponse>(`/invitations/${token}/accepter`, {
            name,
            password,
        });
        persist(data);
    };

    const updateUser = (u: User) => {
        localStorage.setItem('user', JSON.stringify(u));
        setUser(u);
    };

    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            oublierSession();
        }
    };

    const can = (domaine: string, action: PermissionLevel = 'read'): boolean => {
        if (user?.is_superadmin) {
            return true;
        }
        const niveau = permissions[domaine] ?? 'none';
        if (action === 'write') {
            return niveau === 'write';
        }
        return niveau === 'read' || niveau === 'write';
    };

    return (
        <AuthContext.Provider
            value={{
                user,
                tenant,
                permissions,
                isAuthenticated: user !== null && localStorage.getItem('token') !== null,
                login,
                register,
                acceptInvitation,
                updateUser,
                logout,
                can,
            }}
        >
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth(): AuthState {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth doit être utilisé dans un AuthProvider.');
    }
    return context;
}

export function RequireAuth({ children }: { children: ReactNode }) {
    const { isAuthenticated } = useAuth();
    if (!isAuthenticated) {
        return <Navigate to="/login" replace />;
    }
    return <>{children}</>;
}
