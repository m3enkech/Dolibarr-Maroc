import { createContext, useContext, useState, type ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { api } from '@/lib/api';
import type { Tenant, User } from '@/types';

interface AuthState {
    user: User | null;
    tenant: Tenant | null;
    isAuthenticated: boolean;
    login: (email: string, password: string) => Promise<void>;
    register: (payload: RegisterPayload) => Promise<void>;
    logout: () => Promise<void>;
}

export interface RegisterPayload {
    company_name: string;
    name: string;
    email: string;
    password: string;
}

const AuthContext = createContext<AuthState | null>(null);

function readJson<T>(key: string): T | null {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : null;
}

export function AuthProvider({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<User | null>(() => readJson<User>('user'));
    const [tenant, setTenant] = useState<Tenant | null>(() => readJson<Tenant>('tenant'));

    const persist = (token: string, user: User, tenant: Tenant) => {
        localStorage.setItem('token', token);
        localStorage.setItem('user', JSON.stringify(user));
        localStorage.setItem('tenant', JSON.stringify(tenant));
        setUser(user);
        setTenant(tenant);
    };

    const login = async (email: string, password: string) => {
        const { data } = await api.post('/auth/login', { email, password });
        persist(data.token, data.user, data.tenant);
    };

    const register = async (payload: RegisterPayload) => {
        const { data } = await api.post('/auth/register', payload);
        persist(data.token, data.user, data.tenant);
    };

    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } finally {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('tenant');
            setUser(null);
            setTenant(null);
        }
    };

    return (
        <AuthContext.Provider
            value={{
                user,
                tenant,
                isAuthenticated: user !== null && localStorage.getItem('token') !== null,
                login,
                register,
                logout,
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
