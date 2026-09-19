<?php

use App\Modules\Tiers\Http\Controllers\ContactsController;
use App\Modules\Tiers\Http\Controllers\TiersController;
use Illuminate\Support\Facades\Route;

// Conversion prospect → client (déclarée avant la ressource pour ne pas être
// captée par la route {tiers}).
Route::post('tiers/{tiers}/convertir', [TiersController::class, 'convertir']);
Route::get('tiers/{tiers}/encours', [TiersController::class, 'encours']);

// Vue 360 : la synthèse chiffrée, et les articles échangés avec ce tiers.
Route::get('tiers/{tiers}/synthese', [TiersController::class, 'synthese']);
Route::get('tiers/{tiers}/produits', [TiersController::class, 'produits']);

// Les interlocuteurs, imbriqués sous leur tiers : un contact n'existe pas seul.
Route::get('tiers/{tiers}/contacts', [ContactsController::class, 'index']);
Route::post('tiers/{tiers}/contacts', [ContactsController::class, 'store']);
Route::put('tiers/{tiers}/contacts/{contact}', [ContactsController::class, 'update']);
Route::delete('tiers/{tiers}/contacts/{contact}', [ContactsController::class, 'destroy']);

Route::apiResource('tiers', TiersController::class)->parameters(['tiers' => 'tiers']);
