<?php

namespace App\Modules\Compta\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'label', 'classe', 'is_system', 'is_active'])]
class Compte extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'classe' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function lignesViaEcriture(): HasMany
    {
        return $this->hasMany(EcritureLigne::class);
    }
}
