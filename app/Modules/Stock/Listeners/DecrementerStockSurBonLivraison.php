<?php

namespace App\Modules\Stock\Listeners;

use App\Modules\Stock\Services\StockService;
use App\Modules\Ventes\Events\BonLivraisonValide;

/** La marchandise part à la validation du bon de livraison : le stock sort. */
class DecrementerStockSurBonLivraison
{
    public function __construct(private StockService $stock) {}

    public function handle(BonLivraisonValide $event): void
    {
        $this->stock->sortieVente($event->document);
    }
}
