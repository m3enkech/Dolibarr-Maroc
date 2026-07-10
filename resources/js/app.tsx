import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, RequireAuth } from '@/lib/auth';
import Layout from '@/components/Layout';
import Landing from '@/pages/Landing';
import Dashboard from '@/pages/Dashboard';
import Login from '@/pages/Login';
import Register from '@/pages/Register';
import Rejoindre from '@/pages/Rejoindre';
import MotDePasseOublie from '@/pages/MotDePasseOublie';
import Reinitialiser from '@/pages/Reinitialiser';
import Profil from '@/pages/Profil';
import Equipe from '@/pages/equipe/Equipe';
import CategoriesProduit from '@/pages/catalogue/CategoriesProduit';
import ProduitForm from '@/pages/catalogue/ProduitForm';
import ProduitsList from '@/pages/catalogue/ProduitsList';
import AchatDetail from '@/pages/achats/AchatDetail';
import AchatForm from '@/pages/achats/AchatForm';
import AchatsList from '@/pages/achats/AchatsList';
import ComptaPage from '@/pages/compta/ComptaPage';
import CrmPage from '@/pages/crm/CrmPage';
import Effets from '@/pages/effets/Effets';
import Parametres from '@/pages/Parametres';
import PosPage from '@/pages/pos/PosPage';
import Relances from '@/pages/relances/Relances';
import StockPage from '@/pages/stock/StockPage';
import TiersForm from '@/pages/tiers/TiersForm';
import TiersList from '@/pages/tiers/TiersList';
import VenteDetail from '@/pages/ventes/VenteDetail';
import VenteForm from '@/pages/ventes/VenteForm';
import VentesList from '@/pages/ventes/VentesList';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: 1,
            refetchOnWindowFocus: false,
        },
    },
});

function App() {
    return (
        <QueryClientProvider client={queryClient}>
            <BrowserRouter>
                <AuthProvider>
                    <Routes>
                        <Route path="/" element={<Landing />} />
                        <Route path="/login" element={<Login />} />
                        <Route path="/register" element={<Register />} />
                        <Route path="/rejoindre/:token" element={<Rejoindre />} />
                        <Route path="/mot-de-passe-oublie" element={<MotDePasseOublie />} />
                        <Route path="/reinitialiser/:token" element={<Reinitialiser />} />
                        {/* Caisse : plein écran, hors du Layout applicatif. */}
                        <Route
                            path="/caisse"
                            element={
                                <RequireAuth>
                                    <PosPage />
                                </RequireAuth>
                            }
                        />
                        <Route
                            element={
                                <RequireAuth>
                                    <Layout />
                                </RequireAuth>
                            }
                        >
                            <Route path="/dashboard" element={<Dashboard />} />
                            <Route path="/tiers" element={<TiersList />} />
                            <Route path="/tiers/nouveau" element={<TiersForm />} />
                            <Route path="/tiers/:id" element={<TiersForm />} />
                            <Route path="/catalogue" element={<ProduitsList />} />
                            <Route path="/catalogue/categories" element={<CategoriesProduit />} />
                            <Route path="/catalogue/nouveau" element={<ProduitForm />} />
                            <Route path="/catalogue/:id" element={<ProduitForm />} />
                            <Route path="/ventes" element={<VentesList />} />
                            <Route path="/ventes/nouveau" element={<VenteForm />} />
                            <Route path="/ventes/:id" element={<VenteDetail />} />
                            <Route path="/ventes/:id/modifier" element={<VenteForm />} />
                            <Route path="/achats" element={<AchatsList />} />
                            <Route path="/achats/nouveau" element={<AchatForm />} />
                            <Route path="/achats/:id" element={<AchatDetail />} />
                            <Route path="/achats/:id/modifier" element={<AchatForm />} />
                            <Route path="/stock" element={<StockPage />} />
                            <Route path="/relances" element={<Relances />} />
                            <Route path="/crm" element={<CrmPage />} />
                            <Route path="/effets" element={<Effets />} />
                            <Route path="/compta" element={<ComptaPage />} />
                            <Route path="/equipe" element={<Equipe />} />
                            <Route path="/profil" element={<Profil />} />
                            <Route path="/parametres" element={<Parametres />} />
                        </Route>
                        <Route path="*" element={<Navigate to="/" replace />} />
                    </Routes>
                </AuthProvider>
            </BrowserRouter>
        </QueryClientProvider>
    );
}

createRoot(document.getElementById('root')!).render(<App />);
