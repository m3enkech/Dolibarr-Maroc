<?php

namespace App\Modules\Compta\Listeners;

use App\Modules\Compta\Services\ComptaService;
use App\Modules\Ventes\Events\AvoirValide;

class GenererEcritureAvoir
{
    public function __construct(private ComptaService $compta) {}

    public function handle(AvoirValide $event): void
    {
        $this->compta->ecrireAvoir($event->document);
    }
}
