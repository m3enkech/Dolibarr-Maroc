<?php

namespace App\Modules\Compta\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['immobilisation_id', 'annee', 'montant', 'ecriture_id'])]
class ImmobilisationDotation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'annee' => 'integer',
            'montant' => 'decimal:2',
        ];
    }

    public function immobilisation(): BelongsTo
    {
        return $this->belongsTo(Immobilisation::class);
    }
}
