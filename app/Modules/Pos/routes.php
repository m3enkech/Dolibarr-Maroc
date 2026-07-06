<?php

use App\Modules\Pos\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::prefix('pos')->group(function () {
    Route::get('session', [PosController::class, 'sessionCourante']);
    Route::post('session/ouvrir', [PosController::class, 'ouvrir']);
    Route::post('session/fermer', [PosController::class, 'fermer']);

    Route::get('sessions', [PosController::class, 'sessions']);
    Route::get('sessions/{session}/rapport', [PosController::class, 'rapport']);

    Route::post('ventes', [PosController::class, 'vendre']);
    Route::get('tickets', [PosController::class, 'tickets']);
});
