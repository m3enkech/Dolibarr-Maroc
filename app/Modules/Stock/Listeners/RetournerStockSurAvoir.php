<?php

namespace App\Modules\Stock\Listeners;

use App\Modules\Stock\Services\StockService;
use App\Modules\Ventes\Events\AvoirValide;

/** Un avoir validé réintègre la marchandise (composants de kits compris). */
class RetournerStockSurAvoir
{
    public function __construct(private StockService $stock) {}

    public function handle(AvoirValide $event): void
    {
        $this->stock->retourVente($event->document);
    }
}
