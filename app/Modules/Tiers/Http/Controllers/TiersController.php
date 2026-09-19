<?php

namespace App\Modules\Tiers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Tiers\Http\Requests\StoreTiersRequest;
use App\Modules\Tiers\Http\Requests\UpdateTiersRequest;
use App\Modules\Tiers\Http\Resources\TiersResource;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\DocumentVenteLigne;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TiersController extends Controller
{
    public function __construct(private TiersService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tiers = Tiers::query()
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                // whereLike : `LIKE` est sensible à la casse sur PostgreSQL et
                // insensible sur SQLite — voir ProduitsController pour le détail.
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->whereLike('name', $search)
                    ->orWhereLike('code', $search)
                    ->orWhereLike('ice', $search));
            })
            // Un prospect reste un client potentiel (is_client=true) : le filtre
            // « client » ne montre que les clients déjà convertis.
            ->when($request->string('type')->toString() === 'client',
                fn ($q) => $q->where('is_client', true)->where('is_prospect', false))
            ->when($request->string('type')->toString() === 'prospect', fn ($q) => $q->where('is_prospect', true))
            ->when($request->string('type')->toString() === 'fournisseur', fn ($q) => $q->where('is_supplier', true))
            ->when($request->string('lead_source')->isNotEmpty(),
                fn ($q) => $q->where('lead_source', $request->string('lead_source')->toString()))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return TiersResource::collection($tiers);
    }

    public function store(StoreTiersRequest $request): TiersResource
    {
        return new TiersResource($this->service->create($request->validated()));
    }

    public function show(Tiers $tiers): TiersResource
    {
        return new TiersResource($tiers);
    }

    public function update(UpdateTiersRequest $request, Tiers $tiers): TiersResource
    {
        return new TiersResource($this->service->update($tiers, $request->validated()));
    }

    /** Encours et plafond de crédit d'un client (contrôle avant vente à crédit). */
    /**
     * La synthèse chiffrée d'un tiers : ce qu'on veut savoir avant d'ouvrir
     * quoi que ce soit.
     *
     * Des COMPTES, pas des listes. Les onglets de la fiche vont chercher leurs
     * pièces eux-mêmes, paginées, quand on les ouvre : charger ici les quatre
     * cents factures d'un gros client pour n'en afficher que le nombre serait
     * payer la page entière pour un chiffre.
     */
    public function synthese(Tiers $tiers): JsonResponse
    {
        // Un seul balayage des documents de vente pour tous les comptes par
        // type — cinq requêtes séparées diraient la même chose cinq fois.
        $parType = DocumentVente::query()
            ->where('tiers_id', $tiers->id)
            ->selectRaw('type, COUNT(*) AS total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $facturesEmises = DocumentVente::query()
            ->where('tiers_id', $tiers->id)
            ->where('type', DocumentVente::TYPE_FACTURE)
            ->whereIn('statut', [DocumentVente::STATUT_VALIDE, DocumentVente::STATUT_PAYE]);

        // « Impayé » = facture émise et non soldée. VenteService bascule la
        // pièce en `paye` dès qu'elle l'est : `valide` SIGNIFIE donc « due »,
        // et il reste à retrancher les acomptes déjà encaissés.
        $duesTtc = (float) (clone $facturesEmises)->where('statut', DocumentVente::STATUT_VALIDE)->sum('total_ttc');
        $acomptes = (float) Paiement::query()
            ->whereHas('document', fn ($q) => $q
                ->where('tiers_id', $tiers->id)
                ->where('type', DocumentVente::TYPE_FACTURE)
                ->where('statut', DocumentVente::STATUT_VALIDE))
            ->sum('montant');

        return response()->json(['data' => [
            'ventes' => [
                'devis' => (int) ($parType[DocumentVente::TYPE_DEVIS] ?? 0),
                'commandes' => (int) ($parType[DocumentVente::TYPE_COMMANDE] ?? 0),
                'bons_livraison' => (int) ($parType[DocumentVente::TYPE_BON_LIVRAISON] ?? 0),
                'factures' => (int) ($parType[DocumentVente::TYPE_FACTURE] ?? 0),
                'avoirs' => (int) ($parType[DocumentVente::TYPE_AVOIR] ?? 0),
            ],
            'achats' => (int) DocumentAchat::where('tiers_id', $tiers->id)->count(),
            'contacts' => (int) $tiers->contacts()->count(),

            'ca_ttc' => $this->montant((clone $facturesEmises)->sum('total_ttc')),
            'ca_12_mois' => $this->montant(
                (clone $facturesEmises)->where('date_document', '>=', now()->subYear()->toDateString())->sum('total_ttc'),
            ),
            'impaye' => $this->montant(max($duesTtc - $acomptes, 0)),

            'achats_ttc' => $this->montant(
                DocumentAchat::where('tiers_id', $tiers->id)
                    ->where('type', DocumentAchat::TYPE_FACTURE)
                    ->whereIn('statut', ['valide', 'paye'])
                    ->sum('total_ttc'),
            ),

            'premier_document' => DocumentVente::where('tiers_id', $tiers->id)->min('date_document'),
            'dernier_document' => DocumentVente::where('tiers_id', $tiers->id)->max('date_document'),
        ]]);
    }

    /**
     * Ce que ce tiers achète — ou nous vend, s'il est fournisseur.
     *
     * La question qu'on se pose vraiment devant un client avant de l'appeler :
     * « qu'est-ce qu'il prend d'habitude, combien, et à quel prix la dernière
     * fois ». Elle demandait jusqu'ici de rouvrir ses factures une par une.
     */
    public function produits(Tiers $tiers, Request $request): JsonResponse
    {
        $sens = $request->string('sens')->toString() === 'achats' ? 'achats' : 'ventes';

        // On passe par `whereHas` sur le DOCUMENT et jamais par une jointure à
        // la main : les tables de lignes ne portent ni `tenant_id` ni
        // `deleted_at`, et refaire le filtre d'entreprise à la main est
        // exactement là où une fuite entre entreprises se glisse.
        $lignes = $sens === 'achats'
            ? DocumentAchatLigne::query()->whereHas('document', fn ($q) => $q
                ->where('tiers_id', $tiers->id)
                ->where('type', DocumentAchat::TYPE_FACTURE)
                ->whereIn('statut', ['valide', 'paye']))
            : DocumentVenteLigne::query()->whereHas('document', fn ($q) => $q
                ->where('tiers_id', $tiers->id)
                ->where('type', DocumentVente::TYPE_FACTURE)
                ->whereIn('statut', [DocumentVente::STATUT_VALIDE, DocumentVente::STATUT_PAYE]));

        $agrege = $lignes
            ->whereNotNull('produit_id')
            ->selectRaw('produit_id, SUM(quantite) AS quantite, SUM(montant_ht) AS montant_ht, COUNT(*) AS occurrences')
            ->groupBy('produit_id')
            ->orderByDesc('montant_ht')
            ->limit(100)
            ->get();

        // Les fiches produits en UNE requête, pas une par ligne.
        $produits = Produit::whereIn('id', $agrege->pluck('produit_id'))->get(['id', 'code', 'name', 'unit'])->keyBy('id');

        return response()->json(['data' => $agrege->map(fn ($ligne) => [
            'produit_id' => (int) $ligne->produit_id,
            'code' => $produits[$ligne->produit_id]->code ?? null,
            'name' => $produits[$ligne->produit_id]->name ?? null,
            'unit' => $produits[$ligne->produit_id]->unit ?? null,
            'quantite' => number_format((float) $ligne->quantite, 3, '.', ''),
            'montant_ht' => $this->montant($ligne->montant_ht),
            'occurrences' => (int) $ligne->occurrences,
        ])->values()]);
    }

    private function montant(float|string|null $valeur): string
    {
        return number_format((float) $valeur, 2, '.', '');
    }

    public function encours(Tiers $tiers, \App\Modules\Tiers\Services\EncoursService $service): \Illuminate\Http\JsonResponse
    {
        $controle = $service->verifier($tiers, 0);

        return response()->json(['data' => [
            'tiers_id' => $tiers->id,
            'encours' => number_format($controle['encours'], 2, '.', ''),
            'plafond' => $controle['plafond'] !== null ? number_format($controle['plafond'], 2, '.', '') : null,
            'disponible' => $controle['disponible'] !== null ? number_format($controle['disponible'], 2, '.', '') : null,
        ]]);
    }

    /** Convertit un prospect en client (le tiers garde son code et son historique). */
    public function convertir(Tiers $tiers): TiersResource
    {
        return new TiersResource($this->service->convertirEnClient($tiers));
    }

    public function destroy(Tiers $tiers): \Illuminate\Http\JsonResponse
    {
        $tiers->delete();

        return response()->json(['message' => 'Tiers supprimé.']);
    }
}
