<?php

use App\Modules\Achats\Http\Controllers\AchatsController;
use Illuminate\Support\Facades\Route;

Route::prefix('achats')->group(function () {
    Route::get('documents', [AchatsController::class, 'index']);
    Route::post('documents', [AchatsController::class, 'store']);
    Route::get('documents/{document}', [AchatsController::class, 'show']);
    Route::put('documents/{document}', [AchatsController::class, 'update']);
    Route::delete('documents/{document}', [AchatsController::class, 'destroy']);

    Route::post('documents/{document}/valider', [AchatsController::class, 'valider']);
    Route::post('documents/{document}/transformer', [AchatsController::class, 'transformer']);
    Route::post('documents/{document}/paiements', [AchatsController::class, 'ajouterPaiement']);
    Route::get('documents/{document}/pdf', [AchatsController::class, 'pdf']);
});
