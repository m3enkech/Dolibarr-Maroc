<?php

use App\Modules\Portail\Http\Controllers\AuthPortailController;
use Illuminate\Support\Facades\Route;

Route::get('auth/moi', [AuthPortailController::class, 'moi']);
Route::post('auth/deconnexion', [AuthPortailController::class, 'deconnexion']);

Route::get('mes-grossistes', [AuthPortailController::class, 'mesGrossistes']);
Route::post('demander-acces', [AuthPortailController::class, 'demanderAcces']);
