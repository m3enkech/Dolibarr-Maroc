<?php

namespace App\Modules\Stock;

use App\Modules\Stock\Listeners\DecrementerStockSurFacture;
use App\Modules\Ventes\Events\FactureValidee;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class StockServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');

        // Branchement inter-modules : le stock réagit aux factures validées.
        Event::listen(FactureValidee::class, DecrementerStockSurFacture::class);
    }
}
