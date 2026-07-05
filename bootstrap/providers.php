<?php

use App\Core\CoreServiceProvider;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Tiers\TiersServiceProvider;
use App\Modules\Compta\ComptaServiceProvider;
use App\Modules\Stock\StockServiceProvider;
use App\Modules\Ventes\VentesServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CoreServiceProvider::class,

    // Modules métier — un provider par module.
    TiersServiceProvider::class,
    CatalogueServiceProvider::class,
    VentesServiceProvider::class,
    StockServiceProvider::class,
    ComptaServiceProvider::class,
];
