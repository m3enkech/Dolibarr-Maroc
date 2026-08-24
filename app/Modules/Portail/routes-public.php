<?php

use App\Modules\Portail\Http\Controllers\AuthPortailController;
use Illuminate\Support\Facades\Route;

Route::post('auth/inscription', [AuthPortailController::class, 'inscription']);
Route::post('auth/connexion', [AuthPortailController::class, 'connexion']);
Route::get('annuaire', [AuthPortailController::class, 'annuaire']);
