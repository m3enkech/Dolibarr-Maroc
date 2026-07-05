<?php

use App\Modules\Compta\Http\Controllers\ClotureController;
use App\Modules\Compta\Http\Controllers\ComptesController;
use App\Modules\Compta\Http\Controllers\EcrituresController;
use App\Modules\Compta\Http\Controllers\ImmobilisationsController;
use App\Modules\Compta\Http\Controllers\LettrageController;
use App\Modules\Compta\Http\Controllers\RapportsController;
use Illuminate\Support\Facades\Route;

Route::prefix('compta')->group(function () {
    Route::get('lettrage', [LettrageController::class, 'index']);
    Route::post('lettrage', [LettrageController::class, 'lettrer']);
    Route::post('lettrage/auto', [LettrageController::class, 'auto']);
    Route::post('lettrage/delettrer', [LettrageController::class, 'delettrer']);

    Route::get('comptes', [ComptesController::class, 'index']);
    Route::post('comptes', [ComptesController::class, 'store']);
    Route::get('mappings', [ComptesController::class, 'mappings']);
    Route::put('mappings', [ComptesController::class, 'updateMapping']);

    Route::get('ecritures', [EcrituresController::class, 'index']);
    Route::post('ecritures', [EcrituresController::class, 'store']);

    Route::get('balance', [RapportsController::class, 'balance']);
    Route::get('tva', [RapportsController::class, 'tva']);

    Route::get('exercices', [ClotureController::class, 'index']);
    Route::post('exercices/cloturer', [ClotureController::class, 'cloturer']);

    Route::get('immobilisations/categories', [ImmobilisationsController::class, 'categories']);
    Route::post('immobilisations/dotations', [ImmobilisationsController::class, 'genererDotations']);
    Route::get('immobilisations', [ImmobilisationsController::class, 'index']);
    Route::post('immobilisations', [ImmobilisationsController::class, 'store']);
    Route::get('immobilisations/{immobilisation}', [ImmobilisationsController::class, 'show']);
    Route::delete('immobilisations/{immobilisation}', [ImmobilisationsController::class, 'destroy']);
    Route::post('immobilisations/{immobilisation}/ceder', [ImmobilisationsController::class, 'ceder']);
});
