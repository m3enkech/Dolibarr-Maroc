<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Modules\Catalogue\Models\CategorieProduit;
use App\Modules\Catalogue\Models\Produit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['product', 'service'])],
            'categorie_produit_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! CategorieProduit::whereKey($value)->exists()) {
                    $fail('Cette catégorie n\'existe pas.');
                }
            }],

            'sell_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'buy_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // Seuls les taux de TVA marocains sont acceptés.
            'tva_rate' => ['required', 'numeric', Rule::in(Produit::TVA_RATES)],

            'unit' => ['nullable', 'string', 'max:20'],
            'barcode' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'tva_rate.in' => 'Le taux de TVA doit être un taux marocain valide : 0, 7, 10, 14 ou 20 %.',
        ];
    }
}
