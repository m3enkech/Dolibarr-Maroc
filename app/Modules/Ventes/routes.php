<?php

use App\Modules\Ventes\Http\Controllers\VentesController;
use Illuminate\Support\Facades\Route;

Route::prefix('ventes')->group(function () {
    Route::get('documents', [VentesController::class, 'index']);
    Route::post('documents', [VentesController::class, 'store']);
    Route::get('documents/{document}', [VentesController::class, 'show']);
    Route::put('documents/{document}', [VentesController::class, 'update']);
    Route::delete('documents/{document}', [VentesController::class, 'destroy']);

    Route::post('documents/{document}/valider', [VentesController::class, 'valider']);
    Route::post('documents/{document}/statut', [VentesController::class, 'changerStatut']);
    Route::post('documents/{document}/transformer', [VentesController::class, 'transformer']);
    Route::post('documents/{document}/paiements', [VentesController::class, 'ajouterPaiement']);
    Route::get('documents/{document}/pdf', [VentesController::class, 'pdf']);
    Route::get('documents/{document}/efacture', [VentesController::class, 'efacture']);
});
