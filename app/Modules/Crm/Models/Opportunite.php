<?php

namespace App\Modules\Crm\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tiers_id', 'user_id', 'code', 'titre', 'montant_estime', 'probabilite',
    'etape', 'statut', 'position', 'date_cloture_prevue', 'note', 'close_at',
])]
class Opportunite extends Model
{
    use BelongsToTenant;

    protected $table = 'opportunites';

    /** Étapes du pipeline (colonnes du kanban), dans l'ordre. */
    public const ETAPES = ['nouveau', 'qualifie', 'proposition', 'negociation'];

    public const STATUT_OUVERTE = 'ouverte';
    public const STATUT_GAGNEE = 'gagnee';
    public const STATUT_PERDUE = 'perdue';

    protected function casts(): array
    {
        return [
            'montant_estime' => 'decimal:2',
            'probabilite' => 'integer',
            'position' => 'integer',
            'date_cloture_prevue' => 'date:Y-m-d',
            'close_at' => 'datetime',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOuverte(): bool
    {
        return $this->statut === self::STATUT_OUVERTE;
    }
}
