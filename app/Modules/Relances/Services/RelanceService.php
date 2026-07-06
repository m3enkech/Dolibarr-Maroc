<?php

namespace App\Modules\Relances\Services;

use App\Modules\Effets\Models\Effet;
use App\Modules\Relances\Models\Relance;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class RelanceService
{
    /**
     * Factures échues non soldées à relancer, triées par retard décroissant.
     * Échéance effective = date d'échéance si renseignée, sinon date de pièce.
     *
     * @return array<int, array<string, mixed>>
     */
    public function aRelancer(?string $dateReference = null): array
    {
        $dateRef = $dateReference !== null ? Carbon::parse($dateReference) : now();

        $factures = DocumentVente::query()
            ->where('type', DocumentVente::TYPE_FACTURE)
            ->where('statut', DocumentVente::STATUT_VALIDE) // validée mais pas encore soldée
            ->with(['tiers', 'paiements'])
            ->get();

        // Une facture couverte par un effet actif (traite en portefeuille/réglée)
        // n'est plus à relancer : la créance a été transférée sur l'effet.
        $couvertesParEffet = Effet::query()
            ->where('type', Effet::TYPE_RECEVOIR)
            ->whereIn('statut', Effet::STATUTS_ACTIFS)
            ->whereIn('document_vente_id', $factures->pluck('id'))
            ->pluck('document_vente_id')
            ->flip();

        // Dernier niveau + date de dernière relance par facture (une requête).
        $relances = Relance::query()
            ->whereIn('document_vente_id', $factures->pluck('id'))
            ->get()
            ->groupBy('document_vente_id');

        return $factures
            ->map(function (DocumentVente $facture) use ($dateRef, $relances, $couvertesParEffet) {
                $reste = $facture->resteAPayer();
                if ($reste <= 0.009 || $couvertesParEffet->has($facture->id)) {
                    return null;
                }

                $echeance = $facture->date_echeance ?? $facture->date_document;
                if ($echeance === null || $echeance->startOfDay()->gte($dateRef->copy()->startOfDay())) {
                    return null; // pas encore échue
                }

                $historique = $relances->get($facture->id);

                return [
                    'document_vente_id' => $facture->id,
                    'code' => $facture->code,
                    'tiers' => $facture->tiers?->name,
                    'tiers_id' => $facture->tiers_id,
                    'date_echeance' => $echeance->format('Y-m-d'),
                    'jours_retard' => $echeance->startOfDay()->diffInDays($dateRef->copy()->startOfDay()),
                    'total_ttc' => number_format((float) $facture->total_ttc, 2, '.', ''),
                    'reste_a_payer' => number_format($reste, 2, '.', ''),
                    'dernier_niveau' => $historique?->max('niveau'),
                    'nb_relances' => $historique?->count() ?? 0,
                    'derniere_relance' => $historique?->max('created_at')?->format('Y-m-d'),
                ];
            })
            ->filter()
            ->sortByDesc('jours_retard')
            ->values()
            ->all();
    }

    public function enregistrer(DocumentVente $facture, int $niveau, string $canal = 'courrier', ?string $note = null): Relance
    {
        if ($facture->type !== DocumentVente::TYPE_FACTURE) {
            throw ValidationException::withMessages(['document_vente_id' => 'Seule une facture peut être relancée.']);
        }

        if ($facture->resteAPayer() <= 0.009) {
            throw ValidationException::withMessages(['document_vente_id' => 'Cette facture est déjà soldée.']);
        }

        return Relance::create([
            'document_vente_id' => $facture->id,
            'user_id' => auth()->id(),
            'niveau' => $niveau,
            'canal' => $canal,
            'note' => $note,
        ]);
    }

    /** Historique des relances d'une facture (plus récentes d'abord). */
    public function historique(DocumentVente $facture): array
    {
        return Relance::where('document_vente_id', $facture->id)
            ->latest('id')
            ->get()
            ->map(fn (Relance $r) => [
                'id' => $r->id,
                'niveau' => $r->niveau,
                'niveau_label' => Relance::NIVEAUX[$r->niveau] ?? (string) $r->niveau,
                'canal' => $r->canal,
                'note' => $r->note,
                'date' => $r->created_at?->format('Y-m-d'),
            ])
            ->all();
    }
}
