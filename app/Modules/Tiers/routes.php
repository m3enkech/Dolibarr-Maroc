<?php

use App\Modules\Tiers\Http\Controllers\CommentairesController;
use App\Modules\Tiers\Http\Controllers\ContactsController;
use App\Modules\Tiers\Http\Controllers\TiersController;
use Illuminate\Support\Facades\Route;

// Actions groupées de la liste et export CSV. AVANT la ressource : déclarée
// après, `GET tiers/export` serait capté par `GET tiers/{tiers}` et finirait
// en 404 sur un tiers nommé « export ». La garde est celle du module, en
// lecture pour l'export, en écriture pour les actions (POST).
Route::get('tiers/export', [TiersController::class, 'export']);
Route::post('tiers/actions', [TiersController::class, 'actions']);

// Conversion prospect → client (déclarée avant la ressource pour ne pas être
// captée par la route {tiers}).
Route::post('tiers/{tiers}/convertir', [TiersController::class, 'convertir']);
Route::get('tiers/{tiers}/encours', [TiersController::class, 'encours']);

// Vue 360 : la synthèse chiffrée, et les articles échangés avec ce tiers.
Route::get('tiers/{tiers}/synthese', [TiersController::class, 'synthese']);
Route::get('tiers/{tiers}/produits', [TiersController::class, 'produits']);

// Vue d'ensemble façon Zoho : UNE garde (celle du module, `tiers`), et les
// blocs de vente filtrés dans le service selon les droits — le précédent est
// le tableau de bord. Jamais un second `permission:` ici.
Route::get('tiers/{tiers}/vue-ensemble', [TiersController::class, 'vueEnsemble']);

// Relevé de compte (écran et PDF) : la même garde, pour la même raison — ce
// que doit un client se lit avec les tiers. Le compte FOURNISSEUR, qui dit ce
// qu'on lui achète, exige en plus le droit achats, vérifié dans le contrôleur.
// Le solde d'ouverture, lui, est une écriture : sa route est dans le module
// Compta, sous la seule garde `compta`.
Route::get('tiers/{tiers}/releve', [TiersController::class, 'releve']);

// Le fil de commentaires : la garde `tiers` seule, PAS le module CRM — le
// comptable et le caissier lisent ce que l'équipe dit d'un client, même CRM
// désactivé. Supprimer exige en plus d'être l'auteur ou l'administrateur,
// vérifié par le contrôleur (Commentaire::supprimablePar).
Route::get('tiers/{tiers}/commentaires', [CommentairesController::class, 'index']);
Route::post('tiers/{tiers}/commentaires', [CommentairesController::class, 'store']);
Route::delete('tiers/{tiers}/commentaires/{commentaire}', [CommentairesController::class, 'destroy']);

// Les interlocuteurs, imbriqués sous leur tiers : un contact n'existe pas seul.
Route::get('tiers/{tiers}/contacts', [ContactsController::class, 'index']);
Route::post('tiers/{tiers}/contacts', [ContactsController::class, 'store']);
Route::put('tiers/{tiers}/contacts/{contact}', [ContactsController::class, 'update']);
Route::delete('tiers/{tiers}/contacts/{contact}', [ContactsController::class, 'destroy']);

Route::apiResource('tiers', TiersController::class)->parameters(['tiers' => 'tiers']);
