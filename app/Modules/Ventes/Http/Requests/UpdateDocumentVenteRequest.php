<?php

namespace App\Modules\Ventes\Http\Requests;

class UpdateDocumentVenteRequest extends StoreDocumentVenteRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // Le type est fixé à la création ; les lignes sont optionnelles en
        // mise à jour (absentes = inchangées).
        unset($rules['type']);
        $rules['tiers_id'][0] = 'sometimes';
        $rules['lignes'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
