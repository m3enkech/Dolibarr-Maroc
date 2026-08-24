import { LANGUES, useLangue } from '@/lib/langue';

/**
 * Bascule français / arabe. Deux langues seulement : un menu déroulant serait
 * un clic de trop, deux boutons se lisent d'un coup d'œil.
 */
export default function SelecteurLangue({ sombre = false }: { sombre?: boolean }) {
    const { langue, changer } = useLangue();

    const actif = sombre ? 'bg-slate-700 text-white' : 'bg-slate-900 text-white';
    const inactif = sombre
        ? 'text-slate-400 hover:text-white'
        : 'text-slate-500 hover:text-slate-800';

    return (
        <div
            className={`flex items-center gap-0.5 rounded-lg p-0.5 ${sombre ? 'bg-slate-800' : 'bg-slate-100'}`}
            role="group"
            aria-label="Langue"
        >
            {LANGUES.map((l) => (
                <button
                    key={l.code}
                    onClick={() => changer(l.code)}
                    aria-pressed={langue === l.code}
                    // La langue s'écrit toujours dans sa propre graphie : un
                    // arabophone cherche « العربية », pas « Arabe ».
                    lang={l.code}
                    className={`rounded-md px-2 py-1 text-xs font-medium transition ${
                        langue === l.code ? actif : inactif
                    }`}
                >
                    {l.code === 'fr' ? 'FR' : 'ع'}
                </button>
            ))}
        </div>
    );
}
