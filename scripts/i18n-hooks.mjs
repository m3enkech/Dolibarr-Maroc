/*
 * Déclare `const t = useT();` en tête de chaque composant qui appelle t(…),
 * et ajoute l'import s'il manque.
 *
 * Le codemod ne branchait le hook que sur le composant exporté par défaut ;
 * or ces écrans découpent souvent leur rendu en sous-composants, qui ont
 * chacun besoin de leur propre appel de hook.
 *
 * Usage : node scripts/i18n-hooks.mjs <fichier…>
 */
import { readFileSync, writeFileSync } from 'node:fs';

/** Fin du bloc ouvert par l'accolade à l'index `debut`. */
function finDuBloc(source, debut) {
    let profondeur = 0;
    for (let i = debut; i < source.length; i++) {
        if (source[i] === '{') profondeur++;
        else if (source[i] === '}' && --profondeur === 0) return i;
    }

    return source.length;
}

for (const fichier of process.argv.slice(2)) {
    let source = readFileSync(fichier, 'utf8');
    if (!/\bt\(/.test(source)) continue;

    // Composants : `function Xxx(…) {`, exportés ou non.
    const composants = [...source.matchAll(/^(?:export )?(?:default )?function ([A-Z]\w*)\s*\(/gm)];
    let decalage = 0;

    for (const composant of composants) {
        const depart = composant.index + decalage;
        const ouvrante = source.indexOf('{', source.indexOf(')', depart));
        // Une signature peut porter un type de retour : on saute jusqu'au corps.
        const corpsDebut = source.indexOf('{\n', ouvrante) === ouvrante ? ouvrante : ouvrante;
        const corpsFin = finDuBloc(source, corpsDebut);
        const corps = source.slice(corpsDebut, corpsFin);

        if (!/\bt\(/.test(corps) || corps.includes('const t = useT()')) continue;

        const insertion = corpsDebut + 1;
        source = source.slice(0, insertion) + '\n    const t = useT();' + source.slice(insertion);
        decalage += '\n    const t = useT();'.length;
    }

    if (!source.includes("from '@/lib/langue'")) {
        const imports = [...source.matchAll(/^import .*;$/gm)];
        if (imports.length > 0) {
            const dernier = imports[imports.length - 1];
            const position = dernier.index + dernier[0].length;
            source = source.slice(0, position) + "\nimport { useT } from '@/lib/langue';" + source.slice(position);
        }
    }

    writeFileSync(fichier, source);
    console.log('hooks : ' + fichier);
}
