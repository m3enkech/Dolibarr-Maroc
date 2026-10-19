<?php

namespace App\Modules\Communications\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Communications\Services\LienPublicDocument;
use App\Modules\Ventes\Services\DocumentPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le PDF d'une facture ou d'un devis, ouvert par le CLIENT depuis le lien reçu
 * sur WhatsApp — sans compte, sans connexion.
 *
 * Ce point d'entrée est anonyme et exposé à Internet. Il ne rend QUE le PDF,
 * déjà destiné au client ; aucune autre donnée, aucun JSON.
 */
class DocumentPublicController extends Controller
{
    /**
     * UN SEUL REFUS pour tout : signature fausse, lien expiré, document
     * introuvable, supprimé, en brouillon, entreprise suspendue. Distinguer
     * ces cas dirait à qui tâtonne ce qui existe.
     *
     * En texte brut et dans les deux langues : c'est un client qui lit, sur
     * son téléphone, et le JSON de l'API lui afficherait l'arabe en codes
     * d'échappement illisibles.
     */
    private const REFUS = "Ce lien n'est pas valide ou a expiré.\nهذا الرابط غير صالح أو انتهت صلاحيته.\n";

    public function pdf(Request $request, LienPublicDocument $liens, DocumentPdfService $pdf, TenantContext $contexte): Response
    {
        $resolu = $liens->resoudre($request);

        if ($resolu === null) {
            return response(self::REFUS, Response::HTTP_FORBIDDEN, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store',
            ] + $this->entetesCommuns());
        }

        [$document, $tenant] = $resolu;

        // Le rendu lit les lignes, le client, les paiements… : il s'exécute
        // DANS l'entreprise du document, posée explicitement, puis restaurée.
        // Sans elle, le scope fail-closed ne trouverait ni les lignes ni le
        // client, et le rendu échouerait.
        $reponse = $contexte->runAs($tenant, fn () => $pdf->rendre($document)->stream($document->code.'.pdf'));

        // `private, no-store` : le lien est personnel, aucun cache partagé
        // (proxy d'opérateur, CDN) ne doit garder la facture d'un client.
        return $reponse->withHeaders([
            'Cache-Control' => 'private, no-store',
        ] + $this->entetesCommuns());
    }

    /** @return array<string, string> */
    private function entetesCommuns(): array
    {
        return [
            // Un lien collé dans une conversation finit parfois sur une page
            // publique : qu'aucun moteur ne l'indexe.
            'X-Robots-Tag' => 'noindex, nofollow',
            // L'URL porte la signature : elle ne doit pas fuir vers un site
            // tiers par l'en-tête Referer.
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
