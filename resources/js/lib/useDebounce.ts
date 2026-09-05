import { useEffect, useState } from 'react';

/**
 * Retarde la propagation d'une valeur.
 *
 * Les écrans de liste de l'ERP interrogent le serveur à CHAQUE frappe : taper
 * « bouteille » lance neuf requêtes, dont huit dont la réponse est jetée. C'est
 * resté sans conséquence tant que les listes étaient consultées ponctuellement.
 *
 * L'écran de suivi change la donne : il est fait pour rester ouvert, sa
 * recherche est le geste le plus fréquent, et chaque requête porte deux
 * sous-requêtes corrélées par ligne affichée. On retarde donc la valeur qui
 * entre dans la clé de requête — jamais celle du champ, qui doit rester
 * instantanée sous les doigts.
 */
export function useDebounce<T>(valeur: T, delaiMs = 250): T {
    const [retardee, setRetardee] = useState(valeur);

    useEffect(() => {
        const minuterie = setTimeout(() => setRetardee(valeur), delaiMs);

        return () => clearTimeout(minuterie);
    }, [valeur, delaiMs]);

    return retardee;
}
