<?php

use App\Modules\Tiers\Http\Controllers\TiersController;
use Illuminate\Support\Facades\Route;

// Conversion prospect → client (déclarée avant la ressource pour ne pas être
// captée par la route {tiers}).
Route::post('tiers/{tiers}/convertir', [TiersController::class, 'convertir']);

Route::apiResource('tiers', TiersController::class)->parameters(['tiers' => 'tiers']);
