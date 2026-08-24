<?php

namespace App\Modules\Portail\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Acheteur du portail : un épicier qui commande chez un ou plusieurs grossistes.
 *
 * N'appartient à AUCUNE entreprise (ni tenant_id, ni BelongsToTenant) : il ne
 * consomme aucun siège facturable et n'a aucun accès à l'ERP. Son périmètre est
 * défini uniquement par ses rattachements approuvés (voir AcheteurTiers).
 */
#[Fillable(['email', 'name', 'password', 'phone', 'is_active'])]
class Acheteur extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'acheteurs';

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'derniere_connexion_at' => 'datetime',
        ];
    }

    public function rattachements(): HasMany
    {
        return $this->hasMany(AcheteurTiers::class);
    }

    /** Rattachement approuvé chez ce grossiste, ou null. */
    public function rattachementApprouve(int $tenantId): ?AcheteurTiers
    {
        return $this->rattachements()
            ->where('tenant_id', $tenantId)
            ->where('statut', AcheteurTiers::STATUT_APPROUVE)
            ->first();
    }
}
