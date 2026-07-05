<?php

namespace App\Modules\Tiers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TiersResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_client' => $this->is_client,
            'is_supplier' => $this->is_supplier,
            'ice' => $this->ice,
            'if_number' => $this->if_number,
            'rc' => $this->rc,
            'patente' => $this->patente,
            'cnss' => $this->cnss,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'contact_name' => $this->contact_name,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
