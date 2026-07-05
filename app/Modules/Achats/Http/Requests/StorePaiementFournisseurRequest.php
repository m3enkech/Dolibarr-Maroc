<?php

namespace App\Modules\Achats\Http\Requests;

use App\Modules\Achats\Models\PaiementFournisseur;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaiementFournisseurRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'montant' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(PaiementFournisseur::MODES)],
            'date_paiement' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
