<?php

use App\Modules\Achats\Http\Controllers\AchatsController;
use Illuminate\Support\Facades\Route;

Route::prefix('achats')->group(function () {
    // Passage de commande depuis l'écran de suivi. Limiteur NOMMÉ : la clé par
    // défaut de Laravel ne contient pas le chemin de la route, un `throttle:10,1`
    // générique partagerait donc son seau avec les autres routes limitées.
    Route::post('commandes-reappro', [AchatsController::class, 'commanderReappro'])
        ->middleware('throttle:commande-reappro');

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
