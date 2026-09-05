<?php

use App\Modules\Stock\Http\Controllers\EntrepotsController;
use App\Modules\Stock\Http\Controllers\InventairesController;
use App\Modules\Stock\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::prefix('stock')->group(function () {
    Route::get('niveaux', [StockController::class, 'niveaux']);
    Route::get('alertes', [StockController::class, 'alertes']);

    // Ventilation d'un article : ce que chaque dépôt détient et attend. Servie
    // au clic sur une ligne, jamais en masse — la précharger pour quinze lignes
    // ferait quatre-vingt-dix requêtes pour une information qu'on regarde un
    // produit à la fois.
    Route::get('produits/{produit}/depots', [StockController::class, 'depots']);

    Route::get('mouvements', [StockController::class, 'mouvements']);
    Route::post('mouvements', [StockController::class, 'creerMouvement']);
    Route::post('transferts', [StockController::class, 'transferer']);

    Route::get('entrepots', [EntrepotsController::class, 'index']);
    Route::post('entrepots', [EntrepotsController::class, 'store']);
    Route::put('entrepots/{entrepot}', [EntrepotsController::class, 'update']);
    Route::delete('entrepots/{entrepot}', [EntrepotsController::class, 'destroy']);

    Route::get('inventaires', [InventairesController::class, 'index']);
    Route::post('inventaires', [InventairesController::class, 'store']);
    Route::get('inventaires/{inventaire}', [InventairesController::class, 'show']);
    Route::put('inventaires/{inventaire}', [InventairesController::class, 'update']);
    Route::post('inventaires/{inventaire}/valider', [InventairesController::class, 'valider']);
    Route::delete('inventaires/{inventaire}', [InventairesController::class, 'destroy']);
});
