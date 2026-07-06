<?php

use App\Modules\Parametres\Http\Controllers\ParametresController;
use Illuminate\Support\Facades\Route;

Route::get('parametres', [ParametresController::class, 'show']);
Route::put('parametres', [ParametresController::class, 'update']);
