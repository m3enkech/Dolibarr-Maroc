<?php

namespace App\Core\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Réserve une route au superadmin plateforme. Les comptes entreprises (même
 * les « admin » de tenant) reçoivent 403.
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperadmin()) {
            abort(403, 'Opération réservée au superadmin de la plateforme.');
        }

        return $next($request);
    }
}
