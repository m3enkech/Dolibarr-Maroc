import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { useFeatures } from '@/lib/features';

export default function Layout() {
    const { user, tenant, logout } = useAuth();
    const { features } = useFeatures();
    const navigate = useNavigate();

    const modules = [
        { to: '/dashboard', label: 'Tableau de bord', icon: '▦', enabled: true },
        { to: '/tiers', label: 'Tiers', icon: '👥', enabled: true },
        { to: '/catalogue', label: 'Catalogue', icon: '📦', enabled: true },
        { to: '/ventes', label: 'Ventes', icon: '🧾', enabled: true },
        { to: '/caisse', label: 'Caisse (POS)', icon: '💳', enabled: true },
        { to: '/achats', label: 'Achats', icon: '🛒', enabled: true },
        { to: '/stock', label: 'Stock', icon: '🏬', enabled: true },
        { to: '/compta', label: 'Comptabilité', icon: '⚖', enabled: true },
        // Modules activables dans les paramètres.
        ...(features.relances ? [{ to: '/relances', label: 'Relances', icon: '📨', enabled: true }] : []),
        { to: '/rh', label: 'RH & Projets', icon: '🗂', enabled: false },
        { to: '/parametres', label: 'Paramètres', icon: '⚙', enabled: true },
    ];

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    return (
        <div className="flex min-h-screen bg-slate-100">
            <aside className="flex w-64 flex-col bg-slate-900 text-slate-200">
                <div className="border-b border-slate-800 px-5 py-4">
                    <div className="text-lg font-semibold text-white">Dolibarr Maroc</div>
                    <div className="mt-0.5 truncate text-xs text-slate-400">{tenant?.name}</div>
                </div>
                <nav className="flex-1 space-y-1 px-3 py-4">
                    {modules.map((m) =>
                        m.enabled ? (
                            <NavLink
                                key={m.to}
                                to={m.to}
                                end={m.to === '/dashboard'}
                                className={({ isActive }) =>
                                    `flex items-center gap-3 rounded-md px-3 py-2 text-sm transition ${
                                        isActive
                                            ? 'bg-emerald-600 text-white'
                                            : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                                    }`
                                }
                            >
                                <span aria-hidden>{m.icon}</span>
                                {m.label}
                            </NavLink>
                        ) : (
                            <div
                                key={m.to}
                                className="flex items-center gap-3 rounded-md px-3 py-2 text-sm text-slate-500"
                                title="Module à venir"
                            >
                                <span aria-hidden>{m.icon}</span>
                                {m.label}
                                <span className="ml-auto rounded bg-slate-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wide">
                                    bientôt
                                </span>
                            </div>
                        ),
                    )}
                </nav>
                <div className="border-t border-slate-800 px-5 py-4 text-xs text-slate-400">
                    Module Achats
                </div>
            </aside>

            <div className="flex flex-1 flex-col">
                <header className="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-3">
                    <div className="text-sm text-slate-500">
                        Connecté en tant que <span className="font-medium text-slate-800">{user?.name}</span>
                        {user?.role === 'admin' && (
                            <span className="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700">
                                admin
                            </span>
                        )}
                    </div>
                    <button
                        onClick={handleLogout}
                        className="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        Se déconnecter
                    </button>
                </header>
                <main className="flex-1 p-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
