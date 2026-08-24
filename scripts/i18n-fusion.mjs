/*
 * Fusionne un lot de traductions dans resources/js/lang/ar.json, en conservant
 * l'existant et en gardant les clés triées : les lots successifs restent ainsi
 * relisibles dans le diff.
 *
 * Usage : node scripts/i18n-fusion.mjs <lot.json>
 */
import { readFileSync, writeFileSync } from 'node:fs';

const CIBLE = 'resources/js/lang/ar.json';

const dico = JSON.parse(readFileSync(CIBLE, 'utf8'));
const lot = JSON.parse(readFileSync(process.argv[2], 'utf8'));

let ajoutees = 0;
let ecrasees = 0;

for (const [fr, ar] of Object.entries(lot)) {
    if (fr in dico) ecrasees++;
    else ajoutees++;
    dico[fr] = ar;
}

const triees = Object.fromEntries(
    Object.entries(dico).sort(([a], [b]) => a.localeCompare(b, 'fr')),
);

writeFileSync(CIBLE, JSON.stringify(triees, null, 2) + '\n');
console.log(`ajoutées : ${ajoutees} · déjà présentes (mises à jour) : ${ecrasees} · total : ${Object.keys(triees).length}`);
