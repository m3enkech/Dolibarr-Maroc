<?php

use App\Modules\Parametres\Http\Controllers\AbonnementController;
use App\Modules\Parametres\Http\Controllers\ParametresController;
use Illuminate\Support\Facades\Route;

Route::get('parametres', [ParametresController::class, 'show']);
Route::put('parametres', [ParametresController::class, 'update']);
Route::put('parametres/societe', [ParametresController::class, 'updateSociete']);

// Mon abonnement (côté entreprise cliente) + factures d'abonnement en PDF.
Route::get('abonnement', [AbonnementController::class, 'index']);
Route::get('abonnement/factures/{payment}/pdf', [AbonnementController::class, 'pdf']);
