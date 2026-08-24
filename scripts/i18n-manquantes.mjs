/*
 * Liste les chaînes passées à t(…) qui n'ont pas encore de traduction arabe.
 *
 * Sert de garde-fou : le français étant la clé, une chaîne oubliée ne casse
 * rien à l'écran — elle reste simplement en français. Sans ce compte, une
 * traduction manquante passerait donc inaperçue.
 *
 * Usage : node scripts/i18n-manquantes.mjs [fichier-de-sortie]
 */
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const dico = JSON.parse(readFileSync('resources/js/lang/ar.json', 'utf8'));
const trouvees = new Set();

const SIMPLE = /\bt\(\s*'((?:[^'\\]|\\.)*)'/g;
const DOUBLE = /\bt\(\s*"((?:[^"\\]|\\.)*)"/g;

function parcourir(dossier) {
    for (const entree of readdirSync(dossier, { withFileTypes: true })) {
        const chemin = join(dossier, entree.name);

        if (entree.isDirectory()) {
            parcourir(chemin);
            continue;
        }

        if (!/\.tsx?$/.test(entree.name)) continue;

        const source = readFileSync(chemin, 'utf8');
        for (const m of source.matchAll(SIMPLE)) trouvees.add(m[1].replaceAll("\\'", "'"));
        for (const m of source.matchAll(DOUBLE)) trouvees.add(m[1].replaceAll('\\"', '"'));
    }
}

parcourir('resources/js');

const manquantes = [...trouvees].filter((s) => !(s in dico)).sort((a, b) => a.localeCompare(b, 'fr'));

console.log(
    `appels t() : ${trouvees.size} · traduites : ${trouvees.size - manquantes.length} · manquantes : ${manquantes.length}`,
);

const sortie = process.argv[2];
if (sortie) {
    writeFileSync(sortie, JSON.stringify(manquantes, null, 1));
    console.log('écrit dans ' + sortie);
}
