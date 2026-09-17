<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zoho Books
    |--------------------------------------------------------------------------
    |
    | Accès en LECTURE à Zoho Books, pour rapatrier tiers et factures.
    |
    | ⚠️ LE CENTRE DE DONNÉES COMPTE. Un compte européen ne répond pas sur les
    | domaines `.com` de la documentation : l'authentification échouerait sur un
    | « invalid client » parfaitement trompeur, puisque les identifiants sont
    | bons. Les valeurs par défaut ci-dessous visent l'Europe, qui est le centre
    | de l'organisation Media Desk.
    |
    | Aucune de ces valeurs n'a sa place dans le dépôt : elles vivent dans .env.
    |
    */

    'books' => [
        'client_id' => env('ZOHO_CLIENT_ID'),
        'client_secret' => env('ZOHO_CLIENT_SECRET'),

        // Jeton de rafraîchissement : il ne périme pas, contrairement au code
        // d'autorisation qui l'a engendré (valable quelques minutes, à usage unique).
        'refresh_token' => env('ZOHO_REFRESH_TOKEN'),

        'organization_id' => env('ZOHO_BOOKS_ORGANIZATION_ID'),

        'accounts_url' => env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.eu'),
        'api_url' => env('ZOHO_BOOKS_API_URL', 'https://www.zohoapis.eu/books/v3'),

        // Zoho borne ses appels par minute. On respire entre deux pages plutôt
        // que de se faire couper au milieu d'un import de plusieurs centaines
        // d'enregistrements.
        'pause_entre_pages_ms' => (int) env('ZOHO_PAUSE_PAGES_MS', 400),
    ],

];
