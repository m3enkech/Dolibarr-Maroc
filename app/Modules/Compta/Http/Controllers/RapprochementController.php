<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Http\Requests\ImportReleveRequest;
use App\Modules\Compta\Models\BankStatement;
use App\Modules\Compta\Models\BankStatementLine;
use App\Modules\Compta\Services\RapprochementBancaireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RapprochementController extends Controller
{
    public function __construct(private RapprochementBancaireService $service) {}

    /** Comptes rapprochables + relevés existants. */
    public function index(): JsonResponse
    {
        $statements = BankStatement::query()
            ->with('compte:id,code,label')
            ->withCount([
                'lignes',
                'lignes as lignes_rapprochees_count' => fn ($q) => $q->whereNotNull('ecriture_ligne_id'),
            ])
            ->latest('id')
            ->get()
            ->map(fn (BankStatement $s) => [
                'id' => $s->id,
                'libelle' => $s->libelle,
                'compte' => ['code' => $s->compte->code, 'label' => $s->compte->label],
                'date_debut' => $s->date_debut?->toDateString(),
                'date_fin' => $s->date_fin?->toDateString(),
                'solde_final' => number_format((float) $s->solde_final, 2, '.', ''),
                'statut' => $s->statut,
                'lignes_count' => $s->lignes_count,
                'lignes_rapprochees_count' => $s->lignes_rapprochees_count,
            ]);

        return response()->json([
            'data' => [
                'comptes' => $this->service->comptesRapprochables(),
                'releves' => $statements,
            ],
        ]);
    }

    public function importer(ImportReleveRequest $request): JsonResponse
    {
        $data = $request->validated();

        $statement = $this->service->importer($data['compte_id'], $request->file('fichier'), [
            'libelle' => $data['libelle'] ?? null,
            'date_debut' => $data['date_debut'] ?? null,
            'date_fin' => $data['date_fin'] ?? null,
            'solde_initial' => $data['solde_initial'] ?? 0,
            'solde_final' => $data['solde_final'] ?? 0,
        ]);

        return response()->json(['data' => $this->service->etat($statement)], 201);
    }

    public function show(BankStatement $statement): JsonResponse
    {
        return response()->json(['data' => $this->service->etat($statement)]);
    }

    public function auto(BankStatement $statement): JsonResponse
    {
        $count = $this->service->autoRapprocher($statement);

        return response()->json([
            'data' => $this->service->etat($statement),
            'pointees' => $count,
        ]);
    }

    public function pointer(Request $request, BankStatementLine $ligne): JsonResponse
    {
        $this->assertLigneDuTenant($ligne);

        $validated = $request->validate([
            'ecriture_ligne_id' => ['required', 'integer'],
        ]);

        $this->service->rapprocher($ligne, $validated['ecriture_ligne_id']);

        return response()->json(['data' => $this->service->etat($ligne->statement)]);
    }

    public function depointer(BankStatementLine $ligne): JsonResponse
    {
        $this->assertLigneDuTenant($ligne);
        $this->service->annuler($ligne);

        return response()->json(['data' => $this->service->etat($ligne->statement)]);
    }

    public function destroy(BankStatement $statement): JsonResponse
    {
        $this->service->supprimer($statement);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** La ligne n'est pas scopée tenant : on vérifie que son relevé l'est. */
    private function assertLigneDuTenant(BankStatementLine $ligne): void
    {
        abort_unless(BankStatement::whereKey($ligne->bank_statement_id)->exists(), 404);
    }
}
