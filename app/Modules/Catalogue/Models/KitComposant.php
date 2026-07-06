<?php

namespace App\Modules\Catalogue\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kit_id', 'composant_id', 'quantite'])]
class KitComposant extends Model
{
    use BelongsToTenant;

    protected $table = 'produit_kit_composants';

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
        ];
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'kit_id');
    }

    public function composant(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'composant_id');
    }
}
