<?php

namespace App\Modules\Superadmin;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SuperadminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Pas de middleware 'tenant' : la console opère cross-tenant.
        Route::middleware(['api', 'auth:sanctum', 'superadmin'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
