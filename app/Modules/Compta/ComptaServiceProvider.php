<?php

namespace App\Modules\Compta;

use App\Modules\Compta\Listeners\GenererEcritureEncaissement;
use App\Modules\Compta\Listeners\GenererEcritureVente;
use App\Modules\Ventes\Events\FactureValidee;
use App\Modules\Ventes\Events\PaiementEnregistre;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ComptaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');

        // Branchements inter-modules : la compta réagit aux événements Ventes.
        Event::listen(FactureValidee::class, GenererEcritureVente::class);
        Event::listen(PaiementEnregistre::class, GenererEcritureEncaissement::class);
    }
}
