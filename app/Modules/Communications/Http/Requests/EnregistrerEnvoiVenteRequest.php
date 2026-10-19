<?php

namespace App\Modules\Communications\Http\Requests;

use App\Modules\Communications\Models\Communication;
use Illuminate\Validation\Rule;

/** Envoi d'une facture, d'un devis ou d'une confirmation de paiement. */
class EnregistrerEnvoiVenteRequest extends EnregistrerEnvoiRequest
{
    protected function reglesPropres(): array
    {
        return [
            // La relance n'est pas acceptée ici : elle relève de
            // `permission:relances`, et passe par sa propre route.
            'type' => ['required', Rule::in(Communication::TYPES_VENTES)],
            // Son appartenance au document est vérifiée par le service, avec
            // le même refus qu'il soit absent, d'un autre document ou d'une
            // autre entreprise.
            'paiement_id' => ['nullable', 'integer'],
        ];
    }
}
