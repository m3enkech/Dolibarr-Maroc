<?php

use App\Modules\Pilotage\Http\Controllers\PilotageController;
use Illuminate\Support\Facades\Route;

Route::prefix('pilotage')->group(function () {
    // Pas de `permission:` ici : la garde est posée bloc par bloc dans le
    // service. Voir le commentaire du contrôleur.
    Route::get('flux', [PilotageController::class, 'flux']);
});
