<?php

namespace App\Modules\Stock\Http\Requests;

use App\Modules\Stock\Models\Entrepot;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventaireRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entrepot_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Entrepot::whereKey($value)->exists()) {
                    $fail('Cet entrepôt n\'existe pas.');
                }
            }],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
