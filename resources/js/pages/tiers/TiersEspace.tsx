import { useEffect, useRef } from 'react';
import { Link, Outlet, useLocation, useMatch } from 'react-router-dom';
import { useAuth } from '@/lib/auth';
import { useT } from '@/lib/langue';
import { sansParamsFiche } from '@/pages/tiers/params';
import TiersList from '@/pages/tiers/TiersList';

/**
 * L'espace tiers : la liste et la fiche côte à côte, façon Zoho Books.
 *
 * On passait jusqu'ici de la liste à la fiche et retour, en perdant sa place à
 * chaque aller-retour : comparer deux clients, ou parcourir les impayés un par
 * un, coûtait deux écrans par tiers. Ici la liste reste, la fiche change.
 *
 * CÔTE À CÔTE À PARTIR DE 1280 px SEULEMENT. La barre latérale fixe prend
 * 256 px dès 1024 : entre les deux, il resterait 720 px à partager — une
 * liste de 300 px et une fiche de 400, ni l'une ni l'autre lisible. En
 * dessous, `/tiers` montre la liste seule et `/tiers/:id` la fiche seule, en
 * pleine page : pas de feuille du bas pour une fiche entière, on y perdrait
 * ses onglets sous le pouce.
 *
 * Au bureau, CHAQUE COLONNE DÉFILE SEULE : la page, elle, ne bouge plus. Sa
 * hauteur est celle de l'écran moins l'en-tête de l'application (3,5 rem +
 * 3 px de bordures) et la marge de <main> (2 × 1,5 rem).
 */
export default function TiersEspace() {
    const t = useT();
    const correspondance = useMatch('/tiers/:id');
    const brut = correspondance?.params.id;
    const idActif = brut !== undefined && /^\d+$/.test(brut) ? Number(brut) : null;

    // La fiche change, son conteneur reste : sans ce retour en haut, le tiers
    // suivant s'ouvrirait au milieu, à la hauteur où on lisait le précédent.
    // Sous 1280 px c'est la page qui défile — même remède.
    const colonneFiche = useRef<HTMLDivElement>(null);
    useEffect(() => {
        colonneFiche.current?.scrollTo({ top: 0 });
        if (idActif !== null && !window.matchMedia('(min-width: 1280px)').matches) {
            window.scrollTo({ top: 0 });
        }
    }, [idActif]);

    return (
        <div className="xl:grid xl:h-[calc(100dvh-6.5rem-3px)] xl:grid-cols-[20rem_minmax(0,1fr)] xl:gap-4 2xl:grid-cols-[24rem_minmax(0,1fr)]">
            <section
                className={`${correspondance ? 'hidden xl:flex' : 'flex'} min-h-0 min-w-0 flex-col`}
                aria-label={t('Liste des tiers')}
            >
                <TiersList idActif={idActif} />
            </section>

            <div
                ref={colonneFiche}
                className={`${correspondance ? 'block' : 'hidden xl:block'} min-h-0 min-w-0 xl:overflow-y-auto`}
            >
                <Outlet />
            </div>
        </div>
    );
}

/** Ce qu'affiche la colonne de droite tant qu'aucun tiers n'est choisi. */
export function TiersAucunChoisi() {
    const t = useT();
    const { can } = useAuth();
    const { search } = useLocation();

    return (
        <div className="flex h-full min-h-[50vh] items-center justify-center rounded-xl border-2 border-dashed border-slate-200 p-8 text-center">
            <div className="max-w-sm">
                <div className="text-4xl" aria-hidden>
                    👥
                </div>
                <h2 className="mt-3 font-medium text-slate-900">{t('Sélectionnez un tiers')}</h2>
                <p className="mt-1 text-sm text-slate-500">
                    {t("Sa fiche s'ouvre ici, sans quitter la liste : ses chiffres, ses pièces, ses contacts.")}
                </p>
                {/* Un rôle en lecture n'a que le titre et le texte : l'inviter
                    à créer, c'était l'envoyer vers un refus. */}
                {can('tiers', 'write') && (
                    <Link
                        to={{ pathname: '/tiers/nouveau', search: sansParamsFiche(search) }}
                        className="mt-4 inline-block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                    >
                        {t('+ Nouveau tiers')}
                    </Link>
                )}
            </div>
        </div>
    );
}
