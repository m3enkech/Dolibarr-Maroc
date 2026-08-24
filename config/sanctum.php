<?php

use Laravel\Sanctum\Sanctum;

return [

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', '')),

    /*
    |--------------------------------------------------------------------------
    | Guards d'authentification
    |--------------------------------------------------------------------------
    |
    | Volontairement VIDE. Le SPA s'authentifie uniquement par jeton Bearer.
    | La valeur par défaut du paquet (['web']) ferait résoudre l'utilisateur de
    | session AVANT le jeton et SANS contrôle du type de porteur — ce qui, avec
    | plusieurs types de comptes (utilisateurs de l'ERP et acheteurs du portail),
    | ouvrirait la porte à une authentification croisée.
    |
    */

    'guard' => [],

    // Pas d'expiration globale : les jetons de l'ERP en production resteraient
    // sinon invalidés. L'expiration se pose PAR JETON à l'émission.
    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
