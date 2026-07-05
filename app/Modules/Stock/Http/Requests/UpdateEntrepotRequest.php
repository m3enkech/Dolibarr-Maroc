<?php

namespace App\Modules\Stock\Http\Requests;

class UpdateEntrepotRequest extends StoreEntrepotRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);
    }
}
