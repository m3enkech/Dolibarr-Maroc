import { createContext, useContext, useState, type ReactNode } from 'react';
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

interface SessionResponse {
    token: string;
    user: User;
    tenant: Tenant;
    permissions: Permissions;
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

    const persist = (data: SessionResponse) => {
        localStorage.setItem('token', data.token);
        localStorage.setItem('user', JSON.stringify(data.user));
        localStorage.setItem('tenant', JSON.stringify(data.tenant));
        localStorage.setItem('permissions', JSON.stringify(data.permissions ?? {}));
        setUser(data.user);
        setTenant(data.tenant);
        setPermissions(data.permissions ?? {});
    };

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
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('tenant');
            localStorage.removeItem('permissions');
            setUser(null);
            setTenant(null);
            setPermissions({});
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
