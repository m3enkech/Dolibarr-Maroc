<?php

namespace App\Modules\Stock\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalogue\Models\Produit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['produit_id', 'entrepot_id', 'quantite'])]
class Stock extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function entrepot(): BelongsTo
    {
        return $this->belongsTo(Entrepot::class);
    }
}
