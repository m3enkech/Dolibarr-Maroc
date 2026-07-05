<?php

namespace App\Modules\Stock\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MouvementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'quantite' => $this->quantite,
            'quantite_apres' => $this->quantite_apres,
            'reference' => $this->reference,
            'note' => $this->note,
            'produit' => $this->whenLoaded('produit', fn () => $this->produit ? [
                'id' => $this->produit->id,
                'code' => $this->produit->code,
                'name' => $this->produit->name,
                'unit' => $this->produit->unit,
            ] : null),
            'entrepot' => $this->whenLoaded('entrepot', fn () => $this->entrepot ? [
                'id' => $this->entrepot->id,
                'name' => $this->entrepot->name,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
