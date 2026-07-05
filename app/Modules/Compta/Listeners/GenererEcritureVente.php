<?php

namespace App\Modules\Compta\Listeners;

use App\Modules\Compta\Services\ComptaService;
use App\Modules\Ventes\Events\FactureValidee;

class GenererEcritureVente
{
    public function __construct(private ComptaService $compta) {}

    public function handle(FactureValidee $event): void
    {
        $this->compta->ecrireVente($event->document);
    }
}
