<?php

use App\Modules\Tiers\Http\Controllers\TiersController;
use Illuminate\Support\Facades\Route;

Route::apiResource('tiers', TiersController::class)->parameters(['tiers' => 'tiers']);
