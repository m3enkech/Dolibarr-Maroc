<?php

use App\Core\CoreServiceProvider;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Tiers\TiersServiceProvider;
use App\Modules\Achats\AchatsServiceProvider;
use App\Modules\Compta\ComptaServiceProvider;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Effets\EffetsServiceProvider;
use App\Modules\Parametres\ParametresServiceProvider;
use App\Modules\Pos\PosServiceProvider;
use App\Modules\Relances\RelancesServiceProvider;
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
    AchatsServiceProvider::class,
    StockServiceProvider::class,
    ComptaServiceProvider::class,
    PosServiceProvider::class,
    ParametresServiceProvider::class,
    RelancesServiceProvider::class,
    EffetsServiceProvider::class,
    CrmServiceProvider::class,
];
