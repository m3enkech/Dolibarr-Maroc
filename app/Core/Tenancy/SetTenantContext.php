<?php

namespace App\Core\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware à placer APRÈS auth:sanctum : initialise le contexte tenant
 * à partir de l'utilisateur authentifié.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id === null) {
            abort(403, 'Aucun tenant associé à cet utilisateur.');
        }

        app(TenantContext::class)->set($user->tenant);

        return $next($request);
    }
}
