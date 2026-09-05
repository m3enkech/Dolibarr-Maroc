<?php

namespace App\Modules\Pilotage;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Écran de suivi : le seul point de l'API qui croise plusieurs domaines en
 * lecture.
 *
 * Même pile que le tableau de bord, et pour la même raison : `permission:` est
 * un ET logique, donc exiger `ventes` ET `achats` fermerait la page à la moitié
 * des rôles. La garde vit dans le service, qui omet les blocs interdits.
 */
class PilotageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes.php');
    }
}
