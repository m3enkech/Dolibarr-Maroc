<?php

namespace App\Modules\Stock\Http\Requests;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Stock\Models\MouvementStock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMouvementRequest extends FormRequest
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
            'entrepot_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Entrepot::whereKey($value)->exists()) {
                    $fail('Cet entrepôt n\'existe pas.');
                }
            }],
            'type' => ['required', Rule::in([
                MouvementStock::TYPE_ENTREE,
                MouvementStock::TYPE_SORTIE,
                MouvementStock::TYPE_AJUSTEMENT,
            ])],
            // Entrée/sortie : quantité > 0. Ajustement : nouvelle quantité cible >= 0.
            'quantite' => [
                'required', 'numeric',
                $this->input('type') === MouvementStock::TYPE_AJUSTEMENT ? 'min:0' : 'gt:0',
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
