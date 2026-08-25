<?php

namespace App\Modules\Portail;

use App\Modules\Portail\Http\Middleware\SetTenantPortail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Portail acheteur. Trois familles de routes, volontairement séparées :
 *
 *  1. publiques            — inscription, connexion, annuaire des grossistes ;
 *  2. acheteur HORS tenant — « mes grossistes », demande d'accès ;
 *  3. acheteur AVEC tenant — tout ce qui touche aux données d'un grossiste,
 *     derrière SetTenantPortail qui vérifie le rattachement et pose le contexte.
 *
 * Le guard `acheteur` est distinct de `sanctum` : un jeton d'acheteur ne peut
 * pas authentifier une route de l'ERP, et réciproquement.
 */
class PortailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 1. Public
        Route::middleware('api')
            ->prefix('api/portail/v1')
            ->group(__DIR__.'/routes-public.php');

        // 2. Acheteur authentifié, sans entreprise courante
        Route::middleware(['api', 'auth:acheteur'])
            ->prefix('api/portail/v1')
            ->group(__DIR__.'/routes-acheteur.php');

        // 3. Acheteur authentifié, dans le périmètre d'un grossiste
        //
        // ⚠️ AUCUNE route de ce groupe ne doit s'appuyer sur la résolution
        // automatique du modèle (`show(Produit $produit)`). SubstituteBindings
        // vient du groupe `api` et passe donc AVANT SetTenantPortail : le modèle
        // serait cherché sans entreprise courante — un acheteur n'en porte
        // aucune — et le scope étant fail-closed, la route répondrait 404 en
        // permanence, sans la moindre trace dans les journaux.
        // Les contrôleurs lisent le paramètre par son NOM et chargent la
        // ressource eux-mêmes, une fois le contexte posé.
        // Le limiteur vient APRÈS le garde, pour compter par compte acheteur et
        // non par adresse. C'est ici que se trouvent les routes les plus lourdes
        // du portail — le rendu d'un PDF de facture — et c'est ici qu'un
        // concurrent muni d'un compte approuvé aspirerait catalogue et tarifs.
        Route::middleware(['api', 'auth:acheteur', 'throttle:portail-grossiste', SetTenantPortail::class])
            ->prefix('api/portail/v1/grossistes/{grossiste}')
            ->group(__DIR__.'/routes-grossiste.php');

        // 4. Côté ERP : le grossiste gère les demandes d'accès
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'permission:tiers'])
            ->prefix('api/v1')
            ->group(__DIR__.'/routes-erp.php');
    }
}
