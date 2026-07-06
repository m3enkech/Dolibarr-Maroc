<?php

namespace App\Modules\Stock\Listeners;

use App\Modules\Stock\Services\StockService;
use App\Modules\Ventes\Events\FactureValidee;
use App\Modules\Ventes\Models\DocumentVente;

/**
 * Le module Ventes ne connaît pas le module Stock : il émet FactureValidee,
 * et c'est ici que le stock réagit. Même patron pour la Compta en phase 5.
 *
 * Règle « le stock bouge une seule fois » : si la facture provient d'un bon
 * de livraison, la marchandise est déjà sortie à la livraison — on ne la
 * ressort pas ici.
 */
class DecrementerStockSurFacture
{
    public function __construct(private StockService $stock) {}

    public function handle(FactureValidee $event): void
    {
        $source = $event->document->source;

        if ($source && $source->type === DocumentVente::TYPE_BON_LIVRAISON) {
            return;
        }

        $this->stock->sortieVente($event->document);
    }
}
