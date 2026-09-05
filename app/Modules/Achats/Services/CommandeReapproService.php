<?php

namespace App\Modules\Achats\Services;

use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Stock;
use App\Modules\Stock\Services\ReapproService;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Passer une commande fournisseur depuis l'écran de suivi, en un geste.
 *
 * C'est la SEULE écriture déclenchée depuis un tableau de bord de cette
 * application. Quatre précautions en découlent, toutes délibérées :
 *
 * 1. LE CLIENT N'ENVOIE QUE DES IDENTIFIANTS DE PRODUITS. Ni quantité, ni prix,
 *    ni fournisseur. La suggestion affichée a été calculée à l'instant T ; entre
 *    T et le clic, des ventes ont pu passer. Faire confiance au navigateur,
 *    c'est accepter qu'un écran resté ouvert depuis ce matin — ou une requête
 *    forgée — commande dix mille unités. Tout est RECALCULÉ ici.
 *
 * 2. LES ARTICLES QU'ON NE PEUT PAS COMMANDER SONT RENVOYÉS, PAS IGNORÉS.
 *    Un article jamais acheté n'a aucun fournisseur déductible ; le taire
 *    laisserait croire qu'il est commandé. Chacun revient avec sa raison, lisible
 *    par la machine, pour que l'écran puisse la dire et proposer une suite.
 *
 * 3. LE FOURNISSEUR DÉDUIT EST REVÉRIFIÉ. Il vient du dernier achat, parfois
 *    vieux de deux ans, et rien ne garantit qu'il soit encore fournisseur. Sans
 *    ce garde-fou, on adresserait une commande fournisseur à un client :
 *    `AchatService::create()` accepterait le `tiers_id` sans broncher, la
 *    validation du drapeau vivant dans la requête HTTP, pas dans le service.
 *
 * 4. TOUT OU RIEN. Si la troisième commande échoue, les deux premières sont
 *    annulées. Aucun état à moitié écrit à réconcilier, et le rejeu idempotent
 *    devient trivial.
 */
class CommandeReapproService
{
    /** Un article jamais acheté : aucun fournisseur ne peut en être déduit. */
    public const RAISON_SANS_FOURNISSEUR = 'fournisseur_inconnu';

    /** Le stock et les commandes en cours couvrent déjà le besoin. */
    public const RAISON_RIEN_A_COMMANDER = 'rien_a_commander';

    public function __construct(
        private AchatService $achats,
        private ReapproService $reappro,
    ) {}

    /**
     * @param  list<int>  $produitIds
     * @return array{commandes: list<array<string, mixed>>, ignores: list<array<string, mixed>>}
     */
    public function creer(
        array $produitIds,
        ?int $entrepotId = null,
        ?int $fournisseurDeRepli = null,
        bool $valider = true,
    ): array {
        $produits = $this->chargerAvecLeursQuantites($produitIds, $entrepotId);
        $calculs = $this->reappro->pour($produits, $entrepotId);

        $ignores = [];
        $parFournisseur = [];

        foreach ($produits as $produit) {
            $calcul = $calculs[$produit->id];
            $suggestion = (float) ($calcul['suggestion'] ?? 0);

            if ($suggestion <= 0) {
                $ignores[] = $this->ignore($produit, self::RAISON_RIEN_A_COMMANDER);

                continue;
            }

            $fournisseurId = $this->fournisseurUtilisable($calcul['fournisseur_id'] ?? null)
                ?? $this->fournisseurUtilisable($fournisseurDeRepli);

            if ($fournisseurId === null) {
                $ignores[] = $this->ignore($produit, self::RAISON_SANS_FOURNISSEUR);

                continue;
            }

            $parFournisseur[$fournisseurId][] = [
                'produit_id' => $produit->id,
                'designation' => $produit->name,
                'quantite' => $suggestion,
                // À défaut de dernier prix payé, `syncLignes` retombera sur le
                // prix d'achat du catalogue.
                'prix_unitaire' => $calcul['dernier_prix_achat'] ?? null,
            ];
        }

        if ($parFournisseur === []) {
            // Rien d'invalide : il n'y a simplement rien à faire. Un 422 serait
            // un contresens.
            return ['commandes' => [], 'ignores' => $ignores];
        }

        $commandes = DB::transaction(function () use ($parFournisseur, $entrepotId, $valider) {
            $creees = [];

            foreach ($parFournisseur as $fournisseurId => $lignes) {
                $document = $this->achats->create([
                    'type' => DocumentAchat::TYPE_COMMANDE,
                    'tiers_id' => $fournisseurId,
                    'entrepot_id' => $entrepotId,
                    'notes' => 'Réapprovisionnement passé depuis l\'écran de suivi.',
                    'lignes' => $lignes,
                ]);

                // Validée par défaut, et ce n'est pas du confort : un brouillon
                // ne compte pas dans « en commande ». Le laisser en brouillon
                // laisserait la suggestion inchangée à l'écran, et le clic
                // suivant créerait un doublon pour la même marchandise.
                if ($valider) {
                    $document = $this->achats->valider($document);
                }

                $creees[] = $this->resume($document);
            }

            return $creees;
        });

        return ['commandes' => $commandes, 'ignores' => $ignores];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Les produits, portant les mêmes quantités calculées que l'écran d'alertes
     * — c'est ce que `ReapproService` attend en entrée.
     *
     * @param  list<int>  $produitIds
     * @return Collection<int, Produit>
     */
    private function chargerAvecLeursQuantites(array $produitIds, ?int $entrepotId): Collection
    {
        return Produit::query()
            ->whereIn('id', $produitIds)
            ->where('type', 'product')
            ->addSelect(['stock_quantite' => Stock::query()
                ->selectRaw('COALESCE(SUM(quantite), 0)')
                ->whereColumn('produit_id', 'produits.id')
                ->when($entrepotId, fn ($q) => $q->where('entrepot_id', $entrepotId)),
            ])
            ->addSelect(['en_commande' => DocumentAchatLigne::query()
                ->selectRaw('COALESCE(SUM(quantite - quantite_recue), 0)')
                ->whereColumn('produit_id', 'produits.id')
                ->whereHas('document', fn ($q) => $q
                    ->where('type', DocumentAchat::TYPE_COMMANDE)
                    ->whereIn('statut', [DocumentAchat::STATUT_VALIDE, DocumentAchat::STATUT_RECUE_PARTIELLE])
                    ->when($entrepotId, fn ($qq) => $qq->where(fn ($w) => $w
                        ->where('entrepot_id', $entrepotId)
                        ->orWhereNull('entrepot_id')))),
            ])
            ->get();
    }

    /**
     * Le tiers est-il encore un fournisseur ?
     *
     * Le scope d'entreprise et la corbeille s'appliquent : un identifiant venu
     * d'ailleurs, ou un tiers supprimé, ressort simplement `null`.
     */
    private function fournisseurUtilisable(?int $tiersId): ?int
    {
        if ($tiersId === null) {
            return null;
        }

        return Tiers::query()
            ->whereKey($tiersId)
            ->where('is_supplier', true)
            ->value('id');
    }

    /** @return array<string, mixed> */
    private function ignore(Produit $produit, string $raison): array
    {
        return [
            'produit_id' => $produit->id,
            'code' => $produit->code,
            'name' => $produit->name,
            'raison' => $raison,
        ];
    }

    /** @return array<string, mixed> */
    private function resume(DocumentAchat $document): array
    {
        $document->loadMissing(['tiers:id,name', 'entrepot:id,name', 'lignes']);

        return [
            'id' => $document->id,
            'code' => $document->code,
            'statut' => $document->statut,
            'fournisseur' => [
                'id' => $document->tiers?->id,
                'name' => $document->tiers?->name,
            ],
            'entrepot' => $document->entrepot === null ? null : [
                'id' => $document->entrepot->id,
                'name' => $document->entrepot->name,
            ],
            'nb_lignes' => $document->lignes->count(),
            'total_ht' => number_format((float) $document->total_ht, 2, '.', ''),
            'total_ttc' => number_format((float) $document->total_ttc, 2, '.', ''),
        ];
    }
}
