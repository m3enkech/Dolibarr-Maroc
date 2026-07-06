<?php

namespace App\Modules\Ventes\Events;

use App\Modules\Ventes\Models\DocumentVente;

/** Un avoir vient d'être validé : la compta contrepasse, le stock réintègre. */
class AvoirValide
{
    public function __construct(public DocumentVente $document) {}
}
