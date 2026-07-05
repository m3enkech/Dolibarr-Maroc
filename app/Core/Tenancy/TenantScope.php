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

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
        }
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
