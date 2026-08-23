<?php

namespace App\Modules\Catalogue\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Niveau de tarif appliqué à un groupe de clients (gros, demi-gros, détail…). */
#[Fillable(['name', 'description', 'is_default'])]
class CategorieTarifaire extends Model
{
    use BelongsToTenant;

    protected $table = 'categories_tarifaires';

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function tarifs(): HasMany
    {
        return $this->hasMany(ProduitTarif::class);
    }
}
