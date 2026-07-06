<?php

use App\Modules\Relances\Http\Controllers\RelancesController;
use Illuminate\Support\Facades\Route;

Route::prefix('relances')->group(function () {
    Route::get('a-relancer', [RelancesController::class, 'aRelancer']);
    Route::post('/', [RelancesController::class, 'store']);
    Route::get('{document}/historique', [RelancesController::class, 'historique']);
    Route::get('{document}/lettre', [RelancesController::class, 'lettre']);
});
