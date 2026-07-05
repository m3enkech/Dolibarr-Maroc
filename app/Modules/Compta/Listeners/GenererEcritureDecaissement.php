<?php

namespace App\Modules\Compta\Listeners;

use App\Modules\Achats\Events\PaiementFournisseurEnregistre;
use App\Modules\Compta\Services\ComptaService;

class GenererEcritureDecaissement
{
    public function __construct(private ComptaService $compta) {}

    public function handle(PaiementFournisseurEnregistre $event): void
    {
        $this->compta->ecrireDecaissement($event->paiement, $event->document);
    }
}
