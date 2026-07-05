<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Entre deux requêtes simulées d'un même test, le conteneur est réutilisé :
     * on purge les gardes d'auth et les singletons "scoped" (TenantContext)
     * pour reproduire le comportement d'une vraie requête HTTP.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
