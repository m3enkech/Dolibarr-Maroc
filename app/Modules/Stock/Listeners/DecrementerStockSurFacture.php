<?php

namespace App\Modules\Stock\Listeners;

use App\Modules\Stock\Services\StockService;
use App\Modules\Ventes\Events\FactureValidee;

/**
 * Le module Ventes ne connaît pas le module Stock : il émet FactureValidee,
 * et c'est ici que le stock réagit. Même patron pour la Compta en phase 5.
 */
class DecrementerStockSurFacture
{
    public function __construct(private StockService $stock) {}

    public function handle(FactureValidee $event): void
    {
        $this->stock->sortieVente($event->document);
    }
}
