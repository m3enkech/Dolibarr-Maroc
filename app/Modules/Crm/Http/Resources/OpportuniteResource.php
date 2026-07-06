<?php

namespace App\Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OpportuniteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'titre' => $this->titre,
            'montant_estime' => $this->montant_estime,
            'probabilite' => $this->probabilite,
            'etape' => $this->etape,
            'statut' => $this->statut,
            'date_cloture_prevue' => $this->date_cloture_prevue?->format('Y-m-d'),
            'note' => $this->note,
            'tiers_id' => $this->tiers_id,
            'tiers' => $this->whenLoaded('tiers', fn () => $this->tiers?->name),
            'vendeur' => $this->whenLoaded('user', fn () => $this->user?->name),
            'close_at' => $this->close_at,
            'created_at' => $this->created_at,
        ];
    }
}
