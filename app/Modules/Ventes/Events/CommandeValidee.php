<?php

namespace App\Modules\Ventes\Events;

use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Écouté (phase 4) par le module Stock pour réserver la marchandise.
 */
class CommandeValidee
{
    use Dispatchable, SerializesModels;

    public function __construct(public DocumentVente $document) {}
}
