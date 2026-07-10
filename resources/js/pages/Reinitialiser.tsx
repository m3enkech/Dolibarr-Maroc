import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api } from '@/lib/api';

export default function Reinitialiser() {
    const { token = '' } = useParams();
    const [params] = useSearchParams();
    const navigate = useNavigate();
    const email = params.get('email') ?? '';

    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        if (password !== confirm) {
            setError('Les deux mots de passe ne correspondent pas.');
            return;
        }
        setLoading(true);
        try {
            await api.post('/auth/reset-password', { email, token, password });
            navigate('/login', { replace: true });
        } catch (err: any) {
            const messages = err?.response?.data?.errors;
            setError(
                messages
                    ? (Object.values(messages).flat() as string[]).join(' ')
                    : 'Ce lien est invalide ou expiré.',
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <h1 className="text-2xl font-semibold text-slate-900">Nouveau mot de passe</h1>
                    <p className="mt-1 text-sm text-slate-500">Choisissez un nouveau mot de passe pour {email}</p>
                </div>
                <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-6 shadow-sm">
                    {error && <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">
                            Nouveau mot de passe <span className="font-normal text-slate-400">(8 car. min.)</span>
                        </label>
                        <input
                            type="password"
                            required
                            minLength={8}
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Confirmer</label>
                        <input
                            type="password"
                            required
                            minLength={8}
                            value={confirm}
                            onChange={(e) => setConfirm(e.target.value)}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={loading}
                        className="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {loading ? 'Enregistrement…' : 'Réinitialiser'}
                    </button>
                    <p className="text-center text-sm text-slate-500">
                        <Link to="/login" className="font-medium text-emerald-600 hover:underline">
                            Retour à la connexion
                        </Link>
                    </p>
                </form>
            </div>
        </div>
    );
}
