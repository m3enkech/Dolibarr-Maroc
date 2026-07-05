<?php

namespace App\Modules\Catalogue\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProduitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'categorie_produit_id' => $this->categorie_produit_id,
            'categorie' => $this->whenLoaded('categorieProduit', fn () => $this->categorieProduit?->name),
            'sell_price' => $this->sell_price,
            'sell_price_ttc' => $this->sell_price_ttc,
            'buy_price' => $this->buy_price,
            'tva_rate' => $this->tva_rate,
            'unit' => $this->unit,
            'barcode' => $this->barcode,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
