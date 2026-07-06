<?php

namespace App\Models;

use App\Core\Auth\Roles;
use App\Core\Tenancy\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['tenant_id', 'name', 'email', 'password', 'role', 'is_superadmin', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Roles::ADMIN;
    }

    /** Superadmin plateforme : habilité aux opérations sensibles hors compte entreprise. */
    public function isSuperadmin(): bool
    {
        return (bool) $this->is_superadmin;
    }

    /**
     * Droit d'accès sur un domaine (module). L'action vaut 'read' ou 'write'
     * ('write' implique 'read'). Le superadmin passe partout.
     */
    public function hasPermission(string $domaine, string $action = Roles::READ): bool
    {
        if ($this->isSuperadmin()) {
            return true;
        }

        $niveau = Roles::niveau($this->role, $domaine);

        if ($action === Roles::WRITE) {
            return $niveau === Roles::WRITE;
        }

        return $niveau === Roles::READ || $niveau === Roles::WRITE;
    }

    /** Matrice des droits (domaine → niveau) transmise au frontend. */
    public function permissionsMap(): array
    {
        return Roles::map($this->role);
    }
}
