<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Activite;
use App\Modules\Crm\Models\Opportunite;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimelineController extends Controller
{
    /**
     * Timeline 360° d'un client : activités, opportunités et documents de vente
     * réunis en un fil chronologique (le plus récent d'abord).
     */
    public function show(Request $request, Tiers $tiers): JsonResponse
    {
        $this->assertFeature($request);

        $activites = Activite::where('tiers_id', $tiers->id)->get()->map(fn (Activite $a) => [
            'kind' => 'activite',
            'id' => $a->id,
            'date' => ($a->date_prevue ?? $a->created_at)->format('Y-m-d'),
            'type' => $a->type,
            'titre' => $a->sujet,
            'detail' => $a->note,
            'statut' => $a->fait ? 'fait' : 'a_faire',
        ]);

        $opportunites = Opportunite::where('tiers_id', $tiers->id)->get()->map(fn (Opportunite $o) => [
            'kind' => 'opportunite',
            'id' => $o->id,
            'date' => $o->created_at->format('Y-m-d'),
            'titre' => $o->titre,
            'detail' => number_format((float) $o->montant_estime, 2, '.', '').' MAD · '.$o->probabilite.'%',
            'statut' => $o->statut,
        ]);

        $documents = DocumentVente::where('tiers_id', $tiers->id)->get()->map(fn (DocumentVente $d) => [
            'kind' => 'document',
            'id' => $d->id,
            'date' => $d->date_document?->format('Y-m-d'),
            'type' => $d->type,
            'titre' => $d->code,
            'detail' => number_format((float) $d->total_ttc, 2, '.', '').' MAD',
            'statut' => $d->statut,
        ]);

        $timeline = $activites->concat($opportunites)->concat($documents)
            ->sortByDesc('date')
            ->values();

        return response()->json(['data' => $timeline]);
    }

    private function assertFeature(Request $request): void
    {
        abort_unless(
            $request->user()->tenant->hasFeature('crm'),
            403,
            'Le module CRM est désactivé dans les paramètres.',
        );
    }
}
