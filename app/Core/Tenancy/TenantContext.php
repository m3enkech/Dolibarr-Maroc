<?php

namespace App\Core\Tenancy;

use Closure;

/**
 * Détient le tenant courant pour la durée de la requête.
 * Enregistré en singleton "scoped" — remis à zéro entre deux requêtes (Octane safe).
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    /**
     * Autorisation EXPLICITE de lire au travers des entreprises. Sans elle, une
     * requête sans tenant courant ne renvoie rien (voir TenantScope).
     */
    private bool $global = false;

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

    public function clear(): void
    {
        $this->tenant = null;
    }

    /**
     * Exécute $fn dans le contexte d'un autre tenant, puis RESTAURE toujours
     * l'état précédent — y compris si $fn lève. À préférer systématiquement au
     * couple set()/set() manuel, qui laisse le contexte corrompu en cas d'erreur.
     */
    public function runAs(?Tenant $tenant, Closure $fn): mixed
    {
        $precedent = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $fn();
        } finally {
            $this->tenant = $precedent;
        }
    }

    /**
     * Lecture volontairement inter-entreprises (console d'administration,
     * vitrine publique de la place de marché). L'autorisation ne survit pas au
     * bloc : impossible de l'oublier ouverte.
     */
    public function runGlobal(Closure $fn): mixed
    {
        $avant = $this->global;
        $this->global = true;

        try {
            return $fn();
        } finally {
            $this->global = $avant;
        }
    }

    public static function globalAllowed(): bool
    {
        return app(self::class)->global;
    }
}
