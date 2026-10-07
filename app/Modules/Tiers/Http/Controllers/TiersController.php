<?php

namespace App\Modules\Tiers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Achats\Models\DocumentAchatLigne;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Effets\Models\Effet;
use App\Modules\Tiers\Http\Requests\StoreTiersRequest;
use App\Modules\Tiers\Http\Requests\UpdateTiersRequest;
use App\Modules\Tiers\Http\Resources\TiersResource;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\EncoursService;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Tiers\Services\VueEnsembleService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\DocumentVenteLigne;
use App\Modules\Ventes\Models\Paiement;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class TiersController extends Controller
{
    public function __construct(private TiersService $service) {}

    /**
     * Plafond de `per_page`. La caisse lit l'annuaire client par pages de 500
     * (mises en cache une à une pour le hors-ligne) : c'est le plus gros
     * consommateur légitime. Au-delà, une seule requête pouvait demander les
     * dix mille tiers d'un gros grossiste — et, avec `avec_solde`, l'agrégat
     * du grand livre client pour chacun d'eux.
     */
    private const PAR_PAGE_MAX = 500;

    public function index(Request $request, EncoursService $encours): AnonymousResourceCollection
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
            // `actif` absent ou vide : tous. Un tiers désactivé garde son
            // historique et ses impayés — le cacher par défaut ferait oublier
            // qu'un ancien client doit encore de l'argent.
            ->when($request->filled('actif'), fn ($q) => $q->where('is_active', $request->boolean('actif')))
            // Départage des homonymes : l'annuaire client de la caisse se lit
            // page à page, et PostgreSQL ne garantit aucun ordre entre deux noms
            // égaux — un client pouvait tomber entre deux pages, absent hors ligne.
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($this->parPage($request));

        if ($request->boolean('avec_solde')) {
            $this->joindreSoldes($tiers->getCollection(), $encours);
        }

        return TiersResource::collection($tiers);
    }

    /**
     * Taille de page demandée, plafonnée. Une valeur nulle, négative ou non
     * numérique retombe sur 15, le défaut d'Eloquent : Laravel IGNORE une
     * limite négative — `per_page=-1` rendait toute la table, plafond
     * contourné — et un plancher à 1 aurait, lui, servi `per_page=0` ligne à
     * ligne, quinze fois plus de pages qu'avant sans que rien ne le signale.
     */
    private function parPage(Request $request): int
    {
        $demande = $request->integer('per_page', 15);

        return $demande > 0 ? min($demande, self::PAR_PAGE_MAX) : 15;
    }

    /**
     * Pose sur chaque tiers de la page son solde client signé, calculé pour
     * toute la page en UN SEUL agrégat du grand livre — pas un par ligne.
     *
     * TOUS les tiers de la page sont interrogés, pas seulement ceux cochés
     * « client » : rien n'interdit de facturer un tiers enregistré comme
     * fournisseur (la vente ne contrôle que son existence), ni de décocher
     * « client » sur quelqu'un qui doit encore de l'argent. Filtrer sur la case
     * cachait alors une dette que /encours, lui, affichait.
     *
     * Le drapeau ne sert qu'à lire l'ABSENCE de ligne ouverte : un client
     * confirmé sans écriture est réellement à zéro et l'affiche ; un
     * fournisseur pur ou un prospect sans écriture n'ont pas de compte client
     * — « 0,00 » y laisserait croire qu'on a vérifié, d'où `null`.
     *
     * @param  Collection<int, Tiers>  $page
     */
    private function joindreSoldes(Collection $page, EncoursService $encours): void
    {
        $soldes = $encours->soldesSignes($page->pluck('id')->all());

        foreach ($page as $tiers) {
            $solde = $soldes[$tiers->id] ?? ($tiers->is_client && ! $tiers->is_prospect ? 0.0 : null);

            // Attribut calculé, comme un `withSum` : jamais sauvegardé (la page
            // n'est pas réécrite), il n'existe que pour TiersResource.
            $tiers->setAttribute('solde', $solde !== null ? $this->montant($solde) : null);
        }
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
     *
     * UNE garde (`tiers`), des montants filtrés BLOC PAR BLOC, comme la vue
     * d'ensemble : ce que le tiers nous rapporte (`ca_ttc`, `ca_12_mois`) est
     * OMIS sans le droit ventes, ce qu'on lui achète (`achats_ttc`) sans le
     * droit achats. Le caissier lit les tiers pour encaisser, pas pour voir
     * le chiffre d'affaires : l'écran le lui cachait, la réponse le lui
     * donnait. Les COMPTES de pièces restent (ils nomment des onglets que
     * l'écran filtre lui-même), et l'impayé aussi : ce que doit un client, la
     * caisse le dit déjà au caissier qui lui vend à crédit.
     */
    public function synthese(Request $request, Tiers $tiers): JsonResponse
    {
        $utilisateur = $request->user();
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

        $data = [
            'ventes' => [
                'devis' => (int) ($parType[DocumentVente::TYPE_DEVIS] ?? 0),
                'commandes' => (int) ($parType[DocumentVente::TYPE_COMMANDE] ?? 0),
                'bons_livraison' => (int) ($parType[DocumentVente::TYPE_BON_LIVRAISON] ?? 0),
                'factures' => (int) ($parType[DocumentVente::TYPE_FACTURE] ?? 0),
                'avoirs' => (int) ($parType[DocumentVente::TYPE_AVOIR] ?? 0),
            ],
            'achats' => (int) DocumentAchat::where('tiers_id', $tiers->id)->count(),
            'contacts' => (int) $tiers->contacts()->count(),

            'impaye' => $this->montant(max($duesTtc - $acomptes, 0)),

            'premier_document' => DocumentVente::where('tiers_id', $tiers->id)->min('date_document'),
            'dernier_document' => DocumentVente::where('tiers_id', $tiers->id)->max('date_document'),
        ];

        if ($utilisateur->hasPermission('ventes')) {
            // Les douze mois du graphique « 12 derniers mois », au jour près :
            // du 1er du mois M-11 au dernier jour du mois courant. Un an
            // glissant depuis aujourd'hui, sans borne haute, mettait sur la
            // même fiche deux « 12 mois » qui ne comptaient pas les mêmes
            // factures : celles d'entre J-365 et le 1er du mois M-11, et
            // celles datées d'avance.
            [$du, $au] = VueEnsembleService::bornes('12m', CarbonImmutable::now());

            $data['ca_ttc'] = $this->montant((clone $facturesEmises)->sum('total_ttc'));
            $data['ca_12_mois'] = $this->montant(
                (clone $facturesEmises)
                    ->whereBetween('date_document', [$du->toDateString(), $au->toDateString()])
                    ->sum('total_ttc'),
            );
        }

        if ($utilisateur->hasPermission('achats')) {
            $data['achats_ttc'] = $this->montant(
                DocumentAchat::where('tiers_id', $tiers->id)
                    ->where('type', DocumentAchat::TYPE_FACTURE)
                    ->whereIn('statut', ['valide', 'paye'])
                    ->sum('total_ttc'),
            );
        }

        return response()->json(['data' => $data]);
    }

    /**
     * La vue d'ensemble façon Zoho, en un seul appel : contact principal,
     * accès au portail, conditions de paiement, crédit, compte client et
     * revenus par mois. Compte et crédit n'y figurent que pour un tiers
     * client, les revenus en plus qu'avec le droit `ventes` — voir
     * VueEnsembleService.
     *
     * `periode` hors liste : 422, pas un repli silencieux sur six mois. L'écran
     * ne l'envoie jamais (il valide l'URL avant) ; un appel qui le fait se
     * trompe, et un graphique « 6 derniers mois » sous une demande « 24m »
     * mentirait sans rien dire.
     */
    public function vueEnsemble(Request $request, Tiers $tiers, VueEnsembleService $service): JsonResponse
    {
        $periode = $request->validate([
            'periode' => ['sometimes', 'string', Rule::in(VueEnsembleService::PERIODES)],
        ])['periode'] ?? VueEnsembleService::PERIODE_DEFAUT;

        return response()->json($service->pour($tiers, $request->user(), $periode));
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

    public function encours(Tiers $tiers, EncoursService $service): JsonResponse
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

    /**
     * Supprimer n'est permis qu'à un tiers SANS TRACE. La suppression est
     * douce, mais les relations des pièces ne lisent pas la corbeille : un
     * client effacé avec ses factures faisait tomber leur PDF en erreur 500
     * (`$document->tiers` nul), un avoir tiré de l'une d'elles plantait la
     * comptabilisation, et l'impayé disparaissait de la liste avec lui. Celui
     * qui a des pièces se DÉSACTIVE : il garde son code, ses pièces et ce
     * qu'il doit.
     *
     * Les brouillons supprimés ne comptent pas : personne ne les voit plus,
     * et refuser au nom d'une pièce invisible ne s'expliquerait pas.
     */
    public function destroy(Tiers $tiers): JsonResponse
    {
        if ($this->aDesTraces($tiers)) {
            return response()->json([
                'message' => __('Ce tiers a des pièces ou des écritures : désactivez-le plutôt.'),
            ], 422);
        }

        $tiers->delete();

        return response()->json(['message' => 'Tiers supprimé.']);
    }

    /**
     * Pièces de vente ou d'achat, effets, lignes d'écriture : tout ce qui
     * pointe vers ce tiers et se casserait sans lui. Les lignes d'écriture
     * n'ont pas d'entreprise à elles : on passe par leur écriture, qui en a
     * une — même règle que partout ailleurs.
     */
    private function aDesTraces(Tiers $tiers): bool
    {
        return DocumentVente::where('tiers_id', $tiers->id)->exists()
            || DocumentAchat::where('tiers_id', $tiers->id)->exists()
            || Effet::where('tiers_id', $tiers->id)->exists()
            || EcritureLigne::where('tiers_id', $tiers->id)->whereHas('ecriture')->exists();
    }
}
