<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\LettrageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LettrageController extends Controller
{
    public function __construct(
        private LettrageService $service,
        private ComptaService $compta,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->compta->initialiserPlanComptable();

        $data = $request->validate([
            'compte_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Compte::whereKey($value)->exists()) {
                    $fail('Ce compte n\'existe pas.');
                }
            }],
            'tiers_id' => ['nullable', 'integer'],
            'statut' => ['nullable', Rule::in(['non_lettres', 'lettres', 'tous'])],
        ]);

        $lignes = $this->service->lignes(
            (int) $data['compte_id'],
            isset($data['tiers_id']) ? (int) $data['tiers_id'] : null,
            $data['statut'] ?? 'non_lettres',
        );

        $nonLettrees = $lignes->whereNull('lettrage');

        return response()->json([
            'data' => $lignes->map(fn ($ligne) => [
                'id' => $ligne->id,
                'date_ecriture' => $ligne->ecriture->date_ecriture?->format('Y-m-d'),
                'numero' => $ligne->ecriture->numero,
                'journal' => $ligne->ecriture->journal,
                'libelle' => $ligne->libelle ?? $ligne->ecriture->libelle,
                'reference' => $ligne->ecriture->reference,
                'tiers' => $ligne->tiers?->name,
                'debit' => $ligne->debit,
                'credit' => $ligne->credit,
                'lettrage' => $ligne->lettrage,
            ]),
            'solde_non_lettre' => number_format(
                (float) $nonLettrees->sum(fn ($l) => (float) $l->debit)
                - (float) $nonLettrees->sum(fn ($l) => (float) $l->credit),
                2, '.', '',
            ),
        ]);
    }

    public function lettrer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ligne_ids' => ['required', 'array', 'min:2'],
            'ligne_ids.*' => ['integer'],
        ]);

        return response()->json($this->service->lettrer($data['ligne_ids']));
    }

    public function auto(Request $request): JsonResponse
    {
        $data = $request->validate([
            'compte_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Compte::whereKey($value)->exists()) {
                    $fail('Ce compte n\'existe pas.');
                }
            }],
        ]);

        return response()->json($this->service->lettrageAuto((int) $data['compte_id']));
    }

    public function delettrer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'compte_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:5'],
        ]);

        $lignes = $this->service->delettrer((int) $data['compte_id'], $data['code']);

        return response()->json(['lignes' => $lignes]);
    }
}
