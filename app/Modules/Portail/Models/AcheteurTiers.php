<?php

namespace App\Modules\Portail\Models;

use App\Core\Tenancy\Tenant;
use App\Models\User;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rattachement d'un acheteur à un grossiste, et au compte client (Tiers) qui le
 * représente chez lui.
 *
 * Table traversante : PAS de BelongsToTenant. Toute requête dessus doit porter
 * un `where('tenant_id', …)` explicite — le scope global ne la protège pas.
 */
#[Fillable([
    'acheteur_id', 'tenant_id', 'tiers_id', 'statut',
    'demande_at', 'approuve_at', 'approuve_par', 'revoque_at',
])]
class AcheteurTiers extends Model
{
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_APPROUVE = 'approuve';
    public const STATUT_REFUSE = 'refuse';
    public const STATUT_REVOQUE = 'revoque';

    protected $table = 'acheteur_tiers';

    protected function casts(): array
    {
        return [
            'demande_at' => 'datetime',
            'approuve_at' => 'datetime',
            'revoque_at' => 'datetime',
        ];
    }

    public function acheteur(): BelongsTo
    {
        return $this->belongsTo(Acheteur::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function approuvePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approuve_par');
    }

    public function estApprouve(): bool
    {
        return $this->statut === self::STATUT_APPROUVE;
    }
}
