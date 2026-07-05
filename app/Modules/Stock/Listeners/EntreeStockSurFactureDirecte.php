<?php

namespace App\Modules\Stock\Listeners;

use App\Modules\Achats\Events\FactureAchatValidee;
use App\Modules\Stock\Services\StockService;

/**
 * Règle « le stock bouge une seule fois » : une facture fournisseur ne fait
 * entrer la marchandise QUE si elle n'est issue d'aucun document source.
 * Issue d'une réception : le stock est déjà entré. Issue d'une commande :
 * c'est la réception qui fera l'entrée.
 */
class EntreeStockSurFactureDirecte
{
    public function __construct(private StockService $stock) {}

    public function handle(FactureAchatValidee $event): void
    {
        if ($event->document->source_document_id !== null) {
            return;
        }

        $this->stock->entreeAchat($event->document);
    }
}
