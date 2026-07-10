import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { api } from '@/lib/api';

export default function MotDePasseOublie() {
    const [email, setEmail] = useState('');
    const [envoye, setEnvoye] = useState(false);
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setLoading(true);
        try {
            await api.post('/auth/forgot-password', { email });
            setEnvoye(true);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <h1 className="text-2xl font-semibold text-slate-900">Mot de passe oublié</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Saisissez votre email pour recevoir un lien de réinitialisation
                    </p>
                </div>

                {envoye ? (
                    <div className="rounded-xl bg-white p-6 text-center shadow-sm">
                        <div className="text-3xl">📩</div>
                        <p className="mt-3 text-sm text-slate-600">
                            Si un compte existe pour <span className="font-medium">{email}</span>, un lien de
                            réinitialisation vient d'être envoyé. Pensez à vérifier vos spams.
                        </p>
                        <Link
                            to="/login"
                            className="mt-4 inline-block text-sm font-medium text-emerald-600 hover:underline"
                        >
                            Retour à la connexion
                        </Link>
                    </div>
                ) : (
                    <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-6 shadow-sm">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
                            <input
                                type="email"
                                required
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={loading}
                            className="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                        >
                            {loading ? 'Envoi…' : 'Envoyer le lien'}
                        </button>
                        <p className="text-center text-sm text-slate-500">
                            <Link to="/login" className="font-medium text-emerald-600 hover:underline">
                                Retour à la connexion
                            </Link>
                        </p>
                    </form>
                )}
            </div>
        </div>
    );
}
