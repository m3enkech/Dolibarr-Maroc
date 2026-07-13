<?php

namespace App\Modules\Compta\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Models\Exercice;
use App\Modules\Compta\PlanComptableMarocain;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\EtatsSyntheseService;
use App\Modules\Compta\Services\TvaExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RapportsController extends Controller
{
    public function __construct(private ComptaService $service) {}

    /** États de synthèse CGNC : Bilan + CPC. */
    public function etatsSynthese(Request $request, EtatsSyntheseService $etats): JsonResponse
    {
        return response()->json($etats->calculer($request->date('au')?->format('Y-m-d')));
    }

    /** Balance âgée clients (créances) ou fournisseurs (dettes) par ancienneté. */
    public function balanceAgee(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString() === 'fournisseurs' ? 'fournisseurs' : 'clients';

        return response()->json(
            $this->service->balanceAgee($type, $request->date('au')?->format('Y-m-d')),
        );
    }

    /** Export Excel de la déclaration TVA (relevé de déductions + chiffre d'affaires). */
    public function exportTva(Request $request, TvaExportService $export): StreamedResponse
    {
        $data = $request->validate([
            'mois' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $spreadsheet = $export->build($data['mois']);
        [$annee, $moisNum] = explode('-', $data['mois']);
        $filename = "TVA {$moisNum}{$annee}.xlsx";

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Balance générale : totaux débit/crédit et solde par compte mouvementé. */
    public function balance(Request $request): JsonResponse
    {
        $this->service->initialiserPlanComptable();

        // Sans borne de début explicite, la balance démarre à l'ouverture de la
        // période non clôturée : les à-nouveaux portent déjà tout l'historique.
        $du = $request->date('du');
        if ($du === null && ($derniereCloture = Exercice::max('annee')) !== null) {
            $du = ((int) $derniereCloture + 1).'-01-01';
        }

        $totaux = EcritureLigne::query()
            ->selectRaw('compte_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->whereHas('ecriture', function ($query) use ($request, $du) {
                $query->when($du, fn ($q) => $q->whereDate('date_ecriture', '>=', $du))
                    ->when($request->date('au'), fn ($q, $au) => $q->whereDate('date_ecriture', '<=', $au));
            })
            ->groupBy('compte_id')
            ->get()
            ->keyBy('compte_id');

        $comptes = Compte::whereIn('id', $totaux->keys())->orderBy('code')->get();

        $lignes = $comptes->map(function (Compte $compte) use ($totaux) {
            $debit = (float) $totaux[$compte->id]->total_debit;
            $credit = (float) $totaux[$compte->id]->total_credit;
            $solde = round($debit - $credit, 2);

            return [
                'compte_id' => $compte->id,
                'code' => $compte->code,
                'label' => $compte->label,
                'classe' => $compte->classe,
                'total_debit' => number_format($debit, 2, '.', ''),
                'total_credit' => number_format($credit, 2, '.', ''),
                'solde_debiteur' => $solde > 0 ? number_format($solde, 2, '.', '') : '0.00',
                'solde_crediteur' => $solde < 0 ? number_format(-$solde, 2, '.', '') : '0.00',
            ];
        })->values();

        return response()->json([
            'data' => $lignes,
            'totaux' => [
                'debit' => number_format((float) $totaux->sum('total_debit'), 2, '.', ''),
                'credit' => number_format((float) $totaux->sum('total_credit'), 2, '.', ''),
            ],
            'classes' => PlanComptableMarocain::CLASSES,
        ]);
    }

    /**
     * État de TVA du mois : TVA facturée (4441) − TVA récupérable (3441+3442)
     * = TVA due. Le côté récupérable attend le module Achats, mais les comptes
     * sont déjà mouvementables via écritures manuelles.
     */
    public function tva(Request $request): JsonResponse
    {
        $this->service->initialiserPlanComptable();

        $mois = $request->string('mois')->toString() ?: now()->format('Y-m');
        [$annee, $numeroMois] = explode('-', $mois);

        $soldePeriode = function (array $codes, string $sens) use ($annee, $numeroMois): float {
            $lignes = EcritureLigne::query()
                ->selectRaw('SUM(debit) as d, SUM(credit) as c')
                ->whereIn('compte_id', Compte::whereIn('code', $codes)->pluck('id'))
                ->whereHas('ecriture', fn ($q) => $q
                    ->whereYear('date_ecriture', $annee)
                    ->whereMonth('date_ecriture', $numeroMois)
                    // Les à-nouveaux reportent des soldes, pas de la TVA du mois.
                    ->where('journal', '!=', Ecriture::JOURNAL_A_NOUVEAUX))
                ->first();

            $debit = (float) ($lignes->d ?? 0);
            $credit = (float) ($lignes->c ?? 0);

            return round($sens === 'credit' ? $credit - $debit : $debit - $credit, 2);
        };

        $facturee = $soldePeriode(['4455'], 'credit');
        $recuperable = $soldePeriode(['34551', '34552'], 'debit');
        $due = round($facturee - $recuperable, 2);

        return response()->json([
            'mois' => $mois,
            'tva_facturee' => number_format($facturee, 2, '.', ''),
            'tva_recuperable' => number_format($recuperable, 2, '.', ''),
            'tva_due' => number_format($due, 2, '.', ''),
            'credit_tva' => $due < 0 ? number_format(-$due, 2, '.', '') : '0.00',
        ]);
    }
}
