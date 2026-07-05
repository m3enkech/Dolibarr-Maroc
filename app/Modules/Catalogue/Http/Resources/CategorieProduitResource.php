<?php

namespace App\Modules\Catalogue\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategorieProduitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'compte_vente_id' => $this->compte_vente_id,
            'compte_vente' => $this->whenLoaded('compteVente', fn () => $this->compteVente?->code),
            'compte_achat_id' => $this->compte_achat_id,
            'compte_achat' => $this->whenLoaded('compteAchat', fn () => $this->compteAchat?->code),
            'is_immobilisation' => $this->is_immobilisation,
            'compte_amortissement_id' => $this->compte_amortissement_id,
            'compte_amortissement' => $this->whenLoaded('compteAmortissement', fn () => $this->compteAmortissement?->code),
            'duree_amortissement' => $this->duree_amortissement,
            'produits_count' => $this->when(isset($this->produits_count), fn () => $this->produits_count),
        ];
    }
}
