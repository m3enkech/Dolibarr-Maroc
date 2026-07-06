<?php

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Models\Activite;
use App\Modules\Crm\Models\Opportunite;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActiviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tiers_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! Tiers::whereKey($value)->exists()) {
                    $fail('Ce tiers n\'existe pas.');
                }
            }],
            'opportunite_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! Opportunite::whereKey($value)->exists()) {
                    $fail('Cette opportunité n\'existe pas.');
                }
            }],
            'type' => ['required', Rule::in(Activite::TYPES)],
            'sujet' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
            'date_prevue' => ['nullable', 'date'],
            'fait' => ['boolean'],
        ];
    }
}
