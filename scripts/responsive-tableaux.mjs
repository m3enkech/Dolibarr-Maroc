/*
 * Rend défilables les conteneurs de tableau qui CLIPPAIENT leur contenu.
 *
 * Le patron du projet enveloppe chaque <table> dans un conteneur arrondi. Quand
 * ce conteneur porte `overflow-hidden`, les colonnes qui dépassent la largeur
 * de l'écran sont coupées SANS moyen d'y accéder : sur un téléphone, la moitié
 * droite du tableau n'existe plus. `overflow-x-auto` garde les coins arrondis
 * et rend le tableau défilable — c'est déjà le patron utilisé ailleurs dans le
 * code (Effets, Balance âgée, Équipe).
 *
 * Usage : node scripts/responsive-tableaux.mjs <fichier…>
 */
import { readFileSync, writeFileSync } from 'node:fs';

let total = 0;

for (const fichier of process.argv.slice(2)) {
    const source = readFileSync(fichier, 'utf8');
    const lignes = source.split('\n');
    let compte = 0;

    lignes.forEach((ligne, i) => {
        if (!ligne.includes('overflow-hidden')) return;

        // Le conteneur n'est concerné que s'il enveloppe bien un tableau :
        // ailleurs, `overflow-hidden` sert à couper un décor, et le changer
        // ferait apparaître des barres de défilement parasites.
        //
        // La fenêtre est large : entre le conteneur et son tableau s'intercalent
        // souvent un en-tête de carte et un ternaire « liste vide ? ».
        const suite = lignes.slice(i + 1, i + 12).join('\n');
        if (!suite.includes('<table')) return;

        // …mais pas si un autre conteneur défilant s'est déjà intercalé : le
        // tableau appartient alors à celui-là, pas à celui-ci.
        if (suite.slice(0, suite.indexOf('<table')).includes('overflow-x-auto')) return;

        lignes[i] = ligne.replace('overflow-hidden', 'overflow-x-auto');
        compte++;
    });

    if (compte > 0) {
        writeFileSync(fichier, lignes.join('\n'));
        total += compte;
        console.log(`${fichier} : ${compte}`);
    }
}

console.log('\nconteneurs rendus défilables : ' + total);
