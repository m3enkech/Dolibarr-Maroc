<?php

use App\Modules\Portail\Http\Controllers\AdhesionsErpController;
use Illuminate\Support\Facades\Route;

// Côté grossiste : demandes d'accès au portail.
Route::get('portail/adhesions', [AdhesionsErpController::class, 'index']);
Route::post('portail/adhesions/{rattachement}/approuver', [AdhesionsErpController::class, 'approuver']);
Route::post('portail/adhesions/{rattachement}/refuser', [AdhesionsErpController::class, 'refuser']);
Route::post('portail/adhesions/{rattachement}/revoquer', [AdhesionsErpController::class, 'revoquer']);
