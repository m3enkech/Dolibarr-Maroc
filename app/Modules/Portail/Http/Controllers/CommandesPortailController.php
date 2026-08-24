<?php

namespace App\Modules\Portail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portail\Http\ClientDuGrossiste;
use App\Modules\Portail\Services\PortailCommandeService;
use App\Modules\Tiers\Services\EncoursService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandesPortailController extends Controller
{
    use ClientDuGrossiste;

    public function __construct(
        private PortailCommandeService $service,
        private EncoursService $encours,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->service->lister($this->client($request), max(1, $request->integer('page', 1))),
        );
    }

    public function show(Request $request): JsonResponse
    {
        // Paramètre lu par son NOM : l'URL porte d'abord {grossiste}, un
        // argument positionnel recevrait donc le slug.
        $id = (int) $request->route('commande');

        return response()->json(['data' => $this->service->detail($this->client($request), $id)]);
    }

    public function store(Request $request): JsonResponse
    {
        // Le prix est VOLONTAIREMENT absent des champs acceptés : il est fixé
        // par le serveur, à partir du tarif du client.
        $data = $request->validate([
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required_without:lignes.*.conditionnement_id', 'nullable', 'numeric', 'gt:0'],
            'lignes.*.conditionnement_id' => ['nullable', 'integer'],
            'lignes.*.quantite_colis' => ['nullable', 'numeric', 'gt:0', 'required_with:lignes.*.conditionnement_id'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $commande = $this->service->passer($this->client($request), $data['lignes'], $data['note'] ?? null);

        return response()->json([
            'data' => $this->service->detail($this->client($request), $commande->id),
            'message' => __('Commande transmise au grossiste, en attente de confirmation.'),
        ], 201);
    }

    /** Situation du compte : ce que l'acheteur doit, et ce qu'il peut encore engager. */
    public function monCompte(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $controle = $this->encours->verifier($client, 0);

        return response()->json(['data' => [
            'client' => ['code' => $client->code, 'name' => $client->name],
            'encours' => number_format($controle['encours'], 2, '.', ''),
            'plafond' => $controle['plafond'] !== null ? number_format($controle['plafond'], 2, '.', '') : null,
            'disponible' => $controle['disponible'] !== null ? number_format($controle['disponible'], 2, '.', '') : null,
            'delai_paiement_jours' => $client->delai_paiement_jours,
        ]]);
    }
}
