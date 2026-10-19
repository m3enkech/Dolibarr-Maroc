<?php

namespace App\Modules\Communications\Http\Requests;

use App\Modules\Relances\Models\Relance;
use Illuminate\Validation\Rule;

/** Relance d'impayé par WhatsApp : elle crée aussi la Relance de l'historique. */
class EnregistrerEnvoiRelanceRequest extends EnregistrerEnvoiRequest
{
    protected function reglesPropres(): array
    {
        return [
            'niveau' => ['required', 'integer', Rule::in(array_keys(Relance::NIVEAUX))],
            // La même note que la relance enregistrée à la main (« promet de
            // régler vendredi ») : elle va dans l'historique des relances.
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
