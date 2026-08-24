<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = static::currentTenantId();

        if ($tenantId === null) {
            // FAIL-CLOSED : sans tenant courant, on ne renvoie RIEN plutôt que
            // TOUT. Le portail acheteur sert des requêtes sans utilisateur
            // d'entreprise : la moindre erreur de câblage exposerait sinon les
            // données de tous les grossistes.
            // Les lectures légitimement inter-entreprises passent explicitement
            // par TenantContext::runGlobal() ou withoutGlobalScopes().
            if (! TenantContext::globalAllowed()) {
                $builder->whereRaw('1 = 0');
            }

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * Le contexte est la source de vérité, mais le route model binding
     * s'exécute avant le middleware "tenant" : on se rabat alors sur
     * l'utilisateur authentifié pour ne jamais requêter sans filtre.
     */
    public static function currentTenantId(): ?int
    {
        $context = app(TenantContext::class);

        if ($context->check()) {
            return $context->id();
        }

        return auth()->user()?->tenant_id;
    }
}
