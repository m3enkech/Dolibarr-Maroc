<?php

namespace App\Modules\Integrations;

use App\Modules\Integrations\Console\ImporterArticlesZohoCommand;
use App\Modules\Integrations\Console\ImporterFacturesZohoCommand;
use App\Modules\Integrations\Console\ImporterTiersZohoCommand;
use App\Modules\Integrations\Console\ObtenirJetonZohoCommand;
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
            $this->commands([
                ObtenirJetonZohoCommand::class,
                // Dans l'ordre où elles doivent être jouées : les factures ont
                // besoin de leurs clients et de leurs articles.
                ImporterTiersZohoCommand::class,
                ImporterArticlesZohoCommand::class,
                ImporterFacturesZohoCommand::class,
            ]);
        }
    }
}
