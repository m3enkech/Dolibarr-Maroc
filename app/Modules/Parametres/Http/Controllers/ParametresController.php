<?php

namespace App\Modules\Parametres\Http\Controllers;

use App\Core\Tenancy\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParametresController extends Controller
{
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

    private function payload(Tenant $tenant): array
    {
        return [
            'name' => $tenant->name,
            'plan' => $tenant->plan,
            'features' => $tenant->features(),
        ];
    }
}
