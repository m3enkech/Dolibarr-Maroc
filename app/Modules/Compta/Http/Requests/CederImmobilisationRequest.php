<?php

namespace App\Modules\Compta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CederImmobilisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_cession' => ['required', 'date', 'after_or_equal:'.$this->route('immobilisation')->date_acquisition->format('Y-m-d')],
            'valeur_cession' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    public function messages(): array
    {
        return [
            'date_cession.after_or_equal' => 'La date de cession doit être postérieure à l\'acquisition.',
        ];
    }
}
