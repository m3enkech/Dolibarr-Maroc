<?php

use App\Core\Auth\AuthController;
use App\Core\Auth\PasswordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    // Routes anonymes exposées à Internet : chacune porte SON limiteur nommé.
    // Un `throttle:5,1` générique les ferait toutes partager un seul seau, la
    // clé par défaut de Laravel ne contenant pas le chemin de la route.
    // Voir App\Core\Http\LimitesDeDebit.
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:inscription-erp');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:connexion-erp');

    // Mot de passe oublié / réinitialisation (public).
    Route::post('forgot-password', [PasswordController::class, 'forgot'])
        ->middleware('throttle:mot-de-passe-oublie');
    Route::post('reset-password', [PasswordController::class, 'reset'])
        ->middleware('throttle:reinitialisation');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [PasswordController::class, 'change']);
    });
});
