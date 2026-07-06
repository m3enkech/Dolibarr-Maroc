<?php

namespace App\Modules\Crm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiviteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enRetard = ! $this->fait
            && $this->date_prevue !== null
            && $this->date_prevue->isPast();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'sujet' => $this->sujet,
            'note' => $this->note,
            'date_prevue' => $this->date_prevue?->format('Y-m-d'),
            'fait' => $this->fait,
            'fait_at' => $this->fait_at,
            'en_retard' => $enRetard,
            'tiers_id' => $this->tiers_id,
            'tiers' => $this->whenLoaded('tiers', fn () => $this->tiers?->name),
            'opportunite_id' => $this->opportunite_id,
            'opportunite' => $this->whenLoaded('opportunite', fn () => $this->opportunite?->titre),
            'vendeur' => $this->whenLoaded('user', fn () => $this->user?->name),
            'created_at' => $this->created_at,
        ];
    }
}
