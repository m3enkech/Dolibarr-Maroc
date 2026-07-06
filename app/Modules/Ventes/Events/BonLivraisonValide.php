<?php

namespace App\Modules\Ventes\Events;

use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un bon de livraison est validé : la marchandise part, le stock sort. La
 * facture émise ensuite depuis ce BL ne resortira pas le stock (une seule fois).
 * Un BL ne génère aucune écriture comptable (seule la facture le fait).
 */
class BonLivraisonValide
{
    use Dispatchable, SerializesModels;

    public function __construct(public DocumentVente $document) {}
}
