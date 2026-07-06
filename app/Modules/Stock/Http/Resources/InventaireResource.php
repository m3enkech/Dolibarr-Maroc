<?php

namespace App\Modules\Stock\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'statut' => $this->statut,
            'note' => $this->note,
            'entrepot' => $this->whenLoaded('entrepot', fn () => $this->entrepot ? [
                'id' => $this->entrepot->id,
                'name' => $this->entrepot->name,
            ] : null),
            'lignes' => InventaireLigneResource::collection($this->whenLoaded('lignes')),
            'validated_at' => $this->validated_at,
            'created_at' => $this->created_at,
        ];
    }
}
