<?php

namespace App\Modules\Stock\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalogue\Models\Produit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inventaire_id', 'produit_id', 'quantite_theorique', 'quantite_comptee'])]
class InventaireLigne extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantite_theorique' => 'decimal:3',
            'quantite_comptee' => 'decimal:3',
        ];
    }

    public function inventaire(): BelongsTo
    {
        return $this->belongsTo(Inventaire::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** Écart = comptée − théorique (null tant que la ligne n'est pas comptée). */
    public function ecart(): ?float
    {
        if ($this->quantite_comptee === null) {
            return null;
        }

        return round((float) $this->quantite_comptee - (float) $this->quantite_theorique, 3);
    }
}
