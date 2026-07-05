<?php

namespace App\Modules\Achats\Events;

use App\Modules\Achats\Models\DocumentAchat;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Écouté par le module Compta (écriture AC) et par le module Stock
 * (entrée implicite uniquement si la facture n'a pas de document source).
 */
class FactureAchatValidee
{
    use Dispatchable, SerializesModels;

    public function __construct(public DocumentAchat $document) {}
}
