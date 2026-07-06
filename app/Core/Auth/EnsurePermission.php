<?php

namespace App\Core\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique les droits d'un rôle sur un domaine (module). L'action requise est
 * déduite de la méthode HTTP : lecture pour GET/HEAD/OPTIONS, écriture sinon.
 *
 * Usage : ->middleware('permission:ventes'). Le superadmin plateforme et les
 * rôles disposant du niveau requis passent ; sinon 403.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $domaine): Response
    {
        $action = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            ? Roles::READ
            : Roles::WRITE;

        if (! $request->user()?->hasPermission($domaine, $action)) {
            abort(403, "Vous n'avez pas les droits nécessaires sur ce module.");
        }

        return $next($request);
    }
}
