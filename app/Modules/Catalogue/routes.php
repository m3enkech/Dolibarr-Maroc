<?php

use App\Modules\Catalogue\Http\Controllers\ProduitsController;
use Illuminate\Support\Facades\Route;

Route::apiResource('produits', ProduitsController::class);
