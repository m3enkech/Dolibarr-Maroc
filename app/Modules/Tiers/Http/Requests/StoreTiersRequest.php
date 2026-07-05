<?php

namespace App\Modules\Tiers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
