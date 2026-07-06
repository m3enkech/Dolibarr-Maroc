<?php

namespace App\Modules\Effets;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EffetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:effets'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
