<?php

use App\Modules\Compta\Http\Controllers\ComptesController;
use App\Modules\Compta\Http\Controllers\EcrituresController;
use App\Modules\Compta\Http\Controllers\RapportsController;
use Illuminate\Support\Facades\Route;

Route::prefix('compta')->group(function () {
    Route::get('comptes', [ComptesController::class, 'index']);
    Route::post('comptes', [ComptesController::class, 'store']);
    Route::get('mappings', [ComptesController::class, 'mappings']);
    Route::put('mappings', [ComptesController::class, 'updateMapping']);

    Route::get('ecritures', [EcrituresController::class, 'index']);
    Route::post('ecritures', [EcrituresController::class, 'store']);

    Route::get('balance', [RapportsController::class, 'balance']);
    Route::get('tva', [RapportsController::class, 'tva']);
});
