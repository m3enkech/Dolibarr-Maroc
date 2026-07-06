<?php

namespace App\Modules\Stock\Http\Requests;

use App\Modules\Catalogue\Models\Produit;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInventaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'comptages' => ['required', 'array', 'min:1'],
            'comptages.*.produit_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Produit::whereKey($value)->where('type', 'product')->exists()) {
                    $fail('Produit stockable introuvable.');
                }
            }],
            // null = comptage effacé ; un nombre = quantité physiquement comptée.
            'comptages.*.quantite_comptee' => ['present', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }
}
