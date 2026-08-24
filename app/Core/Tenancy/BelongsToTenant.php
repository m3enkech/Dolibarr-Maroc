<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * À utiliser sur tout modèle métier : filtre automatiquement les requêtes
 * sur le tenant courant et renseigne tenant_id à la création.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            if (! empty($model->tenant_id)) {
                return;
            }

            $tenantId = TenantScope::currentTenantId();

            if ($tenantId === null) {
                // Écrire sans tenant produirait une ligne orpheline, invisible
                // de tous et impossible à rattacher. Mieux vaut une erreur
                // lisible qu'une violation de contrainte SQL plus loin.
                throw new \RuntimeException(
                    'Écriture sans entreprise courante : '.$model::class
                    .'. Posez le contexte (TenantContext::runAs) avant d\'écrire.',
                );
            }

            $model->tenant_id = $tenantId;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
