<?php

use App\Modules\Communications\Http\Controllers\DocumentPublicController;
use App\Modules\Communications\Services\LienPublicDocument;
use Illuminate\Support\Facades\Route;

// Ouvert à un anonyme sur Internet : SON limiteur nommé (voir
// App\Core\Http\LimitesDeDebit). `whereNumber` écarte tout le reste avant même
// le contrôleur. Pas de résolution automatique du modèle : elle chercherait le
// document dans une entreprise courante qui n'existe pas encore, et répondrait
// un 404 distinct du refus unique.
Route::get('documents/{document}/pdf', [DocumentPublicController::class, 'pdf'])
    ->whereNumber('document')
    ->middleware('throttle:lien-document-public')
    ->name(LienPublicDocument::ROUTE);
