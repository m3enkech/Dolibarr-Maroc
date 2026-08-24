<?php

use App\Modules\Portail\Http\Controllers\CataloguePortailController;
use App\Modules\Portail\Http\Controllers\CommandesPortailController;
use Illuminate\Support\Facades\Route;

/*
 * Périmètre d'un grossiste. Quand ces routes s'exécutent, SetTenantPortail a
 * déjà vérifié le rattachement de l'acheteur et posé le contexte tenant : le
 * scope global filtre donc naturellement toutes les requêtes.
 *
 * ATTENTION : le scope filtre par ENTREPRISE, pas par client. Tout ce qui est
 * propre à l'acheteur (ses commandes, son encours) doit en plus être filtré
 * explicitement sur son compte client.
 */

Route::get('catalogue', [CataloguePortailController::class, 'index']);
Route::get('catalogue/{produit}', [CataloguePortailController::class, 'show']);

Route::get('commandes', [CommandesPortailController::class, 'index']);
Route::post('commandes', [CommandesPortailController::class, 'store']);
Route::get('commandes/{commande}', [CommandesPortailController::class, 'show']);

Route::get('mon-compte', [CommandesPortailController::class, 'monCompte']);
