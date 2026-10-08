<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Services\SoldeOuvertureService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\ReleveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le solde d'ouverture d'un tiers, saisi depuis sa fiche.
 *
 * La route vit dans le module COMPTA, sous sa seule garde `compta` (écriture,
 * puisque POST) : c'est une écriture au grand livre, que le comptable doit
 * pouvoir passer — il n'a que la lecture des tiers — et que le commercial, qui
 * peut modifier les tiers mais n'a aucun droit en compta, ne doit pas pouvoir
 * passer. Sous la garde `tiers`, ce serait exactement l'inverse.
 */
class SoldeOuvertureController extends Controller
{
    /**
     * Ce que l'écran de saisie doit savoir AVANT l'envoi, compte par compte :
     * les mouvements que le tiers a déjà (combien, depuis quand) et la date
     * qu'on propose — la veille du premier. Sans cela, la surcouche proposait
     * le 1er janvier à un client repris de Zoho avec quatre ans d'historique,
     * et ne disait pas qu'un solde d'ouverture S'AJOUTE à ce qui est déjà là.
     *
     * Garde `compta` en lecture (GET) : n'importe qui lisant le grand livre y
     * voit déjà ces lignes, fournisseur compris.
     */
    public function show(Tiers $tiers, SoldeOuvertureService $service, ReleveService $releve): JsonResponse
    {
        $comptes = array_map(function (string $compte) use ($tiers, $service, $releve) {
            $mouvements = $releve->mouvements($tiers, $compte);

            return [
                'compte' => $compte,
                'mouvements' => $mouvements['nombre'],
                'premier' => $mouvements['premier'],
                'date_proposee' => $service->dateProposee($mouvements['premier']),
            ];
        }, SoldeOuvertureService::comptesPossibles($tiers));

        return response()->json(['data' => [
            'existant' => $service->existant($tiers),
            'comptes' => $comptes,
        ]]);
    }

    public function store(Request $request, Tiers $tiers, SoldeOuvertureService $service): JsonResponse
    {
        $data = $request->validate([
            // Un montant positif ; le sens dit qui doit à qui.
            'montant' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'sens' => ['required', Rule::in(SoldeOuvertureService::SENS)],
            // Une ouverture est un état PASSÉ : datée d'avance, elle n'apparaîtrait
            // dans aucun relevé ni solde arrêté à aujourd'hui.
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'compte' => ['sometimes', 'nullable', Rule::in(SoldeOuvertureService::COMPTES)],
        ], [
            'montant.gt' => __('Le montant doit être supérieur à zéro.'),
            'date.before_or_equal' => __("Un solde d'ouverture ne peut pas être daté dans le futur."),
        ]);

        $ecriture = $service->saisir($tiers, $data);
        $posterieures = $service->odPosterieures($ecriture);

        return response()->json([
            'data' => $service->existant($tiers),
            // Enregistré, mais la série OD de l'année n'est plus dans l'ordre
            // des dates : on le dit plutôt que de renuméroter d'office des
            // écritures peut-être déjà imprimées (voir SoldeOuvertureService).
            'avertissement' => $posterieures > 0
                ? __("Le journal OD :annee n'est plus dans l'ordre des dates : :n écriture(s) datée(s) après le :date y porte(nt) un numéro plus petit. Faites renuméroter la série OD :annee avant d'éditer le journal.", [
                    'annee' => $ecriture->date_ecriture->format('Y'),
                    'n' => $posterieures,
                    'date' => $ecriture->date_ecriture->format('d/m/Y'),
                ])
                : null,
        ], 201);
    }
}
