<?php

namespace App\Modules\Ventes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Catalogue\Services\TarifApplique;
use App\Modules\Catalogue\Services\TarifService;
use App\Modules\Ventes\Http\Requests\PrixVenteRequest;
use Illuminate\Http\JsonResponse;

/**
 * Prix que le serveur appliquerait à des lignes de vente, pour les proposer au
 * vendeur AVANT l'enregistrement.
 *
 * Le formulaire envoie toujours un prix (c'est le prix affiché qui est
 * enregistré) : sans ce point d'accès, il envoyait le prix catalogue et le
 * tarif du client ne jouait jamais. Plutôt que de recopier l'algorithme dans
 * le navigateur — deux règles qui finiraient par diverger —, on interroge
 * celui du serveur.
 */
class PrixController extends Controller
{
    public function __construct(private TarifService $tarifs) {}

    public function index(PrixVenteRequest $request): JsonResponse
    {
        $lignes = array_values($request->validated('lignes'));
        $produits = $request->produits();

        $tarifs = $this->tarifs->tarifsPour(
            $request->client(),
            array_map(fn (array $l) => [$produits[(int) $l['produit_id']], (float) $l['quantite']], $lignes),
        );

        // Un seul client par appel, donc au plus une catégorie : son nom est lu
        // une fois, pas par ligne.
        $categorieId = collect($tarifs)->pluck('categorieId')->filter()->first();
        $categorie = $categorieId !== null ? CategorieTarifaire::query()->whereKey($categorieId)->value('name') : null;

        return response()->json([
            'data' => array_map(fn (array $l, TarifApplique $t) => [
                'produit_id' => (int) $l['produit_id'],
                'quantite' => (float) $l['quantite'],
                'prix' => number_format($t->prix, 2, '.', ''),
                'origine' => $t->origine,
                'palier' => $t->palier,
                'categorie' => $t->origine === TarifApplique::CATEGORIE ? $categorie : null,
                // Catégorie par défaut (pas de client, ou client sans catégorie) :
                // l'écran écrit « Tarif par défaut » et non « Tarif client ».
                'par_defaut' => $t->origine === TarifApplique::CATEGORIE && $t->categorieParDefaut,
            ], $lignes, $tarifs),
        ]);
    }
}
