<?php

namespace App\Modules\Superadmin\Services;

use App\Core\Tenancy\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Models\SubscriptionPayment;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\DocumentPdfService;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Émet la facture d'abonnement (vente de service) dans la comptabilité de
 * l'OPÉRATEUR (l'entreprise du superadmin) quand un paiement d'abonné est
 * enregistré : l'abonné devient un Tiers client, une facture est créée +
 * validée (écritures VT) puis encaissée (écriture BQ). Réutilise tout le
 * moteur de ventes (déjà conforme CGNC).
 */
class SubscriptionBillingService
{
    /** Mode d'abonnement → mode de paiement comptable. */
    private const MODE = ['virement' => 'virement', 'cmi' => 'carte', 'cheque' => 'cheque', 'especes' => 'especes', 'autre' => 'autre'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TiersService $tiers,
        private readonly VenteService $ventes,
    ) {}

    public function facturer(
        Tenant $operateur,
        Tenant $abonne,
        float $ttc,
        Carbon $debut,
        Carbon $fin,
        string $method,
        string $planLabel,
    ): DocumentVente {
        $precedent = $this->context->get();
        $this->context->set($operateur); // les modèles scoped viseront l'opérateur

        try {
            $tiers = $this->trouverOuCreerTiers($abonne);
            $ht = round($ttc / 1.2, 2); // le montant reçu est TTC, TVA 20% dégagée

            $doc = $this->ventes->create([
                'type' => DocumentVente::TYPE_FACTURE,
                'tiers_id' => $tiers->id,
                'date_document' => now()->toDateString(),
                'lignes' => [[
                    'designation' => sprintf(
                        'Abonnement Dolibarr Maroc — %s (%s → %s)',
                        $planLabel, $debut->format('d/m/Y'), $fin->format('d/m/Y'),
                    ),
                    'quantite' => 1,
                    'prix_unitaire' => $ht,
                    'tva_rate' => 20,
                ]],
            ]);

            $doc = $this->ventes->valider($doc);
            $this->ventes->ajouterPaiement($doc, [
                'montant' => (float) $doc->total_ttc,
                'mode' => self::MODE[$method] ?? 'autre',
                'date_paiement' => now()->toDateString(),
            ]);

            return $doc->refresh();
        } finally {
            $this->context->set($precedent);
        }
    }

    /** PDF de la facture d'abonnement liée à un paiement (facture chez l'opérateur). */
    public function pdf(SubscriptionPayment $payment): Response
    {
        abort_if($payment->document_vente_id === null, 404, 'Aucune facture pour ce paiement.');

        // On se place dans le contexte de l'opérateur pour que les relations
        // scopées (tiers, lignes, paiements…) de la facture se résolvent bien,
        // même si la requête vient d'un autre tenant (l'abonné).
        $operateur = $payment->operator_tenant_id !== null ? Tenant::find($payment->operator_tenant_id) : null;
        $precedent = $this->context->get();
        if ($operateur !== null) {
            $this->context->set($operateur);
        }

        try {
            return $this->rendre($payment->document_vente_id);
        } finally {
            $this->context->set($precedent);
        }
    }

    private function rendre(int $documentId): Response
    {
        $doc = DocumentVente::with(['lignes', 'tiers', 'tenant', 'paiements', 'source'])->find($documentId);

        abort_if($doc === null, 404);

        // Le MÊME rendu que les factures de vente, et non une copie : la copie
        // qui vivait ici avait déjà cessé de suivre — il lui manquait la
        // mention en toutes lettres exigée par l'article 145 du CGI, et la
        // facture d'abonnement partait en erreur 500 le jour où la vue l'a
        // demandée. Une facture est une facture.
        return app(DocumentPdfService::class)->rendre($doc)
            ->download('abonnement-'.$doc->code.'.pdf');
    }

    private function trouverOuCreerTiers(Tenant $abonne): Tiers
    {
        $existant = Tiers::where('name', $abonne->name)->first();
        if ($existant !== null) {
            return $existant;
        }

        $settings = $abonne->settings ?? [];

        return $this->tiers->create([
            'name' => $abonne->name,
            'is_client' => true,
            'is_supplier' => false,
            'ice' => $settings['ice'] ?? null,
            'if_number' => $settings['if'] ?? null,
            'city' => $settings['city'] ?? null,
            'country' => 'MA',
        ]);
    }
}
