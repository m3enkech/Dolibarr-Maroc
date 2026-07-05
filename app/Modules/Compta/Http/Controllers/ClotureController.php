<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Services\ClotureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClotureController extends Controller
{
    public function __construct(private ClotureService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->service->exercices()]);
    }

    public function cloturer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'annee' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $exercice = $this->service->cloturer((int) $data['annee']);

        return response()->json([
            'data' => [
                'annee' => $exercice->annee,
                'resultat' => (string) $exercice->resultat,
                'cloture_at' => $exercice->cloture_at?->format('Y-m-d H:i'),
            ],
        ], 201);
    }
}
