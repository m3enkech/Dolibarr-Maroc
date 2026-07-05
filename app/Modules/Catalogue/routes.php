<?php

use App\Modules\Catalogue\Http\Controllers\CategoriesProduitController;
use App\Modules\Catalogue\Http\Controllers\ProduitsController;
use Illuminate\Support\Facades\Route;

Route::get('categories-produit', [CategoriesProduitController::class, 'index']);
Route::post('categories-produit', [CategoriesProduitController::class, 'store']);
Route::put('categories-produit/{categorie}', [CategoriesProduitController::class, 'update']);
Route::delete('categories-produit/{categorie}', [CategoriesProduitController::class, 'destroy']);

Route::apiResource('produits', ProduitsController::class);
