<?php

namespace App\Modules\Stock\Services;

use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Stock\Models\MouvementStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Quantité à commander, déduite des ventes passées.
 *
 * La phrase que le calcul doit tenir, et que le grossiste doit pouvoir se
 * répéter : « je vends X par jour où j'avais du stock, je veux tenir N jours
 * devant moi, il me faut donc X × N, moins ce que j'ai et ce qui est en route ».
 *
 * Trois partis pris, tous défendables à voix haute :
 *
 *  1. LA MOYENNE SE CALCULE SUR LES JOURS OÙ L'ARTICLE ÉTAIT DISPONIBLE, pas
 *     sur les jours du calendrier. Sans cela, un article resté en rupture trois
 *     semaines voit sa moyenne s'effondrer, on en recommande moins, et il est de
 *     nouveau en rupture — le système entretiendrait le mal qu'il doit soigner.
 *     C'est le piège dit de la « demande censurée ».
 *  2. QUAND L'HISTORIQUE NE PERMET PAS DE CALCULER HONNÊTEMENT, on ne devine
 *     pas : on redescend sur le seuil saisi, ou on n'affiche rien. Le chiffre
 *     porte toujours son origine, pour que personne ne le prenne pour ce qu'il
 *     n'est pas.
 *  3. RIEN N'EST AUTOMATIQUE. Cette classe propose un nombre ; la commande reste
 *     un geste humain.
 */
class ReapproService
{
    /** Fenêtre d'observation des ventes, en jours. */
    public const FENETRE_JOURS = 90;

    /** Combien de jours de vente on veut avoir devant soi (fréquence de commande). */
    public const COUVERTURE_JOURS = 30;

    /** Délai entre la commande au fournisseur et l'arrivée de la marchandise. */
    public const DELAI_APPRO_JOURS = 7;

    /** Marge tampon, exprimée en jours de vente et non en écart-type. */
    public const SECURITE_JOURS = 7;

    /** En deçà, l'historique est trop court pour qu'une moyenne veuille dire quelque chose. */
    public const JOURS_MIN_HISTORIQUE = 14;

    /**
     * Plancher de jours vendables, en part de la fenêtre. C'est la contrepartie
     * indispensable de la correction de rupture : sans lui, un article
     * disponible deux jours sur quatre-vingt-dix produirait une moyenne
     * journalière délirante, et donc une commande démesurée.
     */
    private const PART_MIN_JOURS_VENDABLES = 0.25;

    /**
     * @param  Collection<int, \App\Modules\Catalogue\Models\Produit>  $produits
     *         chargés avec les attributs calculés `stock_quantite` et `en_commande`
     * @return array<int, array<string, mixed>>  indexé par produit_id
     */
    public function pour(Collection $produits, ?int $entrepotId = null): array
    {
        if ($produits->isEmpty()) {
            return [];
        }

        $ids = $produits->pluck('id')->all();
        $depuis = now()->subDays(self::FENETRE_JOURS);
        $mouvements = $this->mouvementsParProduit($ids, $depuis, $entrepotId);
        $achats = $this->dernierAchatParProduit($ids);

        $resultats = [];

        foreach ($produits as $produit) {
            $resultats[$produit->id] = $this->calculer(
                $produit,
                $mouvements->get($produit->id, collect()),
                $depuis,
            ) + ($achats[$produit->id] ?? [
                'fournisseur_id' => null,
                'fournisseur_nom' => null,
                'dernier_prix_achat' => null,
            ]);
        }

        return $resultats;
    }

    /**
     * Fournisseur habituel et dernier prix payé, par produit.
     *
     * Rien en base ne désigne le fournisseur d'un article : il n'existe aucune
     * table qui relie les deux. On le DÉDUIT donc du dernier achat réel — la
     * seule trace disponible. C'est une suggestion, pas une vérité : elle
     * remplit le formulaire, l'acheteur garde la main.
     *
     * @param  array<int, int>  $produitIds
     * @return array<int, array<string, mixed>>
     */
    private function dernierAchatParProduit(array $produitIds): array
    {
        return DocumentAchatLigne::query()
            ->whereIn('produit_id', $produitIds)
            ->whereHas('document', fn ($q) => $q->whereNotNull('validated_at'))
            ->with(['document:id,tiers_id,validated_at', 'document.tiers:id,name'])
            ->orderByDesc('id')
            ->get(['id', 'document_achat_id', 'produit_id', 'prix_unitaire'])
            // Le plus récent l'emporte : un fournisseur qu'on a quitté il y a
            // deux ans ne doit pas ressortir parce qu'on lui a beaucoup acheté.
            ->groupBy('produit_id')
            ->map(function (Collection $lignes) {
                $recente = $lignes
                    ->sortByDesc(fn (DocumentAchatLigne $l) => $l->document?->validated_at)
                    ->first();

                return [
                    'fournisseur_id' => $recente->document?->tiers_id,
                    'fournisseur_nom' => $recente->document?->tiers?->name,
                    'dernier_prix_achat' => $recente->prix_unitaire !== null
                        ? (float) $recente->prix_unitaire
                        : null,
                ];
            })
            ->all();
    }

    /**
     * Tous les mouvements de la fenêtre, groupés par produit.
     *
     * On prend TOUS les types, pas seulement les ventes : la reconstitution du
     * niveau de stock passé a besoin des entrées et des ajustements pour être
     * juste. Le tri du plus récent au plus ancien sert à remonter le temps
     * depuis le stock d'aujourd'hui.
     *
     * @param  array<int, int>  $produitIds
     * @return Collection<int, Collection<int, MouvementStock>>
     */
    private function mouvementsParProduit(array $produitIds, Carbon $depuis, ?int $entrepotId): Collection
    {
        return MouvementStock::query()
            ->whereIn('produit_id', $produitIds)
            ->where('created_at', '>=', $depuis)
            ->when($entrepotId !== null, fn ($q) => $q->where('entrepot_id', $entrepotId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'produit_id', 'type', 'quantite', 'created_at'])
            ->groupBy('produit_id');
    }

    /**
     * @param  Collection<int, MouvementStock>  $mouvements  du plus récent au plus ancien
     * @return array<string, mixed>
     */
    private function calculer($produit, Collection $mouvements, Carbon $depuis): array
    {
        $stock = (float) ($produit->stock_quantite ?? 0);
        $enCommande = (float) ($produit->en_commande ?? 0);

        // Demande NETTE : les ventes sont négatives, les retours d'avoir positifs.
        // La négation de la somme donne donc directement ce qui est réellement parti.
        $demande = -1 * $mouvements
            ->whereIn('type', [MouvementStock::TYPE_VENTE, MouvementStock::TYPE_RETOUR])
            ->sum(fn (MouvementStock $m) => (float) $m->quantite);
        $demande = round($demande, 3);

        $joursRupture = $this->joursDeRupture($mouvements, $stock, $depuis);

        // Un article créé pendant la fenêtre n'a pas pu vendre avant d'exister.
        $ageJours = $produit->created_at !== null
            ? min(self::FENETRE_JOURS, (int) $produit->created_at->diffInDays(now()))
            : self::FENETRE_JOURS;

        $fenetre = max(1, $ageJours);
        $joursVendables = max(
            $fenetre - $joursRupture,
            (int) ceil(self::PART_MIN_JOURS_VENDABLES * $fenetre),
        );

        $horizon = self::DELAI_APPRO_JOURS + self::COUVERTURE_JOURS + self::SECURITE_JOURS;

        $base = [
            'demande_periode' => $demande,
            'jours_rupture' => $joursRupture,
            'jours_vendables' => $joursVendables,
            'horizon_jours' => $horizon,
            'fenetre_jours' => $fenetre,
        ];

        $historiqueSuffisant = $demande > 0 && ($fenetre - $joursRupture) >= self::JOURS_MIN_HISTORIQUE;
        $historiqueCourt = $demande > 0 && $ageJours >= 7;

        if ($historiqueSuffisant || $historiqueCourt) {
            $consoJour = round($demande / $joursVendables, 4);
            $besoin = $consoJour * $horizon;

            return $base + [
                'origine' => $historiqueSuffisant ? 'ventes' : 'ventes_court',
                'conso_jour' => $consoJour,
                // Jours de vente que le stock actuel permet encore de tenir :
                // c'est ce chiffre-là qui fait agir, plus que la quantité.
                'couverture_restante' => $consoJour > 0 ? round(max(0, $stock) / $consoJour, 1) : null,
                // Toujours arrondi au SUPÉRIEUR : arrondir vers le bas fabrique
                // la rupture qu'on cherche à éviter.
                'suggestion' => (float) ceil(max(0, $besoin - $stock - $enCommande)),
            ];
        }

        // Pas d'historique exploitable : on retombe sur le seuil saisi à la main,
        // c'est-à-dire exactement la formule d'avant.
        if ($produit->stock_min !== null) {
            $cible = (float) ($produit->stock_reappro ?? $produit->stock_min);

            return $base + [
                'origine' => 'seuil',
                'conso_jour' => 0.0,
                'couverture_restante' => null,
                'suggestion' => max(0, round($cible - $stock - $enCommande, 3)),
            ];
        }

        // Ni ventes ni seuil : on n'invente pas de chiffre.
        return $base + [
            'origine' => 'sans_historique',
            'conso_jour' => 0.0,
            'couverture_restante' => null,
            'suggestion' => null,
        ];
    }

    /**
     * Nombre de jours, dans la fenêtre, où l'article était à zéro ou en négatif.
     *
     * Le niveau passé est RECONSTITUÉ en remontant le temps depuis le stock
     * d'aujourd'hui, mouvement après mouvement. On ne se sert pas de la colonne
     * `quantite_apres` : elle ne vaut que pour un entrepôt, alors que la vue par
     * défaut agrège tous les dépôts.
     *
     * @param  Collection<int, MouvementStock>  $mouvements  du plus récent au plus ancien
     */
    private function joursDeRupture(Collection $mouvements, float $stockActuel, Carbon $depuis): int
    {
        $niveau = $stockActuel;
        $borneHaute = now();
        $heuresEnRupture = 0.0;

        foreach ($mouvements as $mouvement) {
            // Entre ce mouvement et le suivant, le niveau n'a pas bougé.
            if ($niveau <= 0.0005) {
                $heuresEnRupture += $mouvement->created_at->diffInHours($borneHaute);
            }

            $niveau = round($niveau - (float) $mouvement->quantite, 3);
            $borneHaute = $mouvement->created_at;
        }

        // Depuis le début de la fenêtre jusqu'au premier mouvement observé.
        if ($niveau <= 0.0005) {
            $heuresEnRupture += $depuis->diffInHours($borneHaute);
        }

        return (int) floor($heuresEnRupture / 24);
    }
}
