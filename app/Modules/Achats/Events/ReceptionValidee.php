<?php

namespace App\Modules\Achats\Events;

use App\Modules\Achats\Models\DocumentAchat;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Écouté par le module Stock : c'est la réception qui fait entrer
 * la marchandise, jamais la facture (sauf facture directe sans source).
 */
class ReceptionValidee
{
    use Dispatchable, SerializesModels;

    public function __construct(public DocumentAchat $document) {}
}
