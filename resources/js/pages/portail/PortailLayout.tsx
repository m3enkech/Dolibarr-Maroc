import { NavLink, Outlet, useNavigate, useParams } from 'react-router-dom';
import { usePortailAuth } from '@/lib/portail-auth';
import { useT } from '@/lib/langue';
import SelecteurLangue from '@/components/SelecteurLangue';

/**
 * Coquille du portail acheteur. Volontairement différente de l'ERP : pas de
 * barre latérale de modules, une navigation courte, et une mise en page pensée
 * pour un téléphone — un épicier commande depuis son comptoir.
 */
export default function PortailLayout() {
    const { acheteur, deconnexion } = usePortailAuth();
    const { grossiste } = useParams();
    const navigate = useNavigate();
    const t = useT();

    const lien = ({ isActive }: { isActive: boolean }) =>
        `rounded-lg px-3 py-2 text-sm font-medium transition ${
            isActive ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-100'
        }`;

    return (
        <div className="min-h-screen bg-slate-50">
            <header className="sticky top-0 z-20 border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between gap-3 px-4 py-3">
                    <button
                        onClick={() => navigate('/portail')}
                        className="flex items-center gap-2 text-start"
                    >
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-emerald-600 text-sm font-bold text-white">
                            ⌁
                        </span>
                        <span>
                            <span className="block text-sm font-semibold text-slate-900">
                                {t('Espace client')}
                            </span>
                            <span className="block text-xs text-slate-500">{acheteur?.name}</span>
                        </span>
                    </button>

                    <div className="flex items-center gap-2">
                        <SelecteurLangue />
                        <button
                            onClick={() => deconnexion().then(() => navigate('/portail/connexion'))}
                            className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            {t('Se déconnecter')}
                        </button>
                    </div>
                </div>

                {/* Navigation propre à un grossiste : n'apparaît qu'une fois choisi. */}
                {grossiste && (
                    <nav className="mx-auto flex max-w-5xl gap-1 overflow-x-auto px-4 pb-2">
                        <NavLink to={`/portail/${grossiste}/catalogue`} className={lien}>
                            {t('Catalogue')}
                        </NavLink>
                        <NavLink to={`/portail/${grossiste}/commandes`} className={lien}>
                            {t('Mes commandes')}
                        </NavLink>
                        <NavLink to={`/portail/${grossiste}/factures`} className={lien}>
                            {t('Mes factures')}
                        </NavLink>
                        <NavLink to={`/portail/${grossiste}/compte`} className={lien}>
                            {t('Mon compte')}
                        </NavLink>
                        <NavLink to="/portail" className={lien} end>
                            {t('Changer de grossiste')}
                        </NavLink>
                    </nav>
                )}
            </header>

            <main className="mx-auto max-w-5xl px-4 py-6">
                <Outlet />
            </main>
        </div>
    );
}
