<?php

namespace App\Modules\Ventes\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_vente_id', 'date_paiement', 'montant', 'mode', 'reference', 'note'])]
class Paiement extends Model
{
    use BelongsToTenant;

    public const MODES = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    protected function casts(): array
    {
        return [
            'date_paiement' => 'date:Y-m-d',
            'montant' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentVente::class, 'document_vente_id');
    }
}
