<?php

namespace App\Modules\Achats\Http\Requests;

use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentAchatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(DocumentAchat::TYPES)],
            'tiers_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                $tiers = Tiers::whereKey($value)->first();
                if ($tiers === null) {
                    $fail('Ce tiers n\'existe pas.');
                } elseif (! $tiers->is_supplier) {
                    $fail('Ce tiers n\'est pas un fournisseur.');
                }
            }],
            'entrepot_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! Entrepot::whereKey($value)->exists()) {
                    $fail('Cet entrepôt n\'existe pas.');
                }
            }],
            'ref_fournisseur' => ['nullable', 'string', 'max:255'],
            'date_document' => ['nullable', 'date'],
            'date_echeance' => ['nullable', 'date', 'after_or_equal:date_document'],
            'notes' => ['nullable', 'string'],

            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! Produit::whereKey($value)->exists()) {
                    $fail('Ce produit n\'existe pas.');
                }
            }],
            'lignes.*.source_ligne_id' => ['nullable', 'integer'],
            'lignes.*.designation' => ['nullable', 'string', 'max:255'],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'lignes.*.prix_unitaire' => ['nullable', 'numeric', 'min:0'],
            'lignes.*.remise_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lignes.*.tva_rate' => ['nullable', 'numeric', Rule::in(Produit::TVA_RATES)],
        ];
    }
}
