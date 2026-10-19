<?php

namespace App\Modules\Communications;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Messages aux clients : préparation (numéros, textes FR/AR, lien du PDF),
 * journal des envois, et le lien public signé vers le PDF.
 *
 * Trois familles de routes, et UNE permission par route — celle du domaine
 * dont relève le message, pas une permission « communications » de plus que
 * personne ne saurait attribuer :
 *
 *  1. publique  — le PDF ouvert par le client depuis le lien reçu ;
 *  2. ventes    — facture, devis, confirmation de paiement (`permission:ventes`) ;
 *  3. relances  — relance d'impayé (`permission:relances`).
 *
 * Même montage que le portail, dont les routes côté ERP vivent sous la garde
 * `tiers` sans appartenir au module Tiers.
 */
class CommunicationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 1. Sans `auth:sanctum` ni `tenant` : le client qui ouvre le lien n'a
        // pas de compte. C'est la signature qui autorise, et le contrôleur qui
        // pose l'entreprise du document.
        Route::middleware('api')
            ->prefix('api/v1/public')
            ->group(__DIR__.'/routes-public.php');

        // 2.
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:ventes'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes-ventes.php');

        // 3.
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:relances'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes-relances.php');
    }
}
