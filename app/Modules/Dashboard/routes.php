<?php

use App\Modules\Dashboard\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Tableau de bord : accessible à tout utilisateur authentifié (pas de gate de
// domaine) — le contenu s'adapte aux permissions dans le service.
Route::get('dashboard', [DashboardController::class, 'index']);
