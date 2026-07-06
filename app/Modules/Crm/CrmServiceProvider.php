<?php

namespace App\Modules\Crm;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:crm'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
