import { useEffect, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { useFeatures } from '@/lib/features';
import { useT } from '@/lib/langue';
import SelecteurLangue from '@/components/SelecteurLangue';

interface MenuLeaf {
    to: string;
    label: string;
    icon?: string;
    /** Domaine de permission requis (lecture). Absent = visible par tous. */
    domain?: string;
    /** Écriture requise sur le domaine (ex. gestion d'équipe, paramètres). */
    write?: boolean;
    /** Feature flag requis (module activable). */
    feature?: 'crm' | 'relances' | 'effets';
    /** Module non encore livré. */
    soon?: boolean;
    /** Réservé au superadmin plateforme. */
    superadmin?: boolean;
    /** Enfant par défaut : actif quand aucun paramètre de requête n'est présent. */
    def?: boolean;
}

interface MenuNode extends Omit<MenuLeaf, 'to'> {
    to?: string;
    /** Sous-entrées (sous-menu dépliable). */
    children?: MenuLeaf[];
}

interface MenuGroup {
    title: string;
    items: MenuNode[];
}

const GROUPS: MenuGroup[] = [
    {
        title: 'Général',
        items: [
            { to: '/dashboard', label: 'Tableau de bord', icon: '▦' },
            // Le domaine est `stock` : les deux requêtes structurantes de
            // l'écran (produits, dépôts) en sont. Tous les rôles ont au moins
            // `stock: read`, l'entrée est donc visible de tous — mais la garde
            // reste explicite, pour qu'un futur rôle sans stock ne voie ni
            // l'entrée ni un 403.
            { to: '/pilotage', label: 'Suivi', icon: '🎯', domain: 'stock' },
        ],
    },
    {
        title: 'Commercial',
        items: [
            { to: '/tiers', label: 'Tiers', icon: '👥', domain: 'tiers' },
            { to: '/adhesions', label: 'Adhésions portail', icon: '🤝', domain: 'tiers' },
            {
                label: 'Catalogue',
                icon: '📦',
                domain: 'catalogue',
                children: [
                    { to: '/catalogue', label: 'Produits & services', domain: 'catalogue' },
                    { to: '/catalogue/categories', label: 'Catégories', domain: 'catalogue' },
                    { to: '/catalogue/tarifs', label: 'Tarifs & prix gros', domain: 'catalogue' },
                ],
            },
            {
                label: 'Ventes',
                icon: '🧾',
                domain: 'ventes',
                children: [
                    { to: '/ventes?type=devis', label: 'Devis', domain: 'ventes', def: true },
                    { to: '/ventes?type=commande', label: 'Commandes', domain: 'ventes' },
                    { to: '/ventes?type=bon_livraison', label: 'Bons de livraison', domain: 'ventes' },
                    { to: '/ventes?type=facture', label: 'Factures', domain: 'ventes' },
                    { to: '/ventes?type=avoir', label: 'Avoirs', domain: 'ventes' },
                ],
            },
            { to: '/caisse', label: 'Caisse (POS)', icon: '💳', domain: 'pos' },
            { to: '/crm', label: 'CRM', icon: '📈', domain: 'crm', feature: 'crm' },
            { to: '/relances', label: 'Relances', icon: '📨', domain: 'relances', feature: 'relances' },
        ],
    },
    {
        title: 'Achats & Stock',
        items: [
            { to: '/achats', label: 'Achats', icon: '🛒', domain: 'achats' },
            { to: '/stock', label: 'Stock', icon: '🏬', domain: 'stock' },
        ],
    },
    {
        title: 'Finance',
        items: [
            {
                label: 'Comptabilité',
                icon: '⚖',
                domain: 'compta',
                children: [
                    { to: '/compta?section=ecritures', label: 'Écritures', domain: 'compta', def: true },
                    { to: '/compta?section=balance', label: 'Balance', domain: 'compta' },
                    { to: '/compta?section=balance-agee', label: 'Balance âgée', domain: 'compta' },
                    { to: '/compta?section=rapprochement', label: 'Rapprochement bancaire', domain: 'compta' },
                    { to: '/compta?section=etats', label: 'Bilan / CPC', domain: 'compta' },
                    { to: '/compta?section=tva', label: 'État TVA', domain: 'compta' },
                    { to: '/compta?section=immobilisations', label: 'Immobilisations', domain: 'compta' },
                    { to: '/compta?section=cloture', label: 'Clôture', domain: 'compta' },
                    { to: '/compta?section=plan', label: 'Plan comptable', domain: 'compta' },
                ],
            },
            { to: '/effets', label: 'Effets (LCN)', icon: '📜', domain: 'effets', feature: 'effets' },
        ],
    },
    {
        title: 'Projets & RH',
        items: [{ to: '/rh', label: 'RH & Projets', icon: '🗂', soon: true }],
    },
    {
        title: 'Administration',
        items: [
            { to: '/equipe', label: 'Équipe', icon: '🧑‍🤝‍🧑', domain: 'equipe', write: true },
            { to: '/abonnement', label: 'Mon abonnement', icon: '💠' },
            { to: '/superadmin', label: 'Plateforme', icon: '🛡', superadmin: true },
            { to: '/parametres', label: 'Paramètres', icon: '⚙', domain: 'parametres', write: true },
        ],
    },
];

const LINK_BASE = 'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition';

export default function Layout() {
    const { user, tenant, logout, can } = useAuth();
    const { features } = useFeatures();
    const t = useT();
    const navigate = useNavigate();
    const location = useLocation();
    const [openMenus, setOpenMenus] = useState<Record<string, boolean>>({});
    const [menuMobile, setMenuMobile] = useState(false);

    // Sur téléphone, la barre latérale recouvre l'écran : elle doit se refermer
    // dès qu'on a navigué, sinon on reste devant le menu au lieu de la page.
    useEffect(() => {
        setMenuMobile(false);
    }, [location.pathname, location.search]);

    // Un item/leaf est-il visible pour cet utilisateur ?
    const isVisible = (m: MenuLeaf | MenuNode): boolean => {
        if (m.superadmin) return Boolean(user?.is_superadmin);
        if (m.soon) return true; // affiché grisé
        if (m.feature && !features[m.feature]) return false;
        if (m.domain && !can(m.domain, m.write ? 'write' : 'read')) return false;
        return true;
    };

    // Un lien-enfant est actif si son chemin correspond, et — pour les liens à
    // paramètre (ex. /ventes?type=facture) — si le paramètre courant correspond
    // (ou s'il est absent et que l'enfant est le défaut).
    const leafActive = (leaf: MenuLeaf): boolean => {
        const [path, query] = leaf.to.split('?');
        if (!query) {
            return location.pathname === path; // exact, pour distinguer les enfants frères
        }
        if (location.pathname !== path) return false;
        const [key, val] = query.split('=');
        const current = new URLSearchParams(location.search).get(key);
        return current === null ? Boolean(leaf.def) : current === val;
    };

    // Parent ouvert si un enfant est actif, ou si on est sur une sous-route de l'un d'eux.
    const childActive = (children: MenuLeaf[]) =>
        children.some((c) => leafActive(c) || location.pathname.startsWith(c.to.split('?')[0] + '/'));

    const handleLogout = async () => {
        await logout();
        navigate('/login');
    };

    const renderLeaf = (m: MenuLeaf) =>
        m.soon ? (
            <div
                key={m.label}
                className={`${LINK_BASE} text-slate-500`}
                title={t('Module à venir')}
            >
                {m.icon && <span aria-hidden>{m.icon}</span>}
                {t(m.label)}
                <span className="ms-auto rounded bg-slate-800 px-1.5 py-0.5 text-[10px] uppercase tracking-wide">
                    {t('bientôt')}
                </span>
            </div>
        ) : (
            <NavLink
                key={m.to}
                to={m.to}
                end={m.to === '/dashboard' || m.to === '/catalogue'}
                className={({ isActive }) =>
                    `${LINK_BASE} ${
                        isActive ? 'bg-emerald-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                    }`
                }
            >
                {m.icon && <span aria-hidden>{m.icon}</span>}
                {t(m.label)}
            </NavLink>
        );

    return (
        <div className="flex min-h-screen bg-slate-100">
            {/* Voile : ferme le menu d'un geste, et empêche de cliquer « au
                travers » pendant qu'il recouvre la page. */}
            {menuMobile && (
                <div
                    onClick={() => setMenuMobile(false)}
                    className="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"
                    aria-hidden
                />
            )}

            {/*
             * Sous 1024 px, la barre latérale sort du flux et coulisse par-dessus
             * la page : à 390 px elle laisserait sinon 130 px au contenu.
             * `shrink-0` la protège de l'écrasement quand elle est dans le flux —
             * en flex, `w-64` seul se laisse comprimer.
             */}
            <aside
                className={`fixed inset-y-0 start-0 z-40 w-64 shrink-0 flex-col bg-slate-900 text-slate-200 lg:static lg:flex ${
                    menuMobile ? 'flex' : 'hidden'
                }`}
            >
                <div className="flex items-center justify-between gap-2 border-b border-slate-800 px-5 py-4">
                    <div className="min-w-0">
                        <div className="text-lg font-semibold text-white">Dolibarr Maroc</div>
                        <div className="mt-0.5 truncate text-xs text-slate-400">{tenant?.name}</div>
                    </div>
                    <button
                        onClick={() => setMenuMobile(false)}
                        className="-me-2 rounded-md p-2 text-slate-400 hover:text-white lg:hidden"
                        aria-label={t('Fermer')}
                    >
                        ✕
                    </button>
                </div>

                <nav className="flex-1 space-y-4 overflow-y-auto px-3 py-4">
                    {GROUPS.map((group) => {
                        // Un item à sous-menu n'est gardé que s'il a des enfants visibles.
                        const items = group.items
                            .map((item) => ({
                                item,
                                children: item.children?.filter(isVisible) ?? null,
                            }))
                            .filter(({ item, children }) =>
                                item.children ? (children?.length ?? 0) > 0 : isVisible(item),
                            );

                        if (items.length === 0) return null;

                        return (
                            <div key={group.title}>
                                <div className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                                    {t(group.title)}
                                </div>
                                <div className="space-y-1">
                                    {items.map(({ item, children }) => {
                                        // Item simple (lien direct).
                                        if (!item.children) {
                                            return renderLeaf(item as MenuLeaf);
                                        }

                                        // Item avec sous-menu dépliable.
                                        const active = childActive(children!);
                                        const open = openMenus[item.label] ?? active;

                                        return (
                                            <div key={item.label}>
                                                <button
                                                    onClick={() =>
                                                        setOpenMenus((s) => ({ ...s, [item.label]: !open }))
                                                    }
                                                    className={`${LINK_BASE} w-full ${
                                                        active
                                                            ? 'text-white'
                                                            : 'text-slate-300 hover:bg-slate-800 hover:text-white'
                                                    }`}
                                                >
                                                    {item.icon && <span aria-hidden>{item.icon}</span>}
                                                    {t(item.label)}
                                                    <span
                                                        // Fermé, le chevron pointe vers l'intérieur du
                                                        // texte : il se retourne donc en arabe.
                                                        className={`ms-auto text-xs transition-transform ${
                                                            open ? 'rotate-90' : 'rtl:rotate-180'
                                                        }`}
                                                        aria-hidden
                                                    >
                                                        ›
                                                    </span>
                                                </button>
                                                {open && (
                                                    <div className="mt-1 space-y-1 border-s border-slate-800 ps-3">
                                                        {children!.map((c) => (
                                                            <Link
                                                                key={c.to}
                                                                to={c.to}
                                                                className={`block rounded-md px-3 py-1.5 text-sm transition ${
                                                                    leafActive(c)
                                                                        ? 'bg-emerald-600 text-white'
                                                                        : 'text-slate-400 hover:bg-slate-800 hover:text-white'
                                                                }`}
                                                            >
                                                                {t(c.label)}
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </nav>

                <div className="flex items-center justify-between gap-2 border-t border-slate-800 px-5 py-4 text-xs text-slate-400">
                    <span>{t('ERP marocain multi-utilisateurs')}</span>
                    <SelecteurLangue sombre />
                </div>
            </aside>

            {/* `min-w-0` : sans lui, un tableau large pousse cette colonne
                au-delà de l'écran et c'est la PAGE entière qui défile. */}
            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
                    <button
                        onClick={() => setMenuMobile(true)}
                        className="-ms-1 rounded-md p-2 text-slate-600 transition hover:bg-slate-100 lg:hidden"
                        aria-label={t('Ouvrir le menu')}
                    >
                        <span className="block h-0.5 w-5 bg-current" />
                        <span className="mt-1 block h-0.5 w-5 bg-current" />
                        <span className="mt-1 block h-0.5 w-5 bg-current" />
                    </button>

                    <div className="min-w-0 flex-1 truncate text-sm text-slate-500">
                        {/* La formule complète ne tient pas sur un téléphone :
                            le nom seul suffit, il est cliquable de toute façon. */}
                        <span className="hidden sm:inline">{t('Connecté en tant que')} </span>
                        <NavLink to="/profil" className="font-medium text-slate-800 hover:text-emerald-600 hover:underline">
                            {user?.name}
                        </NavLink>
                        {user?.role && (
                            <span className="ms-2 hidden rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-700 sm:inline">
                                {t(user.role)}
                            </span>
                        )}
                    </div>

                    <button
                        onClick={handleLogout}
                        className="shrink-0 rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50"
                    >
                        <span className="hidden sm:inline">{t('Se déconnecter')}</span>
                        <span className="sm:hidden" aria-label={t('Se déconnecter')}>⏻</span>
                    </button>
                </header>
                <main className="min-w-0 flex-1 p-4 sm:p-6">
                    <Outlet />
                </main>
            </div>
        </div>
    );
}
