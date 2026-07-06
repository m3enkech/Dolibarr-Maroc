<?php

use App\Modules\Crm\Http\Controllers\ActivitesController;
use App\Modules\Crm\Http\Controllers\OpportunitesController;
use App\Modules\Crm\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::prefix('crm/opportunites')->group(function () {
    Route::get('/', [OpportunitesController::class, 'index']);
    Route::get('closes', [OpportunitesController::class, 'closes']);
    Route::post('/', [OpportunitesController::class, 'store']);
    Route::put('{opportunite}', [OpportunitesController::class, 'update']);
    Route::post('{opportunite}/deplacer', [OpportunitesController::class, 'deplacer']);
    Route::post('{opportunite}/cloturer', [OpportunitesController::class, 'cloturer']);
    Route::post('{opportunite}/rouvrir', [OpportunitesController::class, 'rouvrir']);
    Route::post('{opportunite}/devis', [OpportunitesController::class, 'genererDevis']);
    Route::delete('{opportunite}', [OpportunitesController::class, 'destroy']);
});

Route::get('crm/tiers/{tiers}/timeline', [TimelineController::class, 'show']);

Route::prefix('crm/activites')->group(function () {
    Route::get('/', [ActivitesController::class, 'index']);
    Route::post('/', [ActivitesController::class, 'store']);
    Route::put('{activite}', [ActivitesController::class, 'update']);
    Route::post('{activite}/fait', [ActivitesController::class, 'fait']);
    Route::delete('{activite}', [ActivitesController::class, 'destroy']);
});
