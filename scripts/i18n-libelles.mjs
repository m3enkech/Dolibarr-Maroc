/*
 * Enveloppe les libellés lus dans une constante de module — `{etape.label}`,
 * `{st.libelle}` — au moment du RENDU.
 *
 * Ces tableaux de libellés vivent hors composant : ils ne peuvent pas appeler
 * le hook. La traduction se fait donc là où la chaîne est affichée, ce qui
 * laisse la donnée intacte (la clé de tri, l'ordre du pipeline, etc.).
 *
 * Usage : node scripts/i18n-libelles.mjs <fichier…>
 */
import { readFileSync, writeFileSync } from 'node:fs';

const RENDU = /\{(\w+(?:\.\w+)*)\.(label|libelle)\}/g;

for (const fichier of process.argv.slice(2)) {
    const source = readFileSync(fichier, 'utf8');
    let compte = 0;

    const transforme = source.replace(RENDU, (tout, objet, propriete) => {
        // `key={s.label}` reste une clé React : la traduire changerait
        // l'identité de la ligne à chaque bascule de langue.
        compte++;
        return `{t(${objet}.${propriete})}`;
    });

    // On rétablit les usages en attribut, que la regex ne distingue pas.
    const final = transforme.replace(/(key|id|htmlFor|value)=\{t\((\w+(?:\.\w+)*)\.(label|libelle)\)\}/g, '$1={$2.$3}');

    if (final !== source) {
        writeFileSync(fichier, final);
        console.log(`${fichier} : ${compte} libellé(s)`);
    }
}
