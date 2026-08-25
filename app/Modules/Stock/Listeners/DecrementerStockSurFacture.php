<?php

namespace App\Modules\Stock\Listeners;

use App\Modules\Stock\Services\StockService;
use App\Modules\Ventes\Events\FactureValidee;

/**
 * Le module Ventes ne connaît pas le module Stock : il émet FactureValidee,
 * et c'est ici que le stock réagit. Même patron pour la Compta en phase 5.
 *
 * La règle « le stock bouge une seule fois » n'est PLUS appliquée ici. Ce
 * garde-fou ne regardait que la source DIRECTE de la facture : il laissait donc
 * passer le cas — le plus courant — où le bon de livraison et la facture sont
 * deux FRÈRES issus d'une même commande. C'est désormais StockService qui
 * déduit, pour toute la famille de documents, ce qui est déjà parti.
 */
class DecrementerStockSurFacture
{
    public function __construct(private StockService $stock) {}

    public function handle(FactureValidee $event): void
    {
        $this->stock->sortieVente($event->document);
    }
}
