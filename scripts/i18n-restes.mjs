/*
 * Deuxième passe : enveloppe les textes que le codemod avait écartés parce
 * qu'ils cohabitaient sur la même ligne qu'une flèche de fonction ou une
 * expression. La règle est ici plus fine — un nœud de texte ENTRE deux balises,
 * sans accolade ni chevron à l'intérieur — donc sûre même sur ces lignes.
 *
 * Usage : node scripts/i18n-restes.mjs <fichier…>
 */
import { readFileSync, writeFileSync } from 'node:fs';

const MOT_FR = /\b(le|la|les|un|une|des|du|de|et|ou|à|au|aux|pour|par|sur|dans|avec|sans|est|sont|votre|vos|ce|cette|ces|aucun|aucune|nouveau|nouvelle|tous|toutes|moyen|moyenne|estimé|accordée|accordées|émis|théoriques|comptées|prévue|précis|minimum)\b/i;
const ACCENT = /[àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇ]/;
const collectees = new Set();

function litteral(texte) {
    return texte.includes("'") ? JSON.stringify(texte) : `'${texte}'`;
}

for (const fichier of process.argv.slice(2)) {
    const source = readFileSync(fichier, 'utf8');
    let compte = 0;

    const transforme = source
        .split('\n')
        .map((ligne) => {
            if (/^\s*(\/\/|\*|\/\*)/.test(ligne)) return ligne;

            return ligne.replace(/>([^<>{}\n]{3,})</g, (tout, texte) => {
                const nu = texte.trim();
                if (!MOT_FR.test(nu) && !ACCENT.test(nu)) return tout;
                if (/^\d/.test(nu)) return tout;

                collectees.add(nu);
                compte++;

                return `>${texte.match(/^\s*/)[0]}{t(${litteral(nu)})}${texte.match(/\s*$/)[0]}<`;
            });
        })
        .join('\n');

    if (transforme !== source) {
        writeFileSync(fichier, transforme);
        console.log(`${fichier} : ${compte}`);
    }
}

console.log('\n' + JSON.stringify([...collectees].sort(), null, 0));
