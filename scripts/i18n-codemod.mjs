/*
 * Enveloppe dans t(…) les chaînes françaises des écrans passés en argument.
 *
 * Volontairement CONSERVATEUR : il ne touche qu'aux formes sans ambiguïté
 * (nœud de texte JSX seul sur sa ligne, texte inline entre deux balises,
 * placeholder/title littéral). Tout le reste — ternaires, gabarits, tableaux
 * de libellés — reste à faire à la main : mieux vaut un fichier à finir qu'un
 * fichier cassé.
 *
 * Usage : node scripts/i18n-codemod.mjs <fichier…>
 * Sortie : les chaînes collectées, à traduire dans resources/js/lang/ar.json.
 */
import { readFileSync, writeFileSync } from 'node:fs';

const collectees = new Set();

/** Littéral JS pour une chaîne : guillemets doubles si elle contient une apostrophe. */
function litteral(texte) {
    return texte.includes("'") ? JSON.stringify(texte) : `'${texte}'`;
}

/** Une chaîne mérite-t-elle d'être traduite ? */
function traduisible(texte) {
    const nu = texte.trim();
    if (nu.length < 2) return false;
    if (!/[A-Za-zÀ-ÿ]/.test(nu)) return false; // « — », « 12 », « → »
    if (/^[A-Z_]+$/.test(nu)) return false; // constantes
    if (/^\d+([.,]\d+)?\s*%?$/.test(nu)) return false;
    return true;
}

function transformer(source) {
    const lignes = source.split('\n');
    const sortie = [];

    for (let i = 0; i < lignes.length; i++) {
        let ligne = lignes[i];

        // Ne jamais retoucher ce qui est déjà traduit, ni les commentaires —
        // ni une ligne de CODE : `api.get<{ data: X }>(…)` ou une annotation
        // générique forment eux aussi des paires « > … < ».
        const dejaFait = ligne.includes('t(');
        const commentaire = /^\s*(\/\/|\/\*|\*)/.test(ligne);
        const code = /=>|\bapi\.|\bawait\b|React\.|: keyof|\bexport\b|\bimport\b|<\w+(\[\])?[,>]/.test(ligne);

        if (!dejaFait && !commentaire && !code) {
            // 1. Attributs littéraux.
            ligne = ligne.replace(/\b(placeholder|title)="([^"{}<>]+)"/g, (tout, attr, texte) => {
                if (!traduisible(texte)) return tout;
                collectees.add(texte);
                return `${attr}={t(${litteral(texte)})}`;
            });

            // 2. Texte inline entre deux balises, sur la même ligne.
            ligne = ligne.replace(/>([^<>{}\n]+)</g, (tout, texte) => {
                if (!traduisible(texte)) return tout;
                const avant = texte.match(/^\s*/)[0];
                const apres = texte.match(/\s*$/)[0];
                collectees.add(texte.trim());
                return `>${avant}{t(${litteral(texte.trim())})}${apres}<`;
            });

            // 3. Nœud de texte seul sur sa ligne, entre une ouverture et une fermeture.
            const nu = ligne.trim();
            const precedente = (lignes[i - 1] ?? '').trimEnd();
            const suivante = (lignes[i + 1] ?? '').trim();
            const estTexteSeul =
                nu.length > 0 &&
                !/[<>{}=;()]/.test(nu) &&
                precedente.endsWith('>') &&
                (suivante.startsWith('</') || suivante.startsWith('{')) &&
                traduisible(nu);

            if (estTexteSeul) {
                collectees.add(nu);
                ligne = ligne.replace(nu, `{t(${litteral(nu)})}`);
            }
        }

        sortie.push(ligne);
    }

    return sortie.join('\n');
}

/** Ajoute l'import et la déclaration de `t` si le fichier en a besoin. */
function brancherLeHook(source) {
    if (!source.includes('t(') || source.includes("from '@/lib/langue'")) {
        return source;
    }

    // L'import se glisse après le dernier import existant.
    const imports = [...source.matchAll(/^import .*;$/gm)];
    const dernier = imports[imports.length - 1];
    let resultat =
        source.slice(0, dernier.index + dernier[0].length) +
        "\nimport { useT } from '@/lib/langue';" +
        source.slice(dernier.index + dernier[0].length);

    // `const t = useT();` en tête du composant exporté par défaut.
    const composant = resultat.match(/export default function \w+\([^)]*\)(: [\w.<>[\] |]+)? \{\n/);
    if (composant) {
        const position = composant.index + composant[0].length;
        resultat = resultat.slice(0, position) + '    const t = useT();\n' + resultat.slice(position);
    }

    return resultat;
}

for (const fichier of process.argv.slice(2)) {
    const source = readFileSync(fichier, 'utf8');
    const transforme = brancherLeHook(transformer(source));
    if (transforme !== source) {
        writeFileSync(fichier, transforme);
        console.log('modifié : ' + fichier);
    }
}

console.log('\n--- chaînes collectées (' + collectees.size + ') ---');
console.log(JSON.stringify([...collectees].sort(), null, 0));
