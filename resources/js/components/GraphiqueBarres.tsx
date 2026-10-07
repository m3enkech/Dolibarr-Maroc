import { useId, useLayoutEffect, useRef, useState } from 'react';
import { useFormats } from '@/lib/formats-langue';
import { useLangue, useT } from '@/lib/langue';

/** Une barre par mois ('YYYY-MM'), et la valeur d'une éventuelle courbe superposée. */
export type PointMensuel = { mois: string; valeur: number; secondaire?: number };

/**
 * Le dessin « à l'échelle » du tableau de bord : une boîte de dessin fixe que
 * le navigateur étire à la largeur de la carte, avec un plancher sous lequel
 * le graphique défile au lieu de rétrécir.
 */
export type Echelle = { largeur: number; hauteur: number; largeurMin: number };

/**
 * Graphique en barres par mois, sans bibliothèque. Tiré du tableau de bord
 * (BarChart12) pour servir aussi aux revenus d'un client sur la fiche tiers.
 *
 * DEUX FAÇONS DE DESSINER, UN SEUL DESSIN.
 *
 * - À l'ÉCHELLE (`echelle`) : ce que faisait le tableau de bord, gardé à
 *   l'identique — sa boîte de 720 × 240 s'étire avec la carte, et sous
 *   560 px le graphique défile. Changer son rendu n'était pas l'objet : son
 *   axe reste posé sur zéro, et un mois négatif n'y a pas de barre, comme
 *   avant l'extraction (l'info-bulle et le tableau masqué donnent sa valeur).
 *
 * - AU PIXEL (défaut) : la boîte de dessin prend la largeur MESURÉE du
 *   conteneur, à hauteur fixe. Une boîte fixe étirée dans une demi-colonne de
 *   350 px réduisait le texte de 10 à 5 px ; lui imposer une largeur minimale
 *   faisait défiler la moitié d'une fiche pour six barres. Ici le texte garde
 *   sa taille et, quand les mois se serrent (douze barres sur un téléphone),
 *   un mois sur deux est nommé — le dernier, le mois en cours, toujours.
 *
 * AU PIXEL, LES VALEURS NÉGATIVES ONT LEUR BARRE. Un mois où les avoirs
 * dépassent les factures descend sous une ligne du zéro, dans une autre
 * couleur que la légende nomme : une barre absente ferait croire à un mois
 * sans rien.
 *
 * EN ARABE, LE TEMPS VA DE DROITE À GAUCHE, comme la lecture : le mois le
 * plus récent est à gauche. Seules les abscisses sont retournées, pas le
 * texte (un `scale(-1, 1)` aurait écrit les mois en miroir).
 *
 * ACCESSIBLE SANS LA SOURIS. L'image porte un nom et une description ; les
 * valeurs, que la souris lit dans les info-bulles des barres, sont aussi dans
 * un tableau masqué que lisent les lecteurs d'écran.
 */
export default function GraphiqueBarres({
    points,
    titre,
    description,
    libelleValeur,
    libelleSecondaire,
    echelle,
    hauteur = 180,
}: {
    points: PointMensuel[];
    /** Nom accessible de l'image, et légende du tableau masqué. */
    titre: string;
    description?: string;
    /** Ce que mesurent les barres, dit dans la légende et en tête du tableau. */
    libelleValeur: string;
    /** Présent : la courbe secondaire est tracée (les achats du tableau de bord). */
    libelleSecondaire?: string;
    echelle?: Echelle;
    /** Hauteur en pixels du dessin au pixel ; ignorée à l'échelle. */
    hauteur?: number;
}) {
    const t = useT();
    const { dir } = useLangue();
    const { montant, moisCourt, moisLong } = useFormats();
    const ids = useId();
    const conteneur = useRef<HTMLDivElement>(null);
    const [largeurMesuree, setLargeurMesuree] = useState(0);

    // Mesurée AVANT de peindre : sinon une première image dessinée à zéro de
    // large clignoterait. Seul le dessin au pixel en a besoin.
    useLayoutEffect(() => {
        const element = conteneur.current;
        if (echelle || !element) return;

        setLargeurMesuree(element.clientWidth);
        const observateur = new ResizeObserver(([entree]) => setLargeurMesuree(Math.floor(entree.contentRect.width)));
        observateur.observe(element);

        return () => observateur.disconnect();
    }, [echelle]);

    const W = echelle ? echelle.largeur : largeurMesuree;
    const H = echelle ? echelle.hauteur : hauteur;
    const taillePolice = echelle ? 10 : 11;
    const padL = 8;
    const padB = 22;
    const padT = 12;
    const n = points.length || 1;
    const slot = (W - padL) / n;
    const bw = slot * 0.55;

    // À l'échelle, plancher à zéro : la valeur dessinée d'un mois négatif est
    // nulle, l'axe ne remonte pas et les autres barres gardent leur hauteur.
    const dessinee = (v: number) => (echelle ? Math.max(0, v) : v);
    const valeurs = points.flatMap((p) => (libelleSecondaire ? [p.valeur, p.secondaire ?? 0] : [p.valeur])).map(dessinee);
    const max = Math.max(1, ...valeurs);
    const min = Math.min(0, ...valeurs);
    const y = (v: number) => padT + (H - padT - padB) * ((max - v) / (max - min));

    // Abscisse du bord de début d'une barre, retournée en arabe.
    const rtl = dir === 'rtl';
    const xGauche = (i: number) => padL + slot * i + (slot - bw) / 2;
    const x = (i: number) => (rtl ? W - xGauche(i) - bw : xGauche(i));
    const centre = (i: number) => x(i) + bw / 2;

    // Au pixel, un nom de mois demande ~36 px : en dessous, un sur deux,
    // comptés depuis le dernier pour que le mois en cours soit toujours nommé.
    const pas = !echelle && slot < 36 ? 2 : 1;
    const nomme = (i: number) => (points.length - 1 - i) % pas === 0;

    const secondairePts = points.map((p, i) => `${centre(i)},${y(p.secondaire ?? 0)}`).join(' ');

    const legende = (
        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
            <span className="flex items-center gap-1.5">
                <span className="inline-block h-2.5 w-2.5 rounded-sm bg-emerald-600" /> {libelleValeur}
            </span>
            {/* Le rouge seulement quand une barre l'est : une légende pour
                une couleur absente ferait chercher le mois en question. */}
            {min < 0 && (
                <span className="flex items-center gap-1.5">
                    <span className="inline-block h-2.5 w-2.5 rounded-sm bg-rose-600" /> {t('Mois net négatif')}
                </span>
            )}
            {libelleSecondaire && (
                <span className="flex items-center gap-1.5">
                    <span className="inline-block h-2.5 w-2.5 rounded-sm bg-amber-500" /> {libelleSecondaire}
                </span>
            )}
        </div>
    );

    // `relative` : le tableau sr-only (position absolue) doit avoir un ancêtre
    // positionné. Sans lui, il se plaçait par rapport à la page entière et
    // l'allongeait (1 388 px pour 900 visibles dans l'espace tiers, qui ne
    // doit défiler que par ses colonnes) — même piège que la liste du lot 1.
    return (
        <figure className="relative">
            <div ref={conteneur} className={echelle ? 'overflow-x-auto' : 'min-w-0'}>
                {W > 0 ? (
                    <svg
                        viewBox={`0 0 ${W} ${H}`}
                        width={echelle ? undefined : W}
                        height={echelle ? undefined : H}
                        className={echelle ? 'w-full' : 'block'}
                        style={echelle ? { minWidth: echelle.largeurMin } : undefined}
                        role="img"
                        // `aria-label` et non un <title> racine : celui-ci
                        // s'affiche en info-bulle sur tout le fond du dessin,
                        // par-dessus celles des barres.
                        aria-label={titre}
                        aria-describedby={description ? `${ids}-desc` : undefined}
                    >
                        {description && <desc id={`${ids}-desc`}>{description}</desc>}
                        {/* lignes de repère */}
                        {[0.25, 0.5, 0.75, 1].map((part) => (
                            <line
                                key={part}
                                x1={rtl ? 0 : padL}
                                x2={rtl ? W - padL : W}
                                y1={y(max * part)}
                                y2={y(max * part)}
                                stroke="#f1f5f9"
                                strokeWidth={1}
                            />
                        ))}
                        {/* le zéro, seulement quand une barre descend dessous */}
                        {min < 0 && (
                            <line x1={rtl ? 0 : padL} x2={rtl ? W - padL : W} y1={y(0)} y2={y(0)} stroke="#cbd5e1" strokeWidth={1} />
                        )}
                        {/* barres */}
                        {points.map((p, i) => (
                            <g key={p.mois}>
                                <rect
                                    x={x(i)}
                                    y={y(Math.max(dessinee(p.valeur), 0))}
                                    width={bw}
                                    height={Math.max(0, y(Math.min(dessinee(p.valeur), 0)) - y(Math.max(dessinee(p.valeur), 0)))}
                                    rx={3}
                                    fill={dessinee(p.valeur) < 0 ? '#e11d48' : '#059669'}
                                >
                                    <title>{`${moisCourt(p.mois)} : ${montant(p.valeur)}`}</title>
                                </rect>
                                {nomme(i) && (
                                    <text
                                        x={centre(i)}
                                        y={H - 6}
                                        textAnchor="middle"
                                        className="fill-slate-400"
                                        fontSize={taillePolice}
                                    >
                                        {moisCourt(p.mois)}
                                    </text>
                                )}
                            </g>
                        ))}
                        {/* courbe secondaire */}
                        {libelleSecondaire && (
                            <polyline points={secondairePts} fill="none" stroke="#f59e0b" strokeWidth={2} strokeLinejoin="round" />
                        )}
                        {libelleSecondaire &&
                            points.map((p, i) => (
                                <circle key={`s-${p.mois}`} cx={centre(i)} cy={y(p.secondaire ?? 0)} r={2.5} fill="#f59e0b">
                                    <title>{`${libelleSecondaire} ${moisCourt(p.mois)} : ${montant(p.secondaire ?? 0)}`}</title>
                                </circle>
                            ))}
                    </svg>
                ) : (
                    // Avant la première mesure : la place, sans dessin.
                    <div style={{ height: H }} aria-hidden />
                )}
                {legende}
            </div>

            {/* Les valeurs pour les lecteurs d'écran : un tableau se parcourt
                cellule par cellule, une image non. */}
            <table className="sr-only">
                <caption>{titre}</caption>
                <thead>
                    <tr>
                        <th scope="col">{t('Mois')}</th>
                        <th scope="col">{libelleValeur}</th>
                        {libelleSecondaire && <th scope="col">{libelleSecondaire}</th>}
                    </tr>
                </thead>
                <tbody>
                    {points.map((p) => (
                        <tr key={p.mois}>
                            <th scope="row">{moisLong(p.mois)}</th>
                            <td>{montant(p.valeur)}</td>
                            {libelleSecondaire && <td>{montant(p.secondaire ?? 0)}</td>}
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}
