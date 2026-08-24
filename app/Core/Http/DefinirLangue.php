<?php

namespace App\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue des réponses de l'API, lue dans l'en-tête `Accept-Language` que posent
 * les deux clients HTTP (ERP et portail).
 *
 * Les messages de validation et de refus s'affichent tels quels dans
 * l'interface : ils doivent suivre la langue CHOISIE par l'utilisateur, pas
 * celle de son navigateur ni celle du serveur. Toute valeur inattendue retombe
 * sur le français, langue de travail du produit.
 */
class DefinirLangue
{
    private const LANGUES = ['fr', 'ar'];

    private const DEFAUT = 'fr';

    public function handle(Request $request, Closure $next): Response
    {
        // On ne lit que les deux premiers caractères : « ar-MA », « fr-FR » ou
        // une liste pondérée envoyée par un navigateur désignent la même langue.
        $demandee = strtolower(substr((string) $request->header('Accept-Language'), 0, 2));

        app()->setLocale(in_array($demandee, self::LANGUES, true) ? $demandee : self::DEFAUT);

        return $next($request);
    }
}
