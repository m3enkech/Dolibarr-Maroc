<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // En production (derrière le proxy HTTPS de Cloud Run), forcer la
        // génération d'URLs et d'assets en https pour éviter le mixed content.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
