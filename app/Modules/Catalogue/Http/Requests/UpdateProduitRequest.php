<?php

namespace App\Modules\Catalogue\Http\Requests;

class UpdateProduitRequest extends StoreProduitRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['sell_price'] = ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999'];
        $rules['tva_rate'][0] = 'sometimes';

        // Le type est immuable après création (le code PR/SV en dépend).
        unset($rules['type']);

        return $rules;
    }
}
