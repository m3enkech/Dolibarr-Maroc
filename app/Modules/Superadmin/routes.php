<?php

use App\Modules\Superadmin\Http\Controllers\SuperadminController;
use Illuminate\Support\Facades\Route;

// Console plateforme cross-tenant : réservée au superadmin (pas de middleware
// 'tenant', on opère sur toutes les entreprises).
Route::prefix('superadmin')->group(function () {
    Route::get('tenants', [SuperadminController::class, 'index']);
    Route::get('tenants/{tenant}', [SuperadminController::class, 'show']);
    Route::put('tenants/{tenant}', [SuperadminController::class, 'update']);
    Route::post('tenants/{tenant}/suspend', [SuperadminController::class, 'suspend']);
    Route::post('tenants/{tenant}/reactivate', [SuperadminController::class, 'reactivate']);
});
