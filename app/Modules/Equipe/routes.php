<?php

use App\Modules\Equipe\Http\Controllers\EquipeController;
use App\Modules\Equipe\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

// --- Gestion de l'équipe : réservée aux admins du tenant (permission:equipe). ---
Route::middleware(['auth:sanctum', 'tenant', 'permission:equipe'])->group(function () {
    Route::get('equipe', [EquipeController::class, 'index']);
    Route::post('equipe/invitations', [EquipeController::class, 'inviter']);
    Route::delete('equipe/invitations/{invitation}', [EquipeController::class, 'revoquerInvitation']);
    Route::put('equipe/users/{user}', [EquipeController::class, 'modifierUtilisateur']);
    Route::delete('equipe/users/{user}', [EquipeController::class, 'supprimerUtilisateur']);
});

// --- Abonnement (plan + sièges extra) : réservé au superadmin plateforme. ---
Route::middleware(['auth:sanctum', 'tenant', 'superadmin'])->group(function () {
    Route::put('equipe/abonnement', [EquipeController::class, 'abonnement']);
});

// --- Acceptation d'invitation : public (retrouvée par token). ---
Route::get('invitations/{token}', [InvitationController::class, 'show']);
Route::post('invitations/{token}/accepter', [InvitationController::class, 'accepter']);
