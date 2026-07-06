<?php

namespace App\Modules\Pos\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'code', 'statut', 'fond_caisse', 'montant_compte',
    'ecart', 'note', 'opened_at', 'closed_at',
])]
class PosSession extends Model
{
    use BelongsToTenant;

    public const STATUT_OUVERTE = 'ouverte';
    public const STATUT_FERMEE = 'fermee';

    protected function casts(): array
    {
        return [
            'fond_caisse' => 'decimal:2',
            'montant_compte' => 'decimal:2',
            'ecart' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(DocumentVente::class, 'pos_session_id');
    }

    public function isOuverte(): bool
    {
        return $this->statut === self::STATUT_OUVERTE;
    }
}
