<?php

namespace App\Modules\Compta\Listeners;

use App\Modules\Achats\Events\FactureAchatValidee;
use App\Modules\Compta\Services\ComptaService;

class GenererEcritureAchat
{
    public function __construct(private ComptaService $compta) {}

    public function handle(FactureAchatValidee $event): void
    {
        $this->compta->ecrireAchat($event->document);
    }
}
