<?php

namespace App\Modules\Tiers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tiers_id' => $this->tiers_id,
            'nom' => $this->nom,
            'fonction' => $this->fonction,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'notes' => $this->notes,
            'is_principal' => $this->is_principal,
            'is_active' => $this->is_active,
        ];
    }
}
