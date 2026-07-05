<?php

namespace App\Modules\Stock\Listeners;

use App\Modules\Achats\Events\ReceptionValidee;
use App\Modules\Stock\Services\StockService;

class EntreeStockSurReception
{
    public function __construct(private StockService $stock) {}

    public function handle(ReceptionValidee $event): void
    {
        $this->stock->entreeAchat($event->document);
    }
}
