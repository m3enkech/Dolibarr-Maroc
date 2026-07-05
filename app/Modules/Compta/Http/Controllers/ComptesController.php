<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Http\Requests\StoreCompteRequest;
use App\Modules\Compta\Models\ComptaMapping;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\PlanComptableMarocain;
use App\Modules\Compta\Services\ComptaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComptesController extends Controller
{
    public function __construct(private ComptaService $service) {}

    public function index(Request $request): JsonResponse
    {
        $this->service->initialiserPlanComptable();

        $comptes = Compte::query()
            ->when($request->integer('classe'), fn ($q, $classe) => $q->where('classe', $classe))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('code', 'like', $search)->orWhere('label', 'like', $search));
            })
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $comptes->map(fn (Compte $compte) => [
                'id' => $compte->id,
                'code' => $compte->code,
                'label' => $compte->label,
                'classe' => $compte->classe,
                'classe_label' => PlanComptableMarocain::CLASSES[$compte->classe] ?? '',
                'is_system' => $compte->is_system,
                'is_active' => $compte->is_active,
            ]),
            'classes' => PlanComptableMarocain::CLASSES,
        ]);
    }

    public function store(StoreCompteRequest $request): JsonResponse
    {
        $this->service->initialiserPlanComptable();
        $data = $request->validated();

        $compte = Compte::create([
            'code' => $data['code'],
            'label' => $data['label'],
            'classe' => (int) $data['code'][0],
            'is_system' => false,
        ]);

        return response()->json(['data' => $compte], 201);
    }

    public function mappings(): JsonResponse
    {
        $this->service->initialiserPlanComptable();

        $mappings = ComptaMapping::with('compte')->get()->keyBy('cle');

        return response()->json([
            'data' => collect(PlanComptableMarocain::MAPPING_LABELS)->map(fn ($label, $cle) => [
                'cle' => $cle,
                'label' => $label,
                'compte_id' => $mappings->get($cle)?->compte_id,
                'compte_code' => $mappings->get($cle)?->compte?->code,
                'compte_label' => $mappings->get($cle)?->compte?->label,
            ])->values(),
        ]);
    }

    public function updateMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cle' => ['required', 'string', 'in:'.implode(',', array_keys(PlanComptableMarocain::MAPPINGS_DEFAUT))],
            'compte_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Compte::whereKey($value)->where('is_active', true)->exists()) {
                    $fail('Ce compte n\'existe pas.');
                }
            }],
        ]);

        ComptaMapping::updateOrCreate(['cle' => $data['cle']], ['compte_id' => $data['compte_id']]);

        return $this->mappings();
    }
}
