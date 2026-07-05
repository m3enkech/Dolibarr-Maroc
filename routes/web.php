<?php

use Illuminate\Support\Facades\Route;

// SPA React : toutes les routes non-API sont servies par le frontend.
Route::view('/{any?}', 'app')->where('any', '^(?!api).*$');
