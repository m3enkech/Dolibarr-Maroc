<?php

namespace App\Modules\Stock\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['entrepot_id', 'user_id', 'code', 'statut', 'note', 'validated_at'])]
class Inventaire extends Model
{
    use BelongsToTenant;

    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_VALIDE = 'valide';

    protected function casts(): array
    {
        return [
            'validated_at' => 'datetime',
        ];
    }

    public function entrepot(): BelongsTo
    {
        return $this->belongsTo(Entrepot::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(InventaireLigne::class);
    }
}
