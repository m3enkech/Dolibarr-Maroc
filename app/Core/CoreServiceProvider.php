<?php

namespace App\Core;

use App\Core\Auth\SetSuperadminCommand;
use App\Core\Console\DemoSeedCommand;
use App\Core\Http\LimitesDeDebit;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "scoped" : une instance par requête, jamais partagée entre deux requêtes.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Déclarés au démarrage, jamais sérialisés : `route:cache` (joué au
        // lancement du conteneur) ne fige que les routes, pas ces fermetures.
        LimitesDeDebit::definir();

        if ($this->app->runningInConsole()) {
            $this->commands([SetSuperadminCommand::class, DemoSeedCommand::class]);
        }
    }
}
