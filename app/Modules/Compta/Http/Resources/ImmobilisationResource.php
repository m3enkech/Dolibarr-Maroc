<?php

namespace App\Modules\Compta\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImmobilisationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'document_achat_id' => $this->document_achat_id,
            'facture_achat' => $this->whenLoaded('documentAchat', fn () => $this->documentAchat?->code),
            'label' => $this->label,
            'category' => $this->category,
            'date_acquisition' => $this->date_acquisition?->format('Y-m-d'),
            'valeur_acquisition' => $this->valeur_acquisition,
            'duree_annees' => $this->duree_annees,
            'compte_immo' => $this->whenLoaded('compteImmo', fn () => $this->compteImmo?->code),
            'compte_amort' => $this->whenLoaded('compteAmort', fn () => $this->compteAmort?->code),
            'statut' => $this->statut,
            'date_cession' => $this->date_cession?->format('Y-m-d'),
            'valeur_cession' => $this->valeur_cession,
            'cumul_amortissement' => number_format($this->cumulAmortissement(), 2, '.', ''),
            'vna' => number_format($this->vna(), 2, '.', ''),
            'notes' => $this->notes,
        ];
    }
}
