<?php

namespace App\Modules\Ventes\Events;

use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Écouté (phase 4) par le module Stock pour décrémenter les quantités,
 * et (phase 5) par le module Comptabilité pour générer l'écriture de vente.
 */
class FactureValidee
{
    use Dispatchable, SerializesModels;

    public function __construct(public DocumentVente $document) {}
}
