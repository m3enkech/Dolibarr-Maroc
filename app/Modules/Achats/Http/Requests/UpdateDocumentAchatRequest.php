<?php

namespace App\Modules\Achats\Http\Requests;

class UpdateDocumentAchatRequest extends StoreDocumentAchatRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['type']);
        $rules['tiers_id'][0] = 'sometimes';
        $rules['lignes'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
