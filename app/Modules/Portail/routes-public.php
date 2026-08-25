<?php

use App\Modules\Portail\Http\Controllers\AuthPortailController;
use Illuminate\Support\Facades\Route;

// Les trois routes du portail ouvertes à un anonyme sur Internet. Chacune porte
// SON limiteur nommé : voir App\Core\Http\LimitesDeDebit.
Route::post('auth/inscription', [AuthPortailController::class, 'inscription'])
    ->middleware('throttle:inscription-portail');
Route::post('auth/connexion', [AuthPortailController::class, 'connexion'])
    ->middleware('throttle:connexion-portail');
Route::get('annuaire', [AuthPortailController::class, 'annuaire'])
    ->middleware('throttle:annuaire');
