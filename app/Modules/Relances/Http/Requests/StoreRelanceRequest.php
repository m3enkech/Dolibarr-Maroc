<?php

namespace App\Modules\Relances\Http\Requests;

use App\Modules\Relances\Models\Relance;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRelanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_vente_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! DocumentVente::whereKey($value)->where('type', DocumentVente::TYPE_FACTURE)->exists()) {
                    $fail('Cette facture n\'existe pas.');
                }
            }],
            'niveau' => ['required', 'integer', Rule::in(array_keys(Relance::NIVEAUX))],
            'canal' => ['nullable', Rule::in(['courrier', 'email', 'telephone', 'autre'])],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
