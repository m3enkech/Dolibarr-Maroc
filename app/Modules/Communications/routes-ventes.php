<?php

use App\Modules\Communications\Http\Controllers\EnvoisVentesController;
use Illuminate\Support\Facades\Route;

// Facture, devis, confirmation de paiement : la garde des ventes, et elle
// seule. GET lister = lecture ; POST journaliser = écriture. La préparation est
// un GET, mais elle émet un lien public au porteur : le contrôleur y exige
// l'écriture (voir EnvoisVentesController::preparer).
// `whereNumber` : un identifiant non numérique répond 404 au lieu de faire
// échouer la requête SQL sur PostgreSQL (colonne bigint).
Route::get('ventes/documents/{document}/whatsapp', [EnvoisVentesController::class, 'preparer'])->whereNumber('document');
Route::post('ventes/documents/{document}/whatsapp', [EnvoisVentesController::class, 'enregistrer'])->whereNumber('document');
Route::get('ventes/documents/{document}/envois', [EnvoisVentesController::class, 'lister'])->whereNumber('document');
