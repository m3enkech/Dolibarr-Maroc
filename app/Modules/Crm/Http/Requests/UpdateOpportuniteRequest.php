<?php

namespace App\Modules\Crm\Http\Requests;

class UpdateOpportuniteRequest extends StoreOpportuniteRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['titre'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['tiers_id'][0] = 'sometimes';

        return $rules;
    }
}
