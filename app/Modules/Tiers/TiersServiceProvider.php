<?php

namespace App\Modules\Tiers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TiersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
