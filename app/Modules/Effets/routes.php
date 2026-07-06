<?php

use App\Modules\Effets\Http\Controllers\EffetsController;
use Illuminate\Support\Facades\Route;

Route::prefix('effets')->group(function () {
    Route::get('/', [EffetsController::class, 'index']);
    Route::post('/', [EffetsController::class, 'store']);
    Route::post('{effet}/encaisser', [EffetsController::class, 'encaisser']);
    Route::post('{effet}/payer', [EffetsController::class, 'payer']);
    Route::post('{effet}/impaye', [EffetsController::class, 'impaye']);
});
