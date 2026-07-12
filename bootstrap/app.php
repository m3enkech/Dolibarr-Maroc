<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Derrière le proxy de Cloud Run (TLS terminé au proxy), faire confiance
        // aux en-têtes X-Forwarded-* pour que Laravel génère des URLs en https
        // (sinon les assets sont référencés en http -> bloqués en mixed content).
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'tenant' => \App\Core\Tenancy\SetTenantContext::class,
            'superadmin' => \App\Core\Auth\EnsureSuperadmin::class,
            'permission' => \App\Core\Auth\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
