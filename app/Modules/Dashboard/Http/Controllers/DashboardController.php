<?php

namespace App\Modules\Dashboard\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service) {}

    /** Indicateurs du tableau de bord, adaptés aux droits de l'utilisateur. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->pour($request->user())]);
    }
}
