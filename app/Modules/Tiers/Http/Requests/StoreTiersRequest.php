<?php

namespace App\Modules\Tiers\Http\Requests;

use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'is_client' => ['boolean'],
            'is_supplier' => ['boolean'],

            // Qualification commerciale (CRM) : prospect + origine du lead.
            'is_prospect' => ['boolean'],
            'lead_source' => ['nullable', Rule::in(Tiers::LEAD_SOURCES)],

            // Niveau de tarif appliqué à ce client (gros, demi-gros, détail…).
            'categorie_tarifaire_id' => ['nullable', 'integer', function (string $attr, mixed $value, \Closure $fail) {
                if ($value !== null && ! \App\Modules\Catalogue\Models\CategorieTarifaire::whereKey($value)->exists()) {
                    $fail('Cette catégorie tarifaire n\'existe pas.');
                }
            }],

            // Identifiants marocains : l'ICE fait légalement 15 chiffres.
            'ice' => ['nullable', 'digits:15'],
            'if_number' => ['nullable', 'string', 'max:20'],
            'rc' => ['nullable', 'string', 'max:20'],
            'patente' => ['nullable', 'string', 'max:20'],
            'cnss' => ['nullable', 'string', 'max:20'],

            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
