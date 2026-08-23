<?php

namespace App\Modules\Catalogue\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prix d'un article pour un client ou une catégorie tarifaire, à partir d'une quantité. */
#[Fillable(['produit_id', 'categorie_tarifaire_id', 'tiers_id', 'quantite_min', 'prix'])]
class ProduitTarif extends Model
{
    use BelongsToTenant;

    protected $table = 'produit_tarifs';

    protected function casts(): array
    {
        return [
            'quantite_min' => 'decimal:3',
            'prix' => 'decimal:2',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function categorieTarifaire(): BelongsTo
    {
        return $this->belongsTo(CategorieTarifaire::class);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }
}
