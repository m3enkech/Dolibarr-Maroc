<?php

namespace App\Modules\Portail\Services;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitConditionnement;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\DocumentVenteLigne;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Validation\ValidationException;

/**
 * Commandes passées depuis le portail.
 *
 * Trois règles tiennent tout ce service :
 *
 *  1. Le PRIX n'est jamais accepté du client. Le portail ne transmet que
 *     l'article et la quantité ; c'est le moteur tarifaire, côté serveur, qui
 *     fixe le prix. Sans cela, un acheteur pourrait commander à son prix.
 *  2. La commande arrive en BROUILLON. Elle vaut demande, pas engagement du
 *     grossiste : c'est lui qui la confirme en la validant dans son ERP.
 *  3. Un acheteur ne voit QUE ses propres commandes. Le scope d'entreprise ne
 *     suffit pas ici : il laisserait voir celles de tous les clients du
 *     grossiste. Le filtre sur le compte client est donc explicite.
 */
class PortailCommandeService
{
    public function __construct(private VenteService $ventes) {}

    /**
     * @param  array<int, array{produit_id: int, quantite?: float, conditionnement_id?: int, quantite_colis?: float}>  $lignes
     */
    public function passer(Tiers $client, array $lignes, ?string $note = null): DocumentVente
    {
        $preparees = [];

        foreach ($lignes as $ligne) {
            $produit = Produit::where('is_active', true)->find($ligne['produit_id']);

            if ($produit === null) {
                throw ValidationException::withMessages([
                    'lignes' => __('Un article de votre commande n\'est plus disponible.'),
                ]);
            }

            $conditionnementId = $ligne['conditionnement_id'] ?? null;

            if ($conditionnementId !== null) {
                $conditionnement = ProduitConditionnement::where('produit_id', $produit->id)
                    ->find($conditionnementId);

                if ($conditionnement === null) {
                    throw ValidationException::withMessages([
                        'lignes' => __('Ce conditionnement n\'existe pas pour cet article.'),
                    ]);
                }
            }

            $preparees[] = array_filter([
                'produit_id' => $produit->id,
                'quantite' => $ligne['quantite'] ?? null,
                'conditionnement_id' => $conditionnementId,
                'quantite_colis' => $ligne['quantite_colis'] ?? null,
                // Aucun prix_unitaire : VenteService applique le tarif du client.
            ], fn ($v) => $v !== null);
        }

        return $this->ventes->create([
            'type' => DocumentVente::TYPE_COMMANDE,
            'tiers_id' => $client->id,
            'notes' => $note,
            'lignes' => $preparees,
        ]);
    }

    /** Commandes de CE client uniquement, de la plus récente à la plus ancienne. */
    public function lister(Tiers $client, int $page, int $parPage = 20): array
    {
        $commandes = DocumentVente::query()
            ->where('tiers_id', $client->id)
            ->where('type', DocumentVente::TYPE_COMMANDE)
            ->with('lignes')
            ->latest('date_document')
            ->latest('id')
            ->paginate($parPage, ['*'], 'page', $page);

        return [
            'data' => collect($commandes->items())->map(fn (DocumentVente $c) => $this->resume($c))->all(),
            'meta' => [
                'total' => $commandes->total(),
                'page' => $commandes->currentPage(),
                'dernier_page' => $commandes->lastPage(),
            ],
        ];
    }

    /** Fiche d'une commande — refusée si elle n'appartient pas à ce client. */
    public function detail(Tiers $client, int $commandeId): array
    {
        $commande = DocumentVente::query()
            ->where('tiers_id', $client->id)
            ->where('type', DocumentVente::TYPE_COMMANDE)
            ->with('lignes.conditionnement')
            ->find($commandeId);

        abort_if($commande === null, 404);

        return $this->resume($commande, detaille: true);
    }

    /** @return array<string, mixed> */
    private function resume(DocumentVente $commande, bool $detaille = false): array
    {
        $base = [
            'id' => $commande->id,
            'code' => $commande->code,
            'date' => $commande->date_document?->toDateString(),
            // Vocabulaire de l'acheteur, pas celui de l'ERP.
            'etat' => $this->etatLisible($commande),
            'livraison' => $commande->etatLivraison(),
            'total_ht' => number_format((float) $commande->total_ht, 2, '.', ''),
            'total_ttc' => number_format((float) $commande->total_ttc, 2, '.', ''),
            'nb_lignes' => $commande->lignes->count(),
        ];

        if (! $detaille) {
            return $base;
        }

        return $base + [
            'notes' => $commande->notes,
            'lignes' => $commande->lignes->map(fn (DocumentVenteLigne $l) => [
                'designation' => $l->designation,
                'quantite' => (float) $l->quantite,
                'conditionnement' => $l->conditionnement?->nom,
                'quantite_colis' => $l->quantite_colis !== null ? (float) $l->quantite_colis : null,
                'prix_unitaire' => number_format((float) $l->prix_unitaire, 2, '.', ''),
                'montant_ttc' => number_format((float) $l->montant_ttc, 2, '.', ''),
                'quantite_livree' => (float) $l->quantite_livree,
                'reste_a_livrer' => $l->resteALivrer(),
            ])->values()->all(),
        ];
    }

    /**
     * Le statut interne de l'ERP ne parle pas à un épicier : « brouillon »
     * signifie pour lui « en attente de confirmation ».
     */
    private function etatLisible(DocumentVente $commande): string
    {
        return match (true) {
            $commande->statut === DocumentVente::STATUT_BROUILLON => 'en_attente_confirmation',
            $commande->etatLivraison() === 'complete' => 'livree',
            $commande->etatLivraison() === 'partielle' => 'partiellement_livree',
            default => 'confirmee',
        };
    }
}
