<?php

namespace App\Modules\Pos\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PosSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'statut' => $this->statut,
            'fond_caisse' => $this->fond_caisse,
            'montant_compte' => $this->montant_compte,
            'ecart' => $this->ecart,
            'note' => $this->note,
            'entrepot_id' => $this->entrepot_id,
            'entrepot_nom' => $this->whenLoaded('entrepot', fn () => $this->entrepot?->name),
            'vendeur' => $this->whenLoaded('user', fn () => $this->user?->name),
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
        ];
    }
}
