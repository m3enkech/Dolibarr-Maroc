import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth, type RegisterPayload } from '@/lib/auth';

export default function Register() {
    const { register } = useAuth();
    const navigate = useNavigate();
    const [form, setForm] = useState<RegisterPayload>({
        company_name: '',
        name: '',
        email: '',
        password: '',
    });
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const set = (key: keyof RegisterPayload) => (e: React.ChangeEvent<HTMLInputElement>) =>
        setForm((f) => ({ ...f, [key]: e.target.value }));

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        setLoading(true);
        try {
            await register(form);
            navigate('/dashboard');
        } catch (err: any) {
            const messages = err?.response?.data?.errors;
            setError(
                messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Inscription impossible.',
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <h1 className="text-2xl font-semibold text-slate-900">Créer votre espace</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Votre entreprise et votre compte administrateur
                    </p>
                </div>
                <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-6 shadow-sm">
                    {error && (
                        <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
                    )}
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">
                            Nom de l'entreprise
                        </label>
                        <input
                            required
                            value={form.company_name}
                            onChange={set('company_name')}
                            placeholder="Ex. Atlas Négoce SARL"
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Votre nom</label>
                        <input
                            required
                            value={form.name}
                            onChange={set('name')}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
                        <input
                            type="email"
                            required
                            value={form.email}
                            onChange={set('email')}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">
                            Mot de passe <span className="font-normal text-slate-400">(8 caractères min.)</span>
                        </label>
                        <input
                            type="password"
                            required
                            minLength={8}
                            value={form.password}
                            onChange={set('password')}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={loading}
                        className="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {loading ? 'Création…' : "Créer l'espace"}
                    </button>
                    <p className="text-center text-sm text-slate-500">
                        Déjà inscrit ?{' '}
                        <Link to="/login" className="font-medium text-emerald-600 hover:underline">
                            Se connecter
                        </Link>
                    </p>
                </form>
            </div>
        </div>
    );
}
