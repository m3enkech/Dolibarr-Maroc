<?php

namespace App\Modules\Compta\Listeners;

use App\Modules\Achats\Events\FactureAchatValidee;
use App\Modules\Compta\Services\ImmobilisationService;

/**
 * À la validation d'une facture fournisseur, crée les immobilisations pour
 * les lignes dont le produit relève d'une catégorie « immobilisation ».
 */
class CreerImmobilisationsSurAchat
{
    public function __construct(private ImmobilisationService $immobilisations) {}

    public function handle(FactureAchatValidee $event): void
    {
        $this->immobilisations->creerDepuisFactureAchat($event->document);
    }
}
