import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { messageErreur } from '@/lib/portail-api';
import { usePortailAuth } from '@/lib/portail-auth';

export default function PortailConnexion() {
    const { connexion, inscription } = usePortailAuth();
    const navigate = useNavigate();

    const [mode, setMode] = useState<'connexion' | 'inscription'>('connexion');
    const [nom, setNom] = useState('');
    const [email, setEmail] = useState('');
    const [telephone, setTelephone] = useState('');
    const [motDePasse, setMotDePasse] = useState('');
    const [erreur, setErreur] = useState<string | null>(null);
    const [enCours, setEnCours] = useState(false);

    const soumettre = async (e: FormEvent) => {
        e.preventDefault();
        setErreur(null);
        setEnCours(true);

        try {
            if (mode === 'connexion') {
                await connexion(email, motDePasse);
            } else {
                await inscription({ name: nom, email, password: motDePasse, phone: telephone || undefined });
            }
            navigate('/portail');
        } catch (err) {
            setErreur(messageErreur(err, 'Connexion impossible.'));
        } finally {
            setEnCours(false);
        }
    };

    const champ =
        'w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-600 text-lg font-bold text-white">
                        ⌁
                    </span>
                    <h1 className="text-xl font-semibold text-slate-900">Espace client</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Commandez chez vos grossistes et suivez vos livraisons.
                    </p>
                </div>

                <div className="rounded-xl bg-white p-6 shadow-sm">
                    <div className="mb-5 flex rounded-lg border border-slate-200 p-1">
                        {(['connexion', 'inscription'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                onClick={() => { setMode(m); setErreur(null); }}
                                className={`flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                    mode === m ? 'bg-emerald-600 text-white' : 'text-slate-600 hover:bg-slate-50'
                                }`}
                            >
                                {m === 'connexion' ? 'J\'ai un compte' : 'Créer un compte'}
                            </button>
                        ))}
                    </div>

                    {erreur && (
                        <div className="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{erreur}</div>
                    )}

                    <form onSubmit={soumettre} className="space-y-4">
                        {mode === 'inscription' && (
                            <>
                                <div>
                                    <label className="mb-1 block text-sm text-slate-600">Nom de votre commerce</label>
                                    <input
                                        required
                                        type="text"
                                        value={nom}
                                        onChange={(e) => setNom(e.target.value)}
                                        placeholder="Épicerie Al Baraka"
                                        className={champ}
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm text-slate-600">Téléphone</label>
                                    <input
                                        type="tel"
                                        value={telephone}
                                        onChange={(e) => setTelephone(e.target.value)}
                                        placeholder="06 00 00 00 00"
                                        className={champ}
                                    />
                                </div>
                            </>
                        )}

                        <div>
                            <label className="mb-1 block text-sm text-slate-600">Adresse e-mail</label>
                            <input
                                required
                                type="email"
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                className={champ}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm text-slate-600">Mot de passe</label>
                            <input
                                required
                                type="password"
                                value={motDePasse}
                                onChange={(e) => setMotDePasse(e.target.value)}
                                minLength={8}
                                className={champ}
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={enCours}
                            className="w-full rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                        >
                            {enCours ? 'Un instant…' : mode === 'connexion' ? 'Se connecter' : 'Créer mon compte'}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}
