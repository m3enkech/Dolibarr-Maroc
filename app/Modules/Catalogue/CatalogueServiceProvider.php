<?php

namespace App\Modules\Catalogue;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CatalogueServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:catalogue'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
