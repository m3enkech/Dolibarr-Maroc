/*
 * Rend les barres d'onglets défilables sur petit écran.
 *
 * Le patron du projet est une rangée `flex … p-1` en `width: fit-content` :
 * elle prend la largeur de ses onglets, quelle que soit celle de l'écran. Avec
 * neuf onglets (Comptabilité), cela poussait la PAGE 641 px au-delà du
 * téléphone. On borne la barre à la largeur disponible et on la rend
 * défilable ; les onglets, eux, refusent de se comprimer.
 *
 * Usage : node scripts/responsive-onglets.mjs <fichier…>
 */
import { readFileSync, writeFileSync } from 'node:fs';

const BARRE = 'flex rounded-lg border border-slate-200 bg-white p-1';
const BARRE_FLUIDE = 'flex max-w-full overflow-x-auto rounded-lg border border-slate-200 bg-white p-1';

// La largeur intrinsèque était posée en style inline ; `w-fit` la remplace,
// combinable avec `max-w-full` — ce que le style inline empêchait.
const STYLE_INLINE = / style=\{\{ width: 'fit-content' \}\}/g;

const ONGLETS = [
    ['rounded-md px-4 py-1.5 text-sm font-medium transition', 'shrink-0 whitespace-nowrap rounded-md px-4 py-1.5 text-sm font-medium transition'],
    ['rounded-md px-4 py-1.5 text-sm font-medium capitalize transition', 'shrink-0 whitespace-nowrap rounded-md px-4 py-1.5 text-sm font-medium capitalize transition'],
    ['rounded-md px-3 py-1 text-xs font-medium transition', 'shrink-0 whitespace-nowrap rounded-md px-3 py-1 text-xs font-medium transition'],
];

let total = 0;

for (const fichier of process.argv.slice(2)) {
    let source = readFileSync(fichier, 'utf8');
    if (!source.includes(BARRE)) continue;

    const avant = source;
    source = source.split(BARRE).join(BARRE_FLUIDE + ' w-fit');
    source = source.replace(STYLE_INLINE, '');

    for (const [de, vers] of ONGLETS) {
        // `capitalize` d'abord : sinon la variante courte capture son préfixe.
        if (source.includes(vers)) continue;
        source = source.split(de).join(vers);
    }

    if (source !== avant) {
        writeFileSync(fichier, source);
        total++;
        console.log('onglets : ' + fichier);
    }
}

console.log('\nbarres corrigées dans ' + total + ' fichier(s)');
