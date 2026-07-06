<?php

namespace App\Modules\Relances;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RelancesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:relances'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
