<?php

use App\Modules\Portail\Http\Controllers\AuthPortailController;
use Illuminate\Support\Facades\Route;

Route::get('auth/moi', [AuthPortailController::class, 'moi']);
Route::post('auth/deconnexion', [AuthPortailController::class, 'deconnexion']);

Route::get('mes-grossistes', [AuthPortailController::class, 'mesGrossistes']);
// Compté par COMPTE acheteur, pas par adresse : le garde s'est déjà exécuté, et
// plusieurs commerçants peuvent partager une même sortie NAT.
Route::post('demander-acces', [AuthPortailController::class, 'demanderAcces'])
    ->middleware('throttle:demande-acces');
