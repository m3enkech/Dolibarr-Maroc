<?php

namespace App\Modules\Achats\Events;

use App\Modules\Achats\Models\DocumentAchat;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommandeFournisseurValidee
{
    use Dispatchable, SerializesModels;

    public function __construct(public DocumentAchat $document) {}
}
