import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';

interface InvitationInfo {
    email: string;
    role_label: string;
    company_name: string;
}

export default function Rejoindre() {
    const { token = '' } = useParams();
    const { acceptInvitation } = useAuth();
    const navigate = useNavigate();

    const [info, setInfo] = useState<InvitationInfo | null>(null);
    const [invalide, setInvalide] = useState(false);
    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        api.get<{ data: InvitationInfo }>(`/invitations/${token}`)
            .then((res) => setInfo(res.data.data))
            .catch(() => setInvalide(true));
    }, [token]);

    const handleSubmit = async (e: FormEvent) => {
        e.preventDefault();
        setError(null);
        setLoading(true);
        try {
            await acceptInvitation(token, name, password);
            navigate('/dashboard');
        } catch (err: any) {
            const messages = err?.response?.data?.errors;
            setError(
                messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Impossible de rejoindre.',
            );
        } finally {
            setLoading(false);
        }
    };

    if (invalide) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
                <div className="w-full max-w-md rounded-xl bg-white p-6 text-center shadow-sm">
                    <div className="text-3xl">🔒</div>
                    <h1 className="mt-3 text-lg font-semibold text-slate-900">Invitation invalide</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Ce lien d'invitation est expiré ou a déjà été utilisé. Demandez à votre administrateur
                        de vous en générer un nouveau.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <h1 className="text-2xl font-semibold text-slate-900">Rejoindre l'équipe</h1>
                    {info && (
                        <p className="mt-1 text-sm text-slate-500">
                            Vous êtes invité(e) à rejoindre <span className="font-medium">{info.company_name}</span>
                            {' '}en tant que <span className="font-medium">{info.role_label}</span>.
                        </p>
                    )}
                </div>
                <form onSubmit={handleSubmit} className="space-y-4 rounded-xl bg-white p-6 shadow-sm">
                    {error && (
                        <div className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
                    )}
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
                        <input
                            readOnly
                            value={info?.email ?? ''}
                            className="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500"
                        />
                    </div>
                    <div>
                        <label className="mb-1 block text-sm font-medium text-slate-700">Votre nom</label>
                        <input
                            required
                            value={name}
                            onChange={(e) => setName(e.target.value)}
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
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        />
                    </div>
                    <button
                        type="submit"
                        disabled={loading || !info}
                        className="w-full rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        {loading ? 'Création…' : 'Rejoindre'}
                    </button>
                </form>
            </div>
        </div>
    );
}
