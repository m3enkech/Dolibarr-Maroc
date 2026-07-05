<?php

namespace App\Modules\Compta\Http\Requests;

use App\Modules\Compta\Models\Compte;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Code CGNC : commence par la classe (1-7), 4 à 8 chiffres.
            'code' => ['required', 'regex:/^[1-7][0-9]{3,7}$/', function (string $attribute, mixed $value, \Closure $fail) {
                if (Compte::where('code', $value)->exists()) {
                    $fail('Ce code de compte existe déjà.');
                }
            }],
            'label' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Le code doit suivre le CGNC : premier chiffre = classe (1 à 7), 4 chiffres minimum.',
        ];
    }
}
