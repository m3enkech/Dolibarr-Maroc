<?php

namespace App\Modules\Stock;

use App\Modules\Achats\Events\FactureAchatValidee;
use App\Modules\Achats\Events\ReceptionValidee;
use App\Modules\Stock\Listeners\DecrementerStockSurBonLivraison;
use App\Modules\Stock\Listeners\DecrementerStockSurFacture;
use App\Modules\Stock\Listeners\EntreeStockSurFactureDirecte;
use App\Modules\Stock\Listeners\EntreeStockSurReception;
use App\Modules\Stock\Listeners\RetournerStockSurAvoir;
use App\Modules\Ventes\Events\AvoirValide;
use App\Modules\Ventes\Events\BonLivraisonValide;
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

        // Branchements inter-modules : le stock réagit aux ventes et aux achats.
        Event::listen(FactureValidee::class, DecrementerStockSurFacture::class);
        Event::listen(BonLivraisonValide::class, DecrementerStockSurBonLivraison::class);
        Event::listen(AvoirValide::class, RetournerStockSurAvoir::class);
        Event::listen(ReceptionValidee::class, EntreeStockSurReception::class);
        Event::listen(FactureAchatValidee::class, EntreeStockSurFactureDirecte::class);
    }
}
