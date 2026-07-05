<?php

namespace App\Core;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "scoped" : une instance par requête, jamais partagée entre deux requêtes.
        $this->app->scoped(TenantContext::class);
    }
}
