<?php

namespace App\Modules\Communications\Services;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantScope;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Le lien PUBLIC vers le PDF d'une facture ou d'un devis, glissé dans le
 * message envoyé au client.
 *
 * Le client n'a pas de compte : le lien doit s'ouvrir tel quel sur son
 * téléphone. Il est donc SIGNÉ (HMAC avec la clé de l'application) et
 * TEMPORAIRE. La signature couvre le chemin ET la date d'expiration : changer
 * l'identifiant du document, repousser l'échéance ou ajouter un paramètre la
 * casse. Sans la clé, aucun lien ne se fabrique — l'identifiant visible dans
 * l'URL ne donne donc rien à énumérer.
 *
 * SIGNATURE RELATIVE, ET POURQUOI. Une signature « absolue » couvre aussi le
 * schéma et l'hôte. En production, l'application est derrière nginx : la
 * requête lui arrive en http, sur une adresse interne, et ne retrouve
 * https://crm.mediadesk.ma que si chaque en-tête X-Forwarded-* est fidèlement
 * transmis. Qu'un maillon manque, et TOUS les liens déjà envoyés aux clients
 * répondent 403 d'un coup. On ne signe donc que le chemin et sa requête
 * (`absolute: false`) ; l'hôte ne protège rien — le secret, c'est la clé.
 *
 * L'HÔTE DU LIEN. En production, c'est APP_URL, et jamais l'hôte de la
 * requête : le lien part chez un tiers qui doit joindre l'adresse PUBLIQUE.
 * L'hôte de la requête, lui, dépend de ce que le proxy transmet (une adresse
 * interne si nginx ne recopie pas Host) et un en-tête Host forgé fabriquerait
 * un lien vers un autre site. Hors production, on prend l'hôte de la requête :
 * le lien fonctionne alors aussi sur un poste de développement servi sur un
 * autre port que celui d'APP_URL.
 */
class LienPublicDocument
{
    public const ROUTE = 'documents.public.pdf';

    /** Ce qui se partage avec un client : la facture et le devis, rien d'autre. */
    public const TYPES = [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_DEVIS];

    /** @return array{url: string, expire_le: Carbon} */
    public function generer(DocumentVente $document): array
    {
        $jours = max(1, (int) config('communications.lien_document.duree_jours', 30));

        // Jusqu'à la fin du dernier jour : « disponible jusqu'au 16/11 » doit
        // rester vrai le 16 au soir.
        $expireLe = now()->addDays($jours)->endOfDay();

        $relatif = URL::temporarySignedRoute(self::ROUTE, $expireLe, ['document' => $document->id], absolute: false);

        return ['url' => rtrim($this->racine(), '/').$relatif, 'expire_le' => $expireLe];
    }

    /**
     * Le document désigné par un lien signé, ou null.
     *
     * La signature est vérifiée AVANT toute lecture en base : une URL forgée
     * ne coûte qu'un HMAC, et ne laisse mesurer aucune différence entre « ce
     * document existe » et « il n'existe pas ».
     *
     * Le document est cherché SANS le scope d'entreprise — la requête est
     * anonyme, aucune entreprise n'est posée — mais AVEC celui des
     * suppressions logiques : `withoutGlobalScopes()` les retirerait tous deux
     * et rouvrirait un document supprimé.
     *
     * @return array{0: DocumentVente, 1: Tenant}|null
     */
    public function resoudre(Request $request): ?array
    {
        // Un lien sans date d'expiration serait valable pour toujours (Laravel
        // l'accepte) : on n'en émet jamais, on n'en reçoit donc pas non plus.
        if (! is_string($request->query('expires'))
            || ! $request->hasValidRelativeSignatureWhileIgnoring(self::parametreDePistage(...))) {
            return null;
        }

        $id = $request->route('document');
        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $document = DocumentVente::withoutGlobalScope(TenantScope::class)->whereKey((int) $id)->first();

        if ($document === null
            || ! in_array($document->type, self::TYPES, true)
            || $document->isBrouillon()) {
            return null;
        }

        $tenant = Tenant::find($document->tenant_id);

        // Une entreprise suspendue ne sert plus rien, à personne : ni à ses
        // utilisateurs (SetTenantContext), ni à ses clients par ce lien.
        if ($tenant === null || $tenant->isSuspended()) {
            return null;
        }

        return [$document, $tenant];
    }

    private function racine(): string
    {
        return app()->isProduction() ? (string) config('app.url') : url('/');
    }

    /**
     * Les paramètres qu'un lien ramasse en route, hors de notre volonté : un
     * lien re-partagé depuis Facebook ou Messenger revient avec `fbclid`, une
     * campagne avec `utm_*`. Ils ne désignent rien — le contrôleur ne lit que
     * l'identifiant du chemin — mais casseraient la signature, et le client
     * lirait « lien expiré » sur un lien parfaitement valable.
     */
    private static function parametreDePistage(string $parametre): bool
    {
        return in_array($parametre, ['fbclid', 'gclid'], true) || str_starts_with($parametre, 'utm_');
    }
}
