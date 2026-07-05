<?php

namespace App\Modules\Compta\Http\Requests;

use App\Modules\Compta\CategoriesImmobilisation;
use App\Modules\Compta\Models\Compte;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImmobilisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $compteExiste = function (string $attribute, mixed $value, \Closure $fail) {
            if ($value !== null && ! Compte::whereKey($value)->exists()) {
                $fail('Ce compte n\'existe pas.');
            }
        };

        return [
            'label' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(CategoriesImmobilisation::keys())],
            'date_acquisition' => ['required', 'date'],
            'valeur_acquisition' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'duree_annees' => ['nullable', 'integer', 'min:1', 'max:40'],
            'compte_immo_id' => ['nullable', 'integer', $compteExiste],
            'compte_amort_id' => ['nullable', 'integer', $compteExiste],
            'notes' => ['nullable', 'string'],
        ];
    }
}
