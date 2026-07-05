<?php

use App\Modules\Stock\Http\Controllers\EntrepotsController;
use App\Modules\Stock\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::prefix('stock')->group(function () {
    Route::get('niveaux', [StockController::class, 'niveaux']);
    Route::get('mouvements', [StockController::class, 'mouvements']);
    Route::post('mouvements', [StockController::class, 'creerMouvement']);

    Route::get('entrepots', [EntrepotsController::class, 'index']);
    Route::post('entrepots', [EntrepotsController::class, 'store']);
    Route::put('entrepots/{entrepot}', [EntrepotsController::class, 'update']);
    Route::delete('entrepots/{entrepot}', [EntrepotsController::class, 'destroy']);
});
