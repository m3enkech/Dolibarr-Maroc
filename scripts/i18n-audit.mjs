/*
 * Repère les textes visibles restés en dur, écran par écran.
 *
 * Heuristique volontairement large : tout nœud de texte JSX ou libellé
 * (label/placeholder/title) qui contient un mot français et n'est pas passé à
 * t(…). Elle signale donc quelques faux positifs — un compte rendu utile vaut
 * mieux qu'un compte rendu exact et vide.
 *
 * Usage : node scripts/i18n-audit.mjs <fichier…>
 */
import { readFileSync } from 'node:fs';

const MOT_FR = /\b(le|la|les|un|une|des|du|de|et|ou|à|au|aux|pour|par|sur|dans|avec|sans|est|sont|votre|vos|votre|ce|cette|ces|aucun|aucune|nouveau|nouvelle|tous|toutes)\b/i;
const ACCENT = /[àâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇ]/;

let totalGeneral = 0;

for (const fichier of process.argv.slice(2)) {
    const lignes = readFileSync(fichier, 'utf8').split('\n');
    const restes = [];

    lignes.forEach((ligne, i) => {
        if (/^\s*(\/\/|\*|\/\*)/.test(ligne)) return; // commentaires
        if (ligne.includes('t(')) return; // déjà traduit sur cette ligne

        const candidats = [];
        for (const m of ligne.matchAll(/>([^<>{}\n]{3,})</g)) candidats.push(m[1].trim());
        for (const m of ligne.matchAll(/\b(placeholder|title|label|sub)="([^"]{3,})"/g)) candidats.push(m[2]);

        for (const texte of candidats) {
            if (MOT_FR.test(texte) || ACCENT.test(texte)) {
                restes.push(`  ${i + 1}: ${texte.slice(0, 70)}`);
            }
        }
    });

    if (restes.length > 0) {
        totalGeneral += restes.length;
        console.log(`${fichier} — ${restes.length}`);
        if (process.env.DETAIL) console.log(restes.join('\n'));
    }
}

console.log('\ntextes encore en dur : ' + totalGeneral);
