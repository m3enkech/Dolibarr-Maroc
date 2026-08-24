<?php

namespace App\Modules\Portail;

use App\Modules\Portail\Http\Middleware\SetTenantPortail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Portail acheteur. Trois familles de routes, volontairement séparées :
 *
 *  1. publiques            — inscription, connexion, annuaire des grossistes ;
 *  2. acheteur HORS tenant — « mes grossistes », demande d'accès ;
 *  3. acheteur AVEC tenant — tout ce qui touche aux données d'un grossiste,
 *     derrière SetTenantPortail qui vérifie le rattachement et pose le contexte.
 *
 * Le guard `acheteur` est distinct de `sanctum` : un jeton d'acheteur ne peut
 * pas authentifier une route de l'ERP, et réciproquement.
 */
class PortailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 1. Public
        Route::middleware('api')
            ->prefix('api/portail/v1')
            ->group(__DIR__.'/routes-public.php');

        // 2. Acheteur authentifié, sans entreprise courante
        Route::middleware(['api', 'auth:acheteur'])
            ->prefix('api/portail/v1')
            ->group(__DIR__.'/routes-acheteur.php');

        // 3. Acheteur authentifié, dans le périmètre d'un grossiste
        Route::middleware(['api', 'auth:acheteur', SetTenantPortail::class])
            ->prefix('api/portail/v1/grossistes/{grossiste}')
            ->group(__DIR__.'/routes-grossiste.php');

        // 4. Côté ERP : le grossiste gère les demandes d'accès
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:tiers'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes-erp.php');
    }
}
