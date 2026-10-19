<?php

use App\Modules\Communications\Http\Controllers\EnvoisRelancesController;
use Illuminate\Support\Facades\Route;

// Relance d'impayé : la garde du recouvrement, et elle seule — le comptable,
// qui ne fait que lire les ventes, relance quand même. GET lister = lecture ;
// POST (journaliser + créer la Relance) = écriture ; la préparation, qui émet
// le lien public du PDF, exige l'écriture dans le contrôleur.
Route::get('relances/{document}/whatsapp', [EnvoisRelancesController::class, 'preparer'])->whereNumber('document');
Route::post('relances/{document}/whatsapp', [EnvoisRelancesController::class, 'enregistrer'])->whereNumber('document');
Route::get('relances/{document}/envois', [EnvoisRelancesController::class, 'lister'])->whereNumber('document');
