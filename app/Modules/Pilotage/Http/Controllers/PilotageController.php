<?php

namespace App\Modules\Pilotage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pilotage\Services\FluxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PilotageController extends Controller
{
    public function __construct(private FluxService $flux) {}

    /**
     * Compteurs de l'écran de suivi.
     *
     * La route ne porte AUCUN `permission:` — délibérément. Le middleware est un
     * ET logique : exiger `ventes` ET `achats` sur une même route fermerait la
     * page au commercial (achats: none) comme au caissier (ni l'un ni l'autre).
     * Le filtrage se fait donc bloc par bloc dans le service, qui OMET ce que
     * l'utilisateur n'a pas le droit de voir et le dit dans `capabilities`.
     *
     * C'est exactement le contrat du tableau de bord, seul autre écran de
     * l'application à croiser plusieurs domaines. La différence tient en une
     * ligne : lui n'a aucune route d'écriture, nous en avons une — et elle,
     * porte une garde de domaine unique et explicite.
     */
    public function flux(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->flux->pour($request->user())]);
    }
}
