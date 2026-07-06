<?php

use App\Modules\Crm\Http\Controllers\OpportunitesController;
use Illuminate\Support\Facades\Route;

Route::prefix('crm/opportunites')->group(function () {
    Route::get('/', [OpportunitesController::class, 'index']);
    Route::get('closes', [OpportunitesController::class, 'closes']);
    Route::post('/', [OpportunitesController::class, 'store']);
    Route::put('{opportunite}', [OpportunitesController::class, 'update']);
    Route::post('{opportunite}/deplacer', [OpportunitesController::class, 'deplacer']);
    Route::post('{opportunite}/cloturer', [OpportunitesController::class, 'cloturer']);
    Route::post('{opportunite}/rouvrir', [OpportunitesController::class, 'rouvrir']);
    Route::delete('{opportunite}', [OpportunitesController::class, 'destroy']);
});
