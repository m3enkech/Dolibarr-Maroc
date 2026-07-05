<?php

namespace App\Modules\Compta\Listeners;

use App\Modules\Compta\Services\ComptaService;
use App\Modules\Ventes\Events\PaiementEnregistre;

class GenererEcritureEncaissement
{
    public function __construct(private ComptaService $compta) {}

    public function handle(PaiementEnregistre $event): void
    {
        $this->compta->ecrireEncaissement($event->paiement, $event->document);
    }
}
