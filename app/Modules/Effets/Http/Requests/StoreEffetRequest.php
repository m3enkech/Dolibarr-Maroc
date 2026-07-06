<?php

namespace App\Modules\Effets\Http\Requests;

use App\Modules\Effets\Models\Effet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEffetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([Effet::TYPE_RECEVOIR, Effet::TYPE_PAYER])],
            'facture_id' => ['required', 'integer'],
            'date_echeance' => ['required', 'date'],
        ];
    }
}
