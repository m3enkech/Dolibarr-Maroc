<?php

namespace App\Modules\Stock\Services;

use App\Modules\Ventes\Models\DocumentVente;

/**
 * Ce qu'une famille de documents DOIT avoir fait sortir du stock, rejoué pièce
 * par pièce dans l'ordre des validations.
 *
 * Par article, deux cumuls :
 * - le LIVRÉ : les bons de livraison s'additionnent entre eux. Deux BL frères
 *   sont deux départs de marchandise, pas deux fois le même — c'est ce que
 *   l'ancien « crédit déjà sorti » ignorait (BL de 6 puis BL de 4 : le second ne
 *   sortait rien) ;
 * - le FACTURÉ : les factures s'additionnent entre elles, de même.
 * La famille doit avoir sorti le plus grand des deux : une facture ne fait
 * sortir que ce qu'aucun BL n'a livré, un BL que ce qu'aucune facture n'a déjà
 * fait partir.
 *
 * Un avoir fait toujours rentrer sa marchandise (règle préexistante, voir
 * StockService::retourVente) : ce qui est dehors baisse exactement de sa
 * quantité. Il la retire du facturé, et l'impute d'abord sur le LIVRÉ — ce qui
 * rentre au dépôt est ce qui en était parti. Après « BL 6, facture 10, avoir
 * 2 », le client détient 4 sacs et en attend 4 facturés : la livraison du
 * reliquat est couverte par la facture et ne sort rien. Imputé d'abord sur le
 * facturé non livré, l'avoir laissait le livré à 6 et le reliquat ressortait
 * 2 sacs, sans rattrapage possible puisque tout est déjà facturé. Le seul cas
 * que cette lecture trompe — l'avoir annulait le reliquat, qu'on livre quand
 * même — laisse le stock trop haut jusqu'à la refacturation, qui le rattrape.
 * Un avoir total ramène tout à zéro : une livraison de remplacement ressort.
 *
 * L'ORDRE compte, et c'est pourquoi on rejoue au lieu de sommer : « facture,
 * avoir, puis livraison » (facture annulée avant le départ, puis livraison)
 * doit sortir 10, « facture, livraison, puis avoir » (marchandise rendue) doit
 * laisser 0 dehors — mêmes pièces, mêmes quantités, deux vérités. C'est le seul
 * couple qui ne commute pas (BL/avoir) ; l'ordre est celui de la prise du
 * verrou de famille, voir FamilleDocuments::piecesDuBilan.
 */
final class BilanDeSortie
{
    /** @var array<int, float> livré net, par produit */
    private array $livre = [];

    /** @var array<int, float> facturé net, par produit */
    private array $facture = [];

    /**
     * Ajoute une pièce validée au bilan.
     *
     * @param  array<int, float>  $quantites  quantités de stock portées, par produit
     * @return array<int, float> ce que la pièce doit faire sortir, par produit
     *                           (toujours ≥ 0 ; vide pour un avoir, qui ne sort rien)
     */
    public function appliquer(string $type, array $quantites): array
    {
        $dues = [];

        foreach ($quantites as $produitId => $quantite) {
            $livre = $this->livre[$produitId] ?? 0.0;
            $facture = $this->facture[$produitId] ?? 0.0;
            $dehors = max($livre, $facture);

            if ($type === DocumentVente::TYPE_AVOIR) {
                $facture -= $quantite;
                // Borné par ce qui reste dehors : le nouveau maximum des deux
                // cumuls vaut alors exactement dehors − quantité.
                $livre = min(max(0.0, $livre - $quantite), $dehors - $quantite);
            } elseif ($type === DocumentVente::TYPE_BON_LIVRAISON) {
                $livre += $quantite;
            } else {
                // Facture — y compris le ticket de caisse et la pièce reprise,
                // qui sont des factures.
                $facture += $quantite;
            }

            $this->livre[$produitId] = round($livre, 3);
            $this->facture[$produitId] = round($facture, 3);

            if ($type !== DocumentVente::TYPE_AVOIR) {
                // Livré et facturé ne font que croître ici : l'écart est ≥ 0.
                $dues[$produitId] = round(max($this->livre[$produitId], $this->facture[$produitId]) - $dehors, 3);
            }
        }

        return $dues;
    }
}
