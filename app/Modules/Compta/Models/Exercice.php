<?php

namespace App\Modules\Compta\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['annee', 'resultat', 'ecriture_resultat_id', 'ecriture_an_id', 'cloture_at'])]
class Exercice extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'annee' => 'integer',
            'resultat' => 'decimal:2',
            'cloture_at' => 'datetime',
        ];
    }

    public function ecritureResultat(): BelongsTo
    {
        return $this->belongsTo(Ecriture::class, 'ecriture_resultat_id');
    }

    public function ecritureAn(): BelongsTo
    {
        return $this->belongsTo(Ecriture::class, 'ecriture_an_id');
    }
}
