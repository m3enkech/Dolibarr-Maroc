<?php

use App\Modules\Portail\Http\Controllers\CataloguePortailController;
use Illuminate\Support\Facades\Route;

/*
 * Périmètre d'un grossiste. Quand ces routes s'exécutent, SetTenantPortail a
 * déjà vérifié le rattachement de l'acheteur et posé le contexte tenant : le
 * scope global filtre donc naturellement toutes les requêtes.
 */

Route::get('catalogue', [CataloguePortailController::class, 'index']);
Route::get('catalogue/{produit}', [CataloguePortailController::class, 'show']);
