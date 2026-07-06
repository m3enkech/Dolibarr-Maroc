<?php

namespace App\Models;

use App\Core\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation d'un collaborateur à rejoindre un tenant existant.
 *
 * N'utilise PAS le scope tenant : l'acceptation se fait par un utilisateur non
 * authentifié (via le token), donc hors contexte tenant. Le rattachement au
 * tenant se fait par tenant_id (renseigné à la création via la relation).
 */
#[Fillable(['tenant_id', 'email', 'role', 'token', 'invited_by', 'expires_at', 'accepted_at'])]
class Invitation extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }
}
