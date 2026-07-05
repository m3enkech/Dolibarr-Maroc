<?php

namespace App\Modules\Ventes\Events;

use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Écouté (phase 5) par le module Comptabilité pour générer
 * l'écriture d'encaissement.
 */
class PaiementEnregistre
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Paiement $paiement,
        public DocumentVente $document,
    ) {}
}
