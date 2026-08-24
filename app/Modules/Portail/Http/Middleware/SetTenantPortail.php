<?php

namespace App\Modules\Portail\Http\Middleware;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Modules\Portail\Models\Acheteur;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pose l'entreprise courante pour une requête d'acheteur.
 *
 * C'est LA pièce critique du portail. Un acheteur n'appartient à aucune
 * entreprise : sans ce middleware, le scope global n'aurait aucun tenant à
 * appliquer. Depuis le passage en fail-closed, une telle requête ne renverrait
 * rien du tout — mais on ne s'appuie pas là-dessus : on pose ici le contexte,
 * explicitement, et seulement après avoir vérifié que l'acheteur est bien
 * rattaché ET approuvé chez ce grossiste.
 *
 * Le tenant vient du SLUG de l'URL, jamais d'un champ envoyé par le client.
 */
class SetTenantPortail
{
    public function __construct(private TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = (string) $request->route('grossiste');

        // Tenant::class ne porte pas BelongsToTenant : la recherche par slug est
        // légitimement globale.
        $tenant = Tenant::where('slug', $slug)->first();

        abort_if($tenant === null, 404, 'Grossiste introuvable.');

        /** @var Acheteur|null $acheteur */
        $acheteur = $request->user();

        abort_if($acheteur === null, 401);

        $rattachement = $acheteur->rattachementApprouve($tenant->id);

        abort_if(
            $rattachement === null,
            403,
            'Vous n\'êtes pas encore autorisé à commander chez ce grossiste.',
        );

        // Le Tiers rattaché porte le tarif, l'encours et l'historique : sans lui
        // le portail ne peut rien afficher de fiable.
        abort_if($rattachement->tiers_id === null, 403, 'Votre compte client n\'est pas encore configuré.');

        $request->attributes->set('portail_rattachement', $rattachement);

        return $this->context->runAs($tenant, fn () => $next($request));
    }
}
