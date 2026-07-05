<?php

namespace App\Modules\Compta;

use App\Modules\Achats\Events\FactureAchatValidee;
use App\Modules\Achats\Events\PaiementFournisseurEnregistre;
use App\Modules\Compta\Listeners\CreerImmobilisationsSurAchat;
use App\Modules\Compta\Listeners\GenererEcritureAchat;
use App\Modules\Compta\Listeners\GenererEcritureDecaissement;
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

        // Branchements inter-modules : la compta réagit aux Ventes et aux Achats.
        Event::listen(FactureValidee::class, GenererEcritureVente::class);
        Event::listen(PaiementEnregistre::class, GenererEcritureEncaissement::class);
        Event::listen(FactureAchatValidee::class, GenererEcritureAchat::class);
        Event::listen(FactureAchatValidee::class, CreerImmobilisationsSurAchat::class);
        Event::listen(PaiementFournisseurEnregistre::class, GenererEcritureDecaissement::class);
    }
}
