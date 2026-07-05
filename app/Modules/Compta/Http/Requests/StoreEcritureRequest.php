<?php

namespace App\Modules\Compta\Http\Requests;

use App\Modules\Compta\Models\Compte;
use Illuminate\Foundation\Http\FormRequest;

class StoreEcritureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_ecriture' => ['nullable', 'date'],
            'libelle' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'lignes' => ['required', 'array', 'min:2'],
            'lignes.*.compte_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Compte::whereKey($value)->where('is_active', true)->exists()) {
                    $fail('Ce compte n\'existe pas.');
                }
            }],
            'lignes.*.libelle' => ['nullable', 'string', 'max:255'],
            'lignes.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lignes.*.credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
