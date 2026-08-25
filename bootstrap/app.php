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
        //
        // ⚠️ CETTE LIGNE PORTE AUSSI LA LIMITATION DE DÉBIT. Sans elle,
        // request()->ip() vaut l'adresse interne du frontal Cloud Run — la même
        // pour la Terre entière — et tous les acheteurs de tous les grossistes
        // partageraient UN SEUL compteur : un attaquant seul fermerait la
        // connexion pour tout le monde, ce qui serait pire que l'absence de
        // limitation. Le `'*'` ne signifie pas « tout le monde » : Laravel ne
        // fait alors confiance qu'au pair immédiat, et Symfony retient l'entrée
        // la plus à DROITE de X-Forwarded-For — un en-tête forgé par le client
        // n'est donc pas retenu. Voir App\Core\Http\LimitesDeDebit.
        // À revérifier le jour où un répartiteur de charge sera placé devant
        // Cloud Run : la chaîne gagnerait un maillon et l'adresse retenue
        // redeviendrait constante.
        $middleware->trustProxies(at: '*');

        // Toute réponse d'API parle la langue de l'appelant : les messages de
        // validation et de refus s'affichent tels quels dans l'interface.
        $middleware->api(prepend: [\App\Core\Http\DefinirLangue::class]);

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
