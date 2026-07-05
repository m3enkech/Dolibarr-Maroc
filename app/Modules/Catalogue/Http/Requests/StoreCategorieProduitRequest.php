<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Compta\Models\Compte;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategorieProduitRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'compte_vente_id' => ['nullable', 'integer', $compteExiste],
            'compte_achat_id' => ['nullable', 'integer', $compteExiste],
            'is_immobilisation' => ['boolean'],
            // Compte d'amortissement et durée requis pour une catégorie d'immobilisation.
            'compte_amortissement_id' => ['nullable', 'integer', 'required_if:is_immobilisation,true', $compteExiste],
            'duree_amortissement' => ['nullable', 'integer', 'min:1', 'max:40', 'required_if:is_immobilisation,true'],
        ];
    }

    public function messages(): array
    {
        return [
            'compte_amortissement_id.required_if' => 'Une catégorie d\'immobilisation exige un compte d\'amortissement.',
            'duree_amortissement.required_if' => 'Une catégorie d\'immobilisation exige une durée d\'amortissement.',
        ];
    }
}
