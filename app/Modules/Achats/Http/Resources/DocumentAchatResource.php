<?php

namespace App\Modules\Achats\Http\Resources;

use App\Modules\Achats\Models\DocumentAchat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentAchatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'code' => $this->code,
            'statut' => $this->statut,
            'tiers_id' => $this->tiers_id,
            'tiers' => $this->whenLoaded('tiers', fn () => [
                'id' => $this->tiers->id,
                'code' => $this->tiers->code,
                'name' => $this->tiers->name,
                'ice' => $this->tiers->ice,
            ]),
            'entrepot_id' => $this->entrepot_id,
            'entrepot' => $this->whenLoaded('entrepot', fn () => $this->entrepot ? [
                'id' => $this->entrepot->id,
                'name' => $this->entrepot->name,
            ] : null),
            'ref_fournisseur' => $this->ref_fournisseur,
            'date_document' => $this->date_document?->format('Y-m-d'),
            'date_echeance' => $this->date_echeance?->format('Y-m-d'),
            'total_ht' => $this->total_ht,
            'total_tva' => $this->total_tva,
            'total_ttc' => $this->total_ttc,
            'notes' => $this->notes,
            'validated_at' => $this->validated_at,
            'lignes' => $this->whenLoaded('lignes', fn () => $this->lignes->map(fn ($ligne) => [
                'id' => $ligne->id,
                'produit_id' => $ligne->produit_id,
                'source_ligne_id' => $ligne->source_ligne_id,
                'designation' => $ligne->designation,
                'quantite' => $ligne->quantite,
                'quantite_recue' => $ligne->quantite_recue,
                'reste_a_recevoir' => number_format($ligne->resteARecevoir(), 3, '.', ''),
                'prix_unitaire' => $ligne->prix_unitaire,
                'remise_percent' => $ligne->remise_percent,
                'tva_rate' => $ligne->tva_rate,
                'montant_ht' => $ligne->montant_ht,
                'montant_tva' => $ligne->montant_tva,
                'montant_ttc' => $ligne->montant_ttc,
                'position' => $ligne->position,
            ])),
            'paiements' => $this->when(
                $this->type === DocumentAchat::TYPE_FACTURE && $this->relationLoaded('paiements'),
                fn () => $this->paiements->map(fn ($paiement) => [
                    'id' => $paiement->id,
                    'date_paiement' => $paiement->date_paiement?->format('Y-m-d'),
                    'montant' => $paiement->montant,
                    'mode' => $paiement->mode,
                    'reference' => $paiement->reference,
                    'note' => $paiement->note,
                ]),
            ),
            'montant_paye' => $this->when(
                $this->type === DocumentAchat::TYPE_FACTURE && $this->relationLoaded('paiements'),
                fn () => number_format((float) $this->paiements->sum('montant'), 2, '.', ''),
            ),
            'reste_a_payer' => $this->when(
                $this->type === DocumentAchat::TYPE_FACTURE && $this->relationLoaded('paiements'),
                fn () => number_format((float) $this->total_ttc - (float) $this->paiements->sum('montant'), 2, '.', ''),
            ),
            'source' => $this->whenLoaded('source', fn () => $this->source ? [
                'id' => $this->source->id,
                'type' => $this->source->type,
                'code' => $this->source->code,
            ] : null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
