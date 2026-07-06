<?php

namespace App\Modules\Parametres\Http\Controllers;

use App\Core\Tenancy\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametresController extends Controller
{
    /**
     * Champs d'identité société stockés dans tenants.settings (au niveau
     * racine : les PDF, l'e-facture UBL et l'export SIMPL-TVA lisent déjà ces
     * clés — ice, if, rc, address, city…).
     */
    private const CHAMPS_SOCIETE = [
        'ice', 'if', 'rc', 'patente', 'cnss',
        'address', 'city', 'postal_code', 'phone', 'email', 'website',
    ];

    /** Paramètres de l'entreprise courante (dont l'état des modules activables). */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user()->tenant)]);
    }

    /** Active/désactive les modules (feature flags). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.*' => ['boolean'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;
        $settings = $tenant->settings ?? [];

        // On ne persiste que les clés de features connues.
        $features = $settings['features'] ?? [];
        foreach ($data['features'] as $cle => $actif) {
            if (array_key_exists($cle, Tenant::FEATURES_DEFAUT)) {
                $features[$cle] = (bool) $actif;
            }
        }
        $settings['features'] = $features;

        $tenant->update(['settings' => $settings]);

        return response()->json(['data' => $this->payload($tenant->refresh())]);
    }

    /** Identité légale de l'entreprise (raison sociale + ICE/IF/RC… + coordonnées). */
    public function updateSociete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'ice' => ['nullable', 'string', 'max:15'],
            'if' => ['nullable', 'string', 'max:20'],
            'rc' => ['nullable', 'string', 'max:30'],
            'patente' => ['nullable', 'string', 'max:30'],
            'cnss' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->user()->tenant;
        $settings = $tenant->settings ?? [];

        foreach (self::CHAMPS_SOCIETE as $champ) {
            if (array_key_exists($champ, $data)) {
                $valeur = $data[$champ] !== null ? trim($data[$champ]) : null;
                $settings[$champ] = $valeur === '' ? null : $valeur;
            }
        }

        $maj = ['settings' => $settings];
        if (array_key_exists('name', $data) && trim($data['name']) !== '') {
            $maj['name'] = trim($data['name']);
        }

        $tenant->update($maj);

        return response()->json(['data' => $this->payload($tenant->refresh())]);
    }

    private function payload(Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];

        $societe = ['name' => $tenant->name];
        foreach (self::CHAMPS_SOCIETE as $champ) {
            $societe[$champ] = $settings[$champ] ?? null;
        }

        return [
            'name' => $tenant->name,
            'plan' => $tenant->plan,
            'features' => $tenant->features(),
            'societe' => $societe,
        ];
    }
}
