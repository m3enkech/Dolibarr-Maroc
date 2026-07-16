<?php

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Stock\Models\Entrepot;
use Illuminate\Foundation\Http\FormRequest;

class OuvrirSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fond_caisse' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            // Entrepôt rattaché à la caisse (scope tenant via le global scope Eloquent).
            'entrepot_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! Entrepot::whereKey($value)->exists()) {
                    $fail('Cet entrepôt n\'existe pas.');
                }
            }],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
