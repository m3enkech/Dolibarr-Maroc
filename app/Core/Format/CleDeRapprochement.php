<?php

namespace App\Core\Format;

/**
 * Les clés qui servent à reconnaître deux fois le même tiers ou le même article
 * lors d'une reprise de données.
 *
 * Mises en commun parce que la même logique, recopiée dans trois imports, y a
 * été recopiée avec le même défaut — et qu'il était grave.
 *
 * LE DÉFAUT : `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', 'شركة الأمل')` rend une
 * chaîne VIDE. Le filtre qui suivait n'y changeait rien, et tout nom sans
 * équivalent ASCII — arabe, mais aussi cyrillique ou chinois — retombait donc
 * sur la clé « ». Dans un index construit par `keyBy`, cela veut dire que TOUS
 * ces tiers se ramassent dans une seule case et que le dernier écrase les
 * autres : la facture d'une société arabe est alors attribuée en silence à une
 * société sans rapport. Sur une application qui s'affiche en arabe et en
 * français, ce n'est pas un cas de bord.
 *
 * La parade : quand la translittération ne rend rien, on retombe sur une
 * normalisation qui PRÉSERVE l'Unicode. Deux noms arabes identiques se
 * rapprochent alors l'un de l'autre, et deux noms arabes différents restent
 * distincts — ce qui est exactement ce qu'on attend d'une clé.
 */
class CleDeRapprochement
{
    /**
     * Casse, accents, espaces et ponctuation neutralisés.
     *
     * Rend une chaîne vide UNIQUEMENT si le nom lui-même ne porte aucun
     * caractère significatif ; l'appelant doit alors s'abstenir d'indexer.
     */
    public static function nom(?string $nom): string
    {
        $brut = trim((string) $nom);

        if ($brut === '') {
            return '';
        }

        $sansAccent = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $brut) ?: '';
        $ascii = preg_replace('/[^a-z0-9]+/', '', mb_strtolower($sansAccent)) ?? '';

        if ($ascii !== '') {
            return $ascii;
        }

        // Rien ne s'est translittéré : on garde l'original, sans casse ni
        // ponctuation ni espaces. `\p{L}` et `\p{N}` sous le drapeau `u`
        // couvrent l'arabe comme le reste.
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($brut)) ?? '';
    }

    /**
     * L'ICE se compare sur ses chiffres seuls : espaces et tirets varient d'un
     * système à l'autre.
     */
    public static function chiffres(?string $valeur): string
    {
        return preg_replace('/\D+/', '', (string) $valeur) ?? '';
    }
}
