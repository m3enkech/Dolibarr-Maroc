<?php

namespace App\Modules\Equipe;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EquipeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Les middlewares d'auth sont définis par groupe dans routes.php : la
        // gestion d'équipe est protégée, l'acceptation d'invitation est publique.
        Route::middleware('api')
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
