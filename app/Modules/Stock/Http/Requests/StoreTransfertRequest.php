<?php

namespace App\Modules\Stock\Http\Requests;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransfertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'produit_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                $produit = Produit::find($value);
                if ($produit === null) {
                    $fail('Ce produit n\'existe pas.');
                } elseif ($produit->type !== 'product') {
                    $fail('Un service n\'a pas de stock.');
                }
            }],
            'entrepot_source_id' => ['required', 'integer', $this->entrepotExists()],
            'entrepot_dest_id' => ['required', 'integer', 'different:entrepot_source_id', $this->entrepotExists()],
            'quantite' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'entrepot_dest_id.different' => 'L\'entrepôt de destination doit être différent de la source.',
        ];
    }

    private function entrepotExists(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! Entrepot::whereKey($value)->exists()) {
                $fail('Cet entrepôt n\'existe pas.');
            }
        };
    }
}
