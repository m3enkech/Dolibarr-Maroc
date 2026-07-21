<?php

namespace App\Modules\Pos\Http\Requests;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVentePosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Client facultatif : sans tiers, la vente part sur le client comptoir.
            'tiers_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! Tiers::whereKey($value)->where('is_client', true)->exists()) {
                    $fail('Ce client n\'existe pas.');
                }
            }],

            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! Produit::whereKey($value)->exists()) {
                    $fail('Ce produit n\'existe pas.');
                }
            }],
            'lignes.*.designation' => ['required_without:lignes.*.produit_id', 'nullable', 'string', 'max:255'],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'lignes.*.prix_unitaire' => ['nullable', 'numeric', 'min:0'],
            'lignes.*.remise_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lignes.*.tva_rate' => ['nullable', 'numeric', Rule::in(Produit::TVA_RATES)],

            'paiements' => ['required', 'array', 'min:1'],
            'paiements.*.mode' => ['required', Rule::in(Paiement::MODES)],
            'paiements.*.montant' => ['required', 'numeric', 'gt:0'],
            'paiements.*.reference' => ['nullable', 'string', 'max:255'],

            // Espèces réellement remises par le client (pour afficher le rendu).
            'montant_donne' => ['nullable', 'numeric', 'min:0'],

            // Clé d'idempotence (caisse hors-ligne) : rejouer la même vente ne
            // crée pas de doublon.
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }
}
