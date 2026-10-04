<?php

namespace App\Modules\Stock\Services;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Stock\Models\Inventaire;
use App\Modules\Stock\Models\InventaireLigne;
use App\Modules\Stock\Models\MouvementStock;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use Carbon\CarbonInterface;

/**
 * Diagnostic, en LECTURE SEULE : pour chaque famille de documents de vente de
 * l'entreprise courante, l'écart entre ce qui est sorti du stock et ce qui
 * aurait dû sortir selon la règle de BilanDeSortie.
 *
 * Il retrouve les sous-sorties du bug des BL partiels frères (le second BL ne
 * sortait rien) et les sur-sorties d'avant la règle de famille (BL et facture
 * frères sortant chacun). Il ne corrige rien : un inventaire fait depuis a pu
 * remettre le stock réel d'aplomb, et réécrire l'historique d'office le
 * fausserait à nouveau. Corriger est une décision humaine — d'où, pour chaque
 * écart, les dates des pièces, le client, l'entrepôt et le dernier inventaire
 * qui a compté l'article : sans eux, un écart déjà absorbé par un comptage
 * serait corrigé une seconde fois.
 *
 * Aucune écriture, aucun verrou : rien ici ne passe par StockService, dont
 * même les lectures (entrepotParDefaut, stockRow) peuvent créer des lignes.
 *
 * Limite : l'ordre des pièces validées avant l'introduction du rang de rejeu
 * (documents_vente.rang_stock) est relu à la seconde ; deux validations de la
 * même seconde peuvent y être rejouées dans le désordre.
 */
class VerificationFamilles
{
    /** Au-delà, un lot de familles se lit en plusieurs requêtes `IN (…)`. */
    private const IDS_PAR_LOT = 400;

    /** @var array<int, string> nom des entrepôts, par id */
    private array $entrepots = [];

    private ?int $entrepotParDefaut = null;

    public function __construct(private FamilleDocuments $familles) {}

    /**
     * @return array{familles: int, ecarts: list<array{
     *     racine: string, racine_type: string, client: ?string, produit: string,
     *     entrepot: string, du: float, sorti: float, ecart: float,
     *     inventaire: ?array{code: string, date: string, entrepot: string, posterieur: bool},
     *     pieces: list<array{code: string, type: string, date: ?string, porte: float, du: ?float, sorti: float, rentre: float}>,
     * }>}
     */
    public function ecarts(): array
    {
        $this->chargerEntrepots();

        $groupes = $this->groupes();
        $ecarts = [];
        $lot = [];
        $taille = 0;

        foreach ($groupes as $racine => $ids) {
            $lot[$racine] = $ids;
            $taille += count($ids);

            if ($taille >= self::IDS_PAR_LOT) {
                array_push($ecarts, ...$this->examiner($lot));
                $lot = [];
                $taille = 0;
            }
        }

        if ($lot !== []) {
            array_push($ecarts, ...$this->examiner($lot));
        }

        $ecarts = $this->completer($ecarts);

        usort($ecarts, fn ($a, $b) => [$a['racine'], $a['produit']] <=> [$b['racine'], $b['produit']]);

        return ['familles' => count($groupes), 'ecarts' => $ecarts];
    }

    /**
     * Les familles d'au moins deux documents, indexées par leur racine.
     *
     * Une pièce seule ne peut pas être touchée : sans famille, elle sort tout ce
     * qu'elle porte (chemin rapide). Même définition que racine() et
     * descendance() — remonter les sources tant que le parent existe — mais
     * calculée en une passe pour toute l'entreprise plutôt que pièce par pièce.
     *
     * @return array<int, list<int>>
     */
    private function groupes(): array
    {
        $parents = DocumentVente::query()
            ->whereNotNull('source_document_id')
            ->pluck('source_document_id', 'id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Un parent supprimé coupe la chaîne, comme DocumentVente::find() dans
        // racine() : la pièce devient alors sa propre racine.
        $vivants = [];

        foreach (array_chunk(array_values(array_unique($parents)), self::IDS_PAR_LOT) as $paquet) {
            foreach (DocumentVente::whereIn('id', $paquet)->pluck('id') as $id) {
                $vivants[(int) $id] = true;
            }
        }

        $racines = [];
        $groupes = [];

        foreach (array_keys($parents) as $enfant) {
            $chemin = [];
            $courant = (int) $enfant;

            // Même borne que racine() : au-delà de dix maillons, la donnée est
            // corrompue.
            for ($i = 0; $i < 10 && ! isset($racines[$courant]) && isset($vivants[$parents[$courant] ?? 0]); $i++) {
                $chemin[] = $courant;
                $courant = $parents[$courant];
            }

            $racine = $racines[$courant] ?? $courant;

            foreach ([...$chemin, $courant] as $id) {
                $racines[$id] = $racine;
                $groupes[$racine][$id] = $id;
            }
        }

        return array_map(
            'array_values',
            array_filter($groupes, fn (array $ids) => count($ids) >= 2),
        );
    }

    /**
     * @param  array<int, list<int>>  $lot  familles indexées par leur racine
     * @return list<array<string, mixed>>
     */
    private function examiner(array $lot): array
    {
        $familleDe = [];

        foreach ($lot as $racine => $ids) {
            foreach ($ids as $id) {
                $familleDe[$id] = $racine;
            }
        }

        $pieces = $this->familles->piecesDuBilan(array_keys($familleDe));

        if ($pieces->isEmpty()) {
            return [];
        }

        // Archivés compris : leurs ventes passées ont bien fait sortir le stock.
        $produits = $this->familles->produitsDe($pieces, avecArchives: true);
        $mouvements = $this->mouvements($pieces->pluck('id')->all());
        $racines = DocumentVente::whereIn('id', array_keys($lot))->get(['id', 'code', 'type', 'tiers_id'])->keyBy('id');

        $ecarts = [];

        // groupBy garde l'ordre de rejeu de piecesDuBilan à l'intérieur de
        // chaque famille : le bilan se rejoue dans cet ordre.
        foreach ($pieces->groupBy(fn (DocumentVente $p) => $familleDe[$p->id]) as $racine => $piecesDeLaFamille) {
            $bilan = new BilanDeSortie;
            $parProduit = [];

            foreach ($piecesDeLaFamille as $piece) {
                $porte = $this->familles->quantites($this->familles->elementsDeStock($piece, $produits));
                $dues = $bilan->appliquer($piece->type, $porte);
                $avoir = $piece->type === DocumentVente::TYPE_AVOIR;

                // Les articles que la pièce porte AUJOURD'HUI, et ceux qu'elle
                // a fait bouger : un kit recomposé depuis peut avoir sorti un
                // composant qu'il ne contient plus.
                $touches = array_unique([...array_keys($porte), ...array_keys($mouvements[$piece->id] ?? [])]);

                foreach ($touches as $produitId) {
                    $du = $avoir ? null : ($dues[$produitId] ?? 0.0);
                    $sorti = $mouvements[$piece->id][$produitId][MouvementStock::TYPE_VENTE] ?? 0.0;
                    $ligne = &$parProduit[$produitId];

                    $ligne['du'] = ($ligne['du'] ?? 0.0) + ($du ?? 0.0);
                    $ligne['sorti'] = ($ligne['sorti'] ?? 0.0) + $sorti;
                    $ligne['entrepots'] ??= [];
                    $ligne['derniere'] = $this->plusRecente($ligne['derniere'] ?? null, $piece->validated_at);

                    // Entrepôt de la pièce, sinon celui par défaut d'AUJOURD'HUI
                    // (c'est lui que la validation aurait pris).
                    $entrepotId = $piece->entrepot_id ?? $this->entrepotParDefaut;

                    if ($entrepotId !== null) {
                        $ligne['entrepots'][$entrepotId] = true;
                    }

                    // La pièce fautive la plus récente : un inventaire validé
                    // après elle a compté le stock réel, écart compris.
                    if ($du !== null && abs($du - $sorti) >= 0.0005) {
                        $ligne['fautive'] = $this->plusRecente($ligne['fautive'] ?? null, $piece->validated_at);
                    }

                    $ligne['pieces'][] = [
                        'code' => $piece->code,
                        'type' => $piece->type,
                        'date' => $piece->validated_at?->format('d/m/Y'),
                        'porte' => $porte[$produitId] ?? 0.0,
                        'du' => $du,
                        'sorti' => $sorti,
                        'rentre' => $mouvements[$piece->id][$produitId][MouvementStock::TYPE_RETOUR] ?? 0.0,
                    ];
                    unset($ligne);
                }
            }

            foreach ($parProduit as $produitId => $ligne) {
                $ecart = round($ligne['sorti'] - $ligne['du'], 3);

                if (abs($ecart) < 0.0005) {
                    continue;
                }

                $ecarts[] = [
                    'racine' => $racines->get($racine)?->code ?? '#'.$racine,
                    'racine_type' => $racines->get($racine)?->type ?? '',
                    'tiers_id' => $racines->get($racine)?->tiers_id,
                    'produit_id' => $produitId,
                    'entrepot_ids' => array_keys($ligne['entrepots']),
                    // Un écart a toujours une pièce fautive, sauf sortie de
                    // vente écrite sur un avoir : la plus récente, par prudence.
                    'fautive' => $ligne['fautive'] ?? $ligne['derniere'],
                    'du' => round($ligne['du'], 3),
                    'sorti' => round($ligne['sorti'], 3),
                    'ecart' => $ecart,
                    'pieces' => $ligne['pieces'],
                ];
            }
        }

        return $ecarts;
    }

    /**
     * Libellés de l'article et du client, entrepôt, dernier inventaire : en une
     * passe pour tous les écarts plutôt que par lot de familles.
     *
     * @param  list<array<string, mixed>>  $ecarts
     * @return list<array<string, mixed>>
     */
    private function completer(array $ecarts): array
    {
        if ($ecarts === []) {
            return [];
        }

        $produitIds = array_values(array_unique(array_column($ecarts, 'produit_id')));

        // Un article archivé depuis garde son libellé : ses ventes passées
        // sont dans le bilan (produitsDe avec les archivés).
        $libelles = Produit::withTrashed()->whereIn('id', $produitIds)->get(['id', 'code', 'name'])->keyBy('id');
        $clients = Tiers::withTrashed()
            ->whereIn('id', array_values(array_unique(array_filter(array_column($ecarts, 'tiers_id')))))
            ->pluck('name', 'id');
        $inventaires = $this->derniersInventaires($produitIds);

        return array_map(function (array $ecart) use ($libelles, $clients, $inventaires) {
            $produit = $libelles->get($ecart['produit_id']);
            $inventaire = null;

            foreach ($ecart['entrepot_ids'] as $entrepotId) {
                $candidat = $inventaires[$ecart['produit_id']][$entrepotId] ?? null;

                if ($candidat !== null && ($inventaire === null || $candidat->validated_at->gt($inventaire->validated_at))) {
                    $inventaire = $candidat;
                }
            }

            return [
                'racine' => $ecart['racine'],
                'racine_type' => $ecart['racine_type'],
                'client' => $clients->get($ecart['tiers_id']),
                'produit' => match (true) {
                    $produit === null => '#'.$ecart['produit_id'],
                    blank($produit->code) => $produit->name,
                    default => $produit->name.' ('.$produit->code.')',
                },
                'entrepot' => implode(', ', array_map(
                    fn ($id) => $this->entrepots[$id] ?? '#'.$id,
                    $ecart['entrepot_ids'],
                )) ?: '—',
                'du' => $ecart['du'],
                'sorti' => $ecart['sorti'],
                'ecart' => $ecart['ecart'],
                'inventaire' => $inventaire === null ? null : [
                    'code' => $inventaire->code,
                    'date' => $inventaire->validated_at->format('d/m/Y'),
                    'entrepot' => $this->entrepots[$inventaire->entrepot_id] ?? '#'.$inventaire->entrepot_id,
                    // Postérieur à la dernière pièce fautive : le comptage a
                    // mesuré le stock réel, écart compris, et l'a recalé.
                    'posterieur' => $ecart['fautive'] !== null && $inventaire->validated_at->gt($ecart['fautive']),
                ],
                'pieces' => $ecart['pieces'],
            ];
        }, $ecarts);
    }

    /**
     * Le dernier inventaire validé qui a COMPTÉ chaque article, par entrepôt.
     *
     * @param  list<int>  $produitIds
     * @return array<int, array<int, Inventaire>>
     */
    private function derniersInventaires(array $produitIds): array
    {
        $derniers = [];

        foreach (array_chunk($produitIds, self::IDS_PAR_LOT) as $paquet) {
            $lignes = InventaireLigne::query()
                ->whereIn('produit_id', $paquet)
                ->whereNotNull('quantite_comptee')
                ->whereHas('inventaire', fn ($q) => $q->where('statut', Inventaire::STATUT_VALIDE)->whereNotNull('validated_at'))
                ->with('inventaire:id,code,entrepot_id,validated_at')
                ->get(['id', 'inventaire_id', 'produit_id']);

            foreach ($lignes as $ligne) {
                $inventaire = $ligne->inventaire;
                $actuel = $derniers[$ligne->produit_id][$inventaire->entrepot_id] ?? null;

                if ($actuel === null || $inventaire->validated_at->gt($actuel->validated_at)) {
                    $derniers[$ligne->produit_id][$inventaire->entrepot_id] = $inventaire;
                }
            }
        }

        return $derniers;
    }

    private function plusRecente(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $b->gt($a) ? $b : $a,
        };
    }

    /** Lu tel quel, sans StockService::entrepotParDefaut() qui en créerait un. */
    private function chargerEntrepots(): void
    {
        $entrepots = Entrepot::query()->orderBy('id')->get(['id', 'name', 'is_default']);

        $this->entrepots = $entrepots->pluck('name', 'id')->all();
        $this->entrepotParDefaut = $entrepots->firstWhere('is_default', true)?->id ?? $entrepots->first()?->id;
    }

    /**
     * Sorties de vente et retours d'avoir déjà écrits, par pièce, par produit.
     *
     * @param  list<int>  $ids
     * @return array<int, array<int, array<string, float>>>
     */
    private function mouvements(array $ids): array
    {
        $totaux = [];

        foreach (array_chunk($ids, self::IDS_PAR_LOT) as $paquet) {
            $lignes = MouvementStock::query()
                ->whereIn('document_vente_id', $paquet)
                ->whereIn('type', [MouvementStock::TYPE_VENTE, MouvementStock::TYPE_RETOUR])
                ->selectRaw('document_vente_id, produit_id, type, SUM(quantite) as total')
                ->groupBy('document_vente_id', 'produit_id', 'type')
                ->get();

            foreach ($lignes as $ligne) {
                // Une sortie est écrite en négatif, un retour en positif : on
                // compare des quantités.
                $totaux[(int) $ligne->document_vente_id][(int) $ligne->produit_id][$ligne->type] = abs(round((float) $ligne->total, 3));
            }
        }

        return $totaux;
    }
}
