<?php

namespace App\Modules\Stock\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventaireLigneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ecart = $this->ecart();

        return [
            'id' => $this->id,
            'produit_id' => $this->produit_id,
            'quantite_theorique' => $this->quantite_theorique,
            'quantite_comptee' => $this->quantite_comptee,
            'ecart' => $ecart === null ? null : number_format($ecart, 3, '.', ''),
            'produit' => $this->whenLoaded('produit', fn () => $this->produit ? [
                'id' => $this->produit->id,
                'code' => $this->produit->code,
                'name' => $this->produit->name,
                'unit' => $this->produit->unit,
            ] : null),
        ];
    }
}
