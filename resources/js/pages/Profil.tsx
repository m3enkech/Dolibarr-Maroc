import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { User } from '@/types';

export default function Profil() {
    const { user, tenant, updateUser } = useAuth();
    const [name, setName] = useState(user?.name ?? '');
    const [nomOk, setNomOk] = useState(false);

    const [current, setCurrent] = useState('');
    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [pwdMsg, setPwdMsg] = useState<{ type: 'ok' | 'err'; text: string } | null>(null);

    const saveNom = useMutation({
        mutationFn: () => api.put<{ user: User }>('/auth/profile', { name }),
        onSuccess: (res) => {
            updateUser(res.data.user);
            setNomOk(true);
            setTimeout(() => setNomOk(false), 2000);
        },
    });

    const savePwd = useMutation({
        mutationFn: () => api.put('/auth/password', { current_password: current, password }),
        onSuccess: () => {
            setPwdMsg({ type: 'ok', text: 'Mot de passe mis à jour.' });
            setCurrent('');
            setPassword('');
            setConfirm('');
        },
        onError: (err: any) => {
            const messages = err?.response?.data?.errors;
            setPwdMsg({
                type: 'err',
                text: messages ? (Object.values(messages).flat() as string[]).join(' ') : 'Échec de la mise à jour.',
            });
        },
    });

    const submitPwd = () => {
        setPwdMsg(null);
        if (password !== confirm) {
            setPwdMsg({ type: 'err', text: 'Les deux mots de passe ne correspondent pas.' });
            return;
        }
        savePwd.mutate();
    };

    const champ = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <div className="max-w-2xl space-y-6">
            <div>
                <h1 className="text-xl font-semibold text-slate-900">Mon compte</h1>
                <p className="mt-1 text-sm text-slate-500">
                    {user?.email} · {tenant?.name}
                </p>
            </div>

            {/* Identité */}
            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">Profil</h2>
                <div className="mt-4">
                    <label className="mb-1 block text-xs font-medium text-slate-600">Nom</label>
                    <input value={name} onChange={(e) => setName(e.target.value)} className={champ} />
                </div>
                <div className="mt-4 flex items-center gap-3">
                    <button
                        onClick={() => saveNom.mutate()}
                        disabled={saveNom.isPending || !name.trim()}
                        className="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                    >
                        Enregistrer
                    </button>
                    {nomOk && <span className="text-sm text-emerald-600">✓ Enregistré</span>}
                </div>
            </section>

            {/* Mot de passe */}
            <section className="rounded-xl bg-white p-6 shadow-sm">
                <h2 className="font-medium text-slate-900">Changer mon mot de passe</h2>
                {pwdMsg && (
                    <div
                        className={`mt-3 rounded-md px-3 py-2 text-sm ${
                            pwdMsg.type === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'
                        }`}
                    >
                        {pwdMsg.text}
                    </div>
                )}
                <div className="mt-4 space-y-4">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Mot de passe actuel</label>
                        <input type="password" value={current} onChange={(e) => setCurrent(e.target.value)} className={champ} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">
                            Nouveau mot de passe <span className="font-normal text-slate-400">(8 car. min.)</span>
                        </label>
                        <input type="password" minLength={8} value={password} onChange={(e) => setPassword(e.target.value)} className={champ} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-slate-600">Confirmer</label>
                        <input type="password" minLength={8} value={confirm} onChange={(e) => setConfirm(e.target.value)} className={champ} />
                    </div>
                </div>
                <button
                    onClick={submitPwd}
                    disabled={savePwd.isPending || !current || !password}
                    className="mt-4 rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-60"
                >
                    {savePwd.isPending ? 'Mise à jour…' : 'Mettre à jour le mot de passe'}
                </button>
            </section>
        </div>
    );
}
