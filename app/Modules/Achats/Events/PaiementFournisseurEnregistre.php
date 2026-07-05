<?php

namespace App\Modules\Achats\Events;

use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\PaiementFournisseur;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Écouté par le module Compta pour l'écriture de décaissement.
 */
class PaiementFournisseurEnregistre
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PaiementFournisseur $paiement,
        public DocumentAchat $document,
    ) {}
}
