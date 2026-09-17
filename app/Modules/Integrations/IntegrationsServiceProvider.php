<?php

namespace App\Modules\Integrations;

use App\Modules\Integrations\Console\ImporterTiersZohoCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Intégrations avec des applications tierces.
 *
 * Module sans route : ces échanges se déclenchent en console (reprise d'un
 * historique) ou par tâche planifiée (synchronisation courante), jamais depuis
 * l'interface. Il n'y a donc rien à exposer sur l'API.
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImporterTiersZohoCommand::class]);
        }
    }
}
