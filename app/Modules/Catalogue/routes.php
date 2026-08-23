<?php

use App\Modules\Catalogue\Http\Controllers\CategoriesProduitController;
use App\Modules\Catalogue\Http\Controllers\ProduitsController;
use App\Modules\Catalogue\Http\Controllers\TarifsController;
use Illuminate\Support\Facades\Route;

// Tarifs de la vente en gros : catégories, grille par article, grille client.
Route::get('categories-tarifaires', [TarifsController::class, 'categories']);
Route::post('categories-tarifaires', [TarifsController::class, 'storeCategorie']);
Route::put('categories-tarifaires/{categorie}', [TarifsController::class, 'updateCategorie']);
Route::delete('categories-tarifaires/{categorie}', [TarifsController::class, 'destroyCategorie']);

Route::get('tarifs/grille', [TarifsController::class, 'grilleClient']);
Route::get('produits/{produit}/tarifs', [TarifsController::class, 'parProduit']);
Route::post('produits/{produit}/tarifs', [TarifsController::class, 'storeTarif']);
Route::delete('tarifs/{tarif}', [TarifsController::class, 'destroyTarif']);

Route::get('categories-produit', [CategoriesProduitController::class, 'index']);
Route::post('categories-produit', [CategoriesProduitController::class, 'store']);
Route::put('categories-produit/{categorie}', [CategoriesProduitController::class, 'update']);
Route::delete('categories-produit/{categorie}', [CategoriesProduitController::class, 'destroy']);

Route::apiResource('produits', ProduitsController::class);
