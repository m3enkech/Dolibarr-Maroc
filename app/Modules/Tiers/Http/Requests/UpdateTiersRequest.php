<?php

namespace App\Modules\Tiers\Http\Requests;

class UpdateTiersRequest extends StoreTiersRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ]);
    }
}
