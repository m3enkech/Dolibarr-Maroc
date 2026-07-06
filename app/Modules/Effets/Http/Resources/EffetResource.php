<?php

namespace App\Modules\Effets\Http\Resources;

use App\Modules\Effets\Models\Effet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EffetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $enRetard = $this->statut === Effet::STATUT_PORTEFEUILLE
            && $this->date_echeance !== null
            && $this->date_echeance->isPast();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'code' => $this->code,
            'montant' => $this->montant,
            'date_creation' => $this->date_creation?->format('Y-m-d'),
            'date_echeance' => $this->date_echeance?->format('Y-m-d'),
            'statut' => $this->statut,
            'en_retard' => $enRetard,
            'tiers' => $this->whenLoaded('tiers', fn () => $this->tiers?->name),
            'facture' => $this->when(true, fn () => $this->documentVente?->code ?? $this->documentAchat?->code),
            'created_at' => $this->created_at,
        ];
    }
}
