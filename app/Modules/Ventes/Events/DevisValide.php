<?php

namespace App\Modules\Ventes\Events;

use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DevisValide
{
    use Dispatchable, SerializesModels;

    public function __construct(public DocumentVente $document) {}
}
