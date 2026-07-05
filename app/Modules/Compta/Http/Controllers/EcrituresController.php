<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Http\Requests\StoreEcritureRequest;
use App\Modules\Compta\Http\Resources\EcritureResource;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Services\ComptaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class EcrituresController extends Controller
{
    public function __construct(private ComptaService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $ecritures = Ecriture::query()
            ->with('lignes.compte')
            ->when(
                in_array($request->string('journal')->toString(), Ecriture::JOURNAUX, true),
                fn ($q) => $q->where('journal', $request->string('journal')->toString()),
            )
            ->when($request->date('du'), fn ($q, $du) => $q->whereDate('date_ecriture', '>=', $du))
            ->when($request->date('au'), fn ($q, $au) => $q->whereDate('date_ecriture', '<=', $au))
            ->when($request->string('search')->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q
                    ->where('numero', 'like', $search)
                    ->orWhere('libelle', 'like', $search)
                    ->orWhere('reference', 'like', $search));
            })
            ->latest('date_ecriture')
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return EcritureResource::collection($ecritures);
    }

    public function store(StoreEcritureRequest $request): EcritureResource
    {
        return new EcritureResource($this->service->ecritureManuelle($request->validated()));
    }
}
