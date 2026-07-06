<?php

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Models\Opportunite;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpportuniteRequest extends FormRequest
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
            'titre' => ['required', 'string', 'max:255'],
            'montant_estime' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'probabilite' => ['nullable', 'integer', 'min:0', 'max:100'],
            'etape' => ['nullable', Rule::in(Opportunite::ETAPES)],
            'date_cloture_prevue' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
