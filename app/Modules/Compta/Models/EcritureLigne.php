<?php

namespace App\Modules\Compta\Models;

use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'écriture : accédée uniquement via son écriture parente
 * (toujours filtrer par whereHas('ecriture') pour le scope tenant).
 */
#[Fillable(['compte_id', 'tiers_id', 'libelle', 'debit', 'credit', 'lettrage'])]
class EcritureLigne extends Model
{
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function ecriture(): BelongsTo
    {
        return $this->belongsTo(Ecriture::class);
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }
}
