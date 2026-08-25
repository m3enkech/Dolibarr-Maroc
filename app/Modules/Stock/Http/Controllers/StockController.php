<?php

namespace App\Modules\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Http\Requests\StoreMouvementRequest;
use App\Modules\Stock\Http\Requests\StoreTransfertRequest;
use App\Modules\Stock\Http\Resources\MouvementResource;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Stock\Models\MouvementStock;
use App\Modules\Stock\Models\Stock;
use App\Modules\Stock\Services\ReapproService;
use App\Modules\Stock\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockController extends Controller
{
    public function __construct(private StockService $service, private ReapproService $reappro) {}

    /** Niveaux de stock agrégés par produit (optionnellement filtrés par entrepôt). */
    public function niveaux(Request $request): JsonResponse
    {
        $entrepotId = $request->integer('entrepot_id') ?: null;

        $produits = Produit::query()
            ->where('type', 'product')
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search));
            })
            ->addSelect(['stock_quantite' => Stock::query()
                ->selectRaw('COALESCE(SUM(quantite), 0)')
                ->whereColumn('produit_id', 'produits.id')
                ->when($entrepotId, fn ($q) => $q->where('entrepot_id', $entrepotId)),
            ])
            // Quantités attendues : lignes de commandes fournisseur validées
            // non soldées (reste à recevoir).
            ->addSelect(['en_commande' => $this->enCommandeSubquery()])
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => collect($produits->items())->map(fn (Produit $produit) => [
                'produit_id' => $produit->id,
                'code' => $produit->code,
                'name' => $produit->name,
                'unit' => $produit->unit,
                'quantite' => number_format((float) $produit->stock_quantite, 3, '.', ''),
                'en_commande' => number_format((float) $produit->en_commande, 3, '.', ''),
                'stock_min' => $produit->stock_min !== null
                    ? number_format((float) $produit->stock_min, 3, '.', '')
                    : null,
                'sous_seuil' => $produit->stock_min !== null
                    && (float) $produit->stock_quantite <= (float) $produit->stock_min,
                'valeur_achat' => $produit->buy_price !== null
                    ? number_format((float) $produit->stock_quantite * (float) $produit->buy_price, 2, '.', '')
                    : null,
            ]),
            'meta' => [
                'current_page' => $produits->currentPage(),
                'last_page' => $produits->lastPage(),
                'per_page' => $produits->perPage(),
                'total' => $produits->total(),
            ],
        ]);
    }

    public function mouvements(Request $request): AnonymousResourceCollection
    {
        $mouvements = MouvementStock::query()
            ->with(['produit', 'entrepot'])
            ->when($request->integer('produit_id'), fn ($q, $id) => $q->where('produit_id', $id))
            ->when($request->integer('entrepot_id'), fn ($q, $id) => $q->where('entrepot_id', $id))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return MouvementResource::collection($mouvements);
    }

    public function creerMouvement(StoreMouvementRequest $request): MouvementResource
    {
        $data = $request->validated();
        $produit = Produit::findOrFail($data['produit_id']);
        $entrepot = Entrepot::findOrFail($data['entrepot_id']);
        $quantite = (float) $data['quantite'];
        $note = $data['note'] ?? null;

        $mouvement = match ($data['type']) {
            MouvementStock::TYPE_ENTREE => $this->service->entree($produit, $entrepot, $quantite, $note),
            MouvementStock::TYPE_SORTIE => $this->service->sortie($produit, $entrepot, $quantite, $note),
            MouvementStock::TYPE_AJUSTEMENT => $this->service->ajuster($produit, $entrepot, $quantite, $note),
        };

        return new MouvementResource($mouvement->load(['produit', 'entrepot']));
    }

    /**
     * Produits à réapprovisionner, avec la quantité suggérée.
     *
     * Deux populations, et non plus une seule : les produits sous leur seuil
     * saisi à la main, ET ceux qui ont vendu récemment. La seconde est la raison
     * d'être du calcul sur les ventes — s'en tenir au seuil reviendrait à ne
     * conseiller que les articles déjà paramétrés, c'est-à-dire presque aucun.
     *
     * La quantité vient de ReapproService, qui indique aussi d'où elle sort :
     * des ventes observées, ou du seuil saisi quand l'historique ne permet pas
     * de calculer honnêtement.
     */
    public function alertes(Request $request): JsonResponse
    {
        $entrepotId = $request->integer('entrepot_id') ?: null;
        $depuis = now()->subDays(ReapproService::FENETRE_JOURS);

        $produits = Produit::query()
            ->where('type', 'product')
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->whereNotNull('stock_min')
                ->orWhereExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('mouvements_stock')
                    // Corrélation sur le tenant : cette sous-requête brute ne
                    // passe pas par le scope global, l'omettre ferait entrer les
                    // ventes d'une autre entreprise dans le périmètre.
                    ->whereColumn('mouvements_stock.tenant_id', 'produits.tenant_id')
                    ->whereColumn('mouvements_stock.produit_id', 'produits.id')
                    ->where('mouvements_stock.type', MouvementStock::TYPE_VENTE)
                    ->where('mouvements_stock.created_at', '>=', $depuis)
                    ->when($entrepotId, fn ($qq) => $qq->where('mouvements_stock.entrepot_id', $entrepotId))))
            ->addSelect(['stock_quantite' => Stock::query()
                ->selectRaw('COALESCE(SUM(quantite), 0)')
                ->whereColumn('produit_id', 'produits.id')
                ->when($entrepotId, fn ($q) => $q->where('entrepot_id', $entrepotId)),
            ])
            ->addSelect(['en_commande' => $this->enCommandeSubquery($entrepotId)])
            ->orderBy('name')
            ->get();

        $calculs = $this->reappro->pour($produits, $entrepotId);

        $lignes = $produits
            ->map(function (Produit $p) use ($calculs) {
                $calcul = $calculs[$p->id];
                $suggestion = $calcul['suggestion'];

                return [
                    'produit_id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'unit' => $p->unit,
                    'quantite' => number_format((float) $p->stock_quantite, 3, '.', ''),
                    'stock_min' => $p->stock_min !== null
                        ? number_format((float) $p->stock_min, 3, '.', '')
                        : null,
                    'stock_reappro' => $p->stock_reappro !== null
                        ? number_format((float) $p->stock_reappro, 3, '.', '')
                        : null,
                    'en_commande' => number_format((float) $p->en_commande, 3, '.', ''),
                    'suggestion' => $suggestion !== null
                        ? number_format($suggestion, 3, '.', '')
                        : null,
                    // D'où sort le chiffre : c'est ce qui le rend défendable.
                    'origine' => $calcul['origine'],
                    'conso_jour' => number_format($calcul['conso_jour'], 3, '.', ''),
                    'couverture_restante' => $calcul['couverture_restante'],
                    'demande_periode' => number_format($calcul['demande_periode'], 3, '.', ''),
                    'jours_rupture' => $calcul['jours_rupture'],
                    'fenetre_jours' => $calcul['fenetre_jours'],
                    'horizon_jours' => $calcul['horizon_jours'],
                ];
            })
            // Un produit suivi par seuil garde son critère d'affichage
            // historique ; un produit suivi par ses ventes n'apparaît que s'il
            // y a réellement quelque chose à commander.
            ->filter(function (array $ligne) use ($produits) {
                if ($ligne['origine'] === 'seuil') {
                    $p = $produits->firstWhere('id', $ligne['produit_id']);

                    return (float) $p->stock_quantite <= (float) $p->stock_min;
                }

                return $ligne['suggestion'] !== null && (float) $ligne['suggestion'] > 0;
            })
            ->values();

        return response()->json([
            'data' => $lignes,
            // Les hypothèses du calcul, affichées à l'écran : un chiffre dont on
            // ne connaît pas les hypothèses ne se discute pas.
            'hypotheses' => [
                'fenetre_jours' => ReapproService::FENETRE_JOURS,
                'couverture_jours' => ReapproService::COUVERTURE_JOURS,
                'delai_appro_jours' => ReapproService::DELAI_APPRO_JOURS,
                'securite_jours' => ReapproService::SECURITE_JOURS,
            ],
        ]);
    }

    /** Transfert d'une quantité d'un entrepôt à un autre (sortie + entrée liées). */
    public function transferer(StoreTransfertRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->service->transferer(
            Produit::findOrFail($data['produit_id']),
            Entrepot::findOrFail($data['entrepot_source_id']),
            Entrepot::findOrFail($data['entrepot_dest_id']),
            (float) $data['quantite'],
            $data['note'] ?? null,
        );

        return response()->json([
            'reference' => $result['reference'],
            'sortie' => new MouvementResource($result['sortie']->load(['produit', 'entrepot'])),
            'entree' => new MouvementResource($result['entree']->load(['produit', 'entrepot'])),
        ], 201);
    }

    /**
     * Reste à recevoir sur les commandes fournisseur validées non soldées.
     *
     * Filtré par entrepôt quand la vue l'est : compter, dans le dépôt de
     * Casablanca, une commande attendue à Agadir ferait croire que le réappro
     * est déjà lancé. Une commande sans entrepôt désigné reste comptée partout,
     * faute de mieux.
     */
    private function enCommandeSubquery(?int $entrepotId = null): \Illuminate\Database\Eloquent\Builder
    {
        return DocumentAchatLigne::query()
            ->selectRaw('COALESCE(SUM(quantite - quantite_recue), 0)')
            ->whereColumn('produit_id', 'produits.id')
            ->whereHas('document', fn ($q) => $q
                ->where('type', DocumentAchat::TYPE_COMMANDE)
                ->whereIn('statut', [
                    DocumentAchat::STATUT_VALIDE,
                    DocumentAchat::STATUT_RECUE_PARTIELLE,
                ])
                ->when($entrepotId, fn ($qq) => $qq->where(fn ($w) => $w
                    ->where('entrepot_id', $entrepotId)
                    ->orWhereNull('entrepot_id'))));
    }
}
