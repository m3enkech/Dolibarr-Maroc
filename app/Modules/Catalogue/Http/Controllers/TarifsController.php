<?php

namespace App\Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Catalogue\Models\ProduitTarif;
use App\Modules\Catalogue\Services\TarifService;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TarifsController extends Controller
{
    public function __construct(private TarifService $service) {}

    /* ------------------------------------------------------------------ */
    /* Catégories tarifaires (gros, demi-gros, détail…)                     */
    /* ------------------------------------------------------------------ */

    public function categories(): JsonResponse
    {
        $categories = CategorieTarifaire::query()
            ->withCount('tarifs')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (CategorieTarifaire $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'is_default' => $c->is_default,
                'tarifs_count' => $c->tarifs_count,
            ]);

        return response()->json(['data' => $categories]);
    }

    public function storeCategorie(Request $request): JsonResponse
    {
        $data = $this->validerCategorie($request);
        $categorie = DB::transaction(fn () => $this->creerOuMettreAJour(new CategorieTarifaire(), $data));

        return response()->json(['data' => $categorie], 201);
    }

    public function updateCategorie(Request $request, CategorieTarifaire $categorie): JsonResponse
    {
        $data = $this->validerCategorie($request);
        $categorie = DB::transaction(fn () => $this->creerOuMettreAJour($categorie, $data));

        return response()->json(['data' => $categorie]);
    }

    public function destroyCategorie(CategorieTarifaire $categorie): JsonResponse
    {
        // Les tarifs de la catégorie tombent avec elle (cascade) ; les clients
        // qui la portaient repassent au tarif catalogue (nullOnDelete).
        $categorie->delete();

        return response()->json(['message' => 'Catégorie tarifaire supprimée.']);
    }

    /* ------------------------------------------------------------------ */
    /* Grille tarifaire d'un article                                        */
    /* ------------------------------------------------------------------ */

    /** Tous les tarifs saisis pour un article (catégories + clients). */
    public function parProduit(Produit $produit): JsonResponse
    {
        $tarifs = ProduitTarif::where('produit_id', $produit->id)
            ->with(['categorieTarifaire:id,name', 'tiers:id,name'])
            ->orderBy('categorie_tarifaire_id')
            ->orderBy('tiers_id')
            ->orderBy('quantite_min')
            ->get()
            ->map(fn (ProduitTarif $t) => [
                'id' => $t->id,
                'categorie_tarifaire_id' => $t->categorie_tarifaire_id,
                'categorie' => $t->categorieTarifaire?->name,
                'tiers_id' => $t->tiers_id,
                'client' => $t->tiers?->name,
                'quantite_min' => (float) $t->quantite_min,
                'prix' => number_format((float) $t->prix, 2, '.', ''),
            ]);

        return response()->json([
            'data' => $tarifs,
            'prix_catalogue' => number_format((float) $produit->sell_price, 2, '.', ''),
        ]);
    }

    public function storeTarif(Request $request, Produit $produit): JsonResponse
    {
        $data = $request->validate([
            'categorie_tarifaire_id' => ['nullable', 'integer'],
            'tiers_id' => ['nullable', 'integer'],
            'quantite_min' => ['required', 'numeric', 'min:0.001'],
            'prix' => ['required', 'numeric', 'min:0'],
        ]);

        // Un tarif vise une catégorie OU un client, jamais les deux ni aucun.
        $cible = ($data['categorie_tarifaire_id'] ?? null) !== null;
        $client = ($data['tiers_id'] ?? null) !== null;

        abort_if($cible === $client, 422, 'Un tarif vise soit une catégorie tarifaire, soit un client.');

        if ($cible) {
            abort_unless(CategorieTarifaire::whereKey($data['categorie_tarifaire_id'])->exists(), 422, 'Catégorie inconnue.');
        } else {
            abort_unless(Tiers::whereKey($data['tiers_id'])->exists(), 422, 'Client inconnu.');
        }

        // Un seul prix par (article, cible, palier) : on remplace le doublon.
        $tarif = ProduitTarif::updateOrCreate(
            [
                'produit_id' => $produit->id,
                'categorie_tarifaire_id' => $data['categorie_tarifaire_id'] ?? null,
                'tiers_id' => $data['tiers_id'] ?? null,
                'quantite_min' => $data['quantite_min'],
            ],
            ['prix' => $data['prix']],
        );

        return response()->json(['data' => ['id' => $tarif->id]], 201);
    }

    public function destroyTarif(ProduitTarif $tarif): JsonResponse
    {
        $tarif->delete();

        return response()->json(['message' => 'Tarif supprimé.']);
    }

    /* ------------------------------------------------------------------ */
    /* Grille d'un client — préremplissage de la caisse                     */
    /* ------------------------------------------------------------------ */

    public function grilleClient(Request $request): JsonResponse
    {
        $tiersId = $request->integer('tiers_id') ?: null;
        $client = $tiersId !== null ? Tiers::find($tiersId) : null;

        return response()->json(['data' => $this->service->grillePour($client)]);
    }

    /* ------------------------------------------------------------------ */

    private function validerCategorie(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['boolean'],
        ]);
    }

    /** Une seule catégorie par défaut à la fois. */
    private function creerOuMettreAJour(CategorieTarifaire $categorie, array $data): array
    {
        if (! empty($data['is_default'])) {
            CategorieTarifaire::where('is_default', true)
                ->when($categorie->exists, fn ($q) => $q->whereKeyNot($categorie->id))
                ->update(['is_default' => false]);
        }

        $categorie->fill($data)->save();
        $categorie->refresh();

        return [
            'id' => $categorie->id,
            'name' => $categorie->name,
            'description' => $categorie->description,
            'is_default' => $categorie->is_default,
            'tarifs_count' => $categorie->tarifs()->count(),
        ];
    }
}
