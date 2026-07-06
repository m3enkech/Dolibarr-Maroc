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
            'composants' => $this->whenLoaded('composants', fn () => $this->composants->map(fn ($c) => [
                'produit_id' => $c->composant_id,
                'quantite' => $c->quantite,
                'name' => $c->composant?->name,
                'code' => $c->composant?->code,
                'type' => $c->composant?->type,
                'unit' => $c->composant?->unit,
            ])),
            'sell_price' => $this->sell_price,
            'sell_price_ttc' => $this->sell_price_ttc,
            'buy_price' => $this->buy_price,
            'tva_rate' => $this->tva_rate,
            'unit' => $this->unit,
            'stock_min' => $this->stock_min,
            'stock_reappro' => $this->stock_reappro,
            'barcode' => $this->barcode,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
