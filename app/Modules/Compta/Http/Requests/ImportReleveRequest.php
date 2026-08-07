<?php

namespace App\Modules\Compta\Http\Requests;

use App\Modules\Compta\Models\Compte;
use Illuminate\Foundation\Http\FormRequest;

class ImportReleveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'compte_id' => ['required', 'integer', function (string $attr, mixed $value, \Closure $fail) {
                if (! Compte::whereKey($value)->exists()) {
                    $fail('Ce compte n\'existe pas.');
                }
            }],
            'fichier' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            'libelle' => ['nullable', 'string', 'max:255'],
            'date_debut' => ['nullable', 'date'],
            'date_fin' => ['nullable', 'date'],
            'solde_initial' => ['nullable', 'numeric'],
            'solde_final' => ['nullable', 'numeric'],
        ];
    }
}
