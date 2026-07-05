<?php

namespace App\Core\Tenancy;

/**
 * Détient le tenant courant pour la durée de la requête.
 * Enregistré en singleton "scoped" — remis à zéro entre deux requêtes (Octane safe).
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }
}
