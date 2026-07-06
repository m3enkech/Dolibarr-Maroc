<?php

namespace App\Modules\Crm\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tiers_id', 'opportunite_id', 'user_id', 'type', 'sujet', 'note',
    'date_prevue', 'fait', 'fait_at',
])]
class Activite extends Model
{
    use BelongsToTenant;

    public const TYPES = ['appel', 'email', 'reunion', 'note', 'tache'];

    protected function casts(): array
    {
        return [
            'date_prevue' => 'date:Y-m-d',
            'fait' => 'boolean',
            'fait_at' => 'datetime',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function opportunite(): BelongsTo
    {
        return $this->belongsTo(Opportunite::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
