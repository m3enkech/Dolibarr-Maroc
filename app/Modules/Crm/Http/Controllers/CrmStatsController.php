<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\CrmStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmStatsController extends Controller
{
    public function __construct(private CrmStatsService $service) {}

    /** Statistiques commerciales (option ?depuis=YYYY-MM-DD pour borner la période). */
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->tenant->hasFeature('crm'),
            403,
            'Le module CRM est désactivé dans les paramètres.',
        );

        // Validé : une chaîne libre injectée dans whereDate() passe en silence
        // sur SQLite mais fait échouer PostgreSQL en production.
        $data = $request->validate([
            'depuis' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $this->service->stats($data['depuis'] ?? null)]);
    }
}
