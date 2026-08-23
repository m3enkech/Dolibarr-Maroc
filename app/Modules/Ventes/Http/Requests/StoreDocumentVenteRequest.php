<?php

namespace App\Modules\Ventes\Http\Requests;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentVenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(DocumentVente::TYPES)],
            // Les closures interrogent les modèles scopés : un id d'un autre
            // tenant est invisible, donc rejeté.
            'tiers_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Tiers::whereKey($value)->exists()) {
                    $fail('Ce tiers n\'existe pas.');
                }
            }],
            'date_document' => ['nullable', 'date'],
            'date_echeance' => ['nullable', 'date', 'after_or_equal:date_document'],
            'notes' => ['nullable', 'string'],

            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! Produit::whereKey($value)->exists()) {
                    $fail('Ce produit n\'existe pas.');
                }
            }],
            'lignes.*.designation' => ['required_without:lignes.*.produit_id', 'nullable', 'string', 'max:255'],
            // Saisie au colis : quantite devient facultative, elle est déduite
            // du conditionnement (5 cartons de 12 → 60 en unité de stock).
            'lignes.*.quantite' => ['required_without:lignes.*.conditionnement_id', 'nullable', 'numeric', 'gt:0'],
            'lignes.*.conditionnement_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! \App\Modules\Catalogue\Models\ProduitConditionnement::whereKey($value)->exists()) {
                    $fail('Ce conditionnement n\'existe pas.');
                }
            }],
            'lignes.*.quantite_colis' => ['nullable', 'numeric', 'gt:0', 'required_with:lignes.*.conditionnement_id'],
            'lignes.*.prix_unitaire' => ['nullable', 'numeric', 'min:0'],
            'lignes.*.remise_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lignes.*.tva_rate' => ['nullable', 'numeric', Rule::in(Produit::TVA_RATES)],
        ];
    }
}
