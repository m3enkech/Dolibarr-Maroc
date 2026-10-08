<?php

namespace App\Modules\Tiers\Http\Requests;

use App\Modules\Catalogue\Models\CategorieTarifaire;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Absente : le défaut de la base (entreprise). Elle n'exige rien de
            // plus : l'ICE reste facultatif pour une entreprise comme pour un
            // particulier (voir plus bas), et la forme ne s'en déduit pas.
            'forme' => ['sometimes', 'required', Rule::in(Tiers::FORMES)],
            'is_client' => ['boolean'],
            'is_supplier' => ['boolean'],

            // Qualification commerciale (CRM) : prospect + origine du lead.
            'is_prospect' => ['boolean'],
            'lead_source' => ['nullable', Rule::in(Tiers::LEAD_SOURCES)],

            // Vente à crédit : encours autorisé (null = pas de plafond) et
            // délai accordé, qui fixe l'échéance des ventes à crédit.
            'plafond_credit' => ['nullable', 'numeric', 'min:0'],
            'delai_paiement_jours' => ['nullable', 'integer', 'min:0', 'max:365'],

            // Niveau de tarif appliqué à ce client (gros, demi-gros, détail…).
            'categorie_tarifaire_id' => ['nullable', 'integer', function (string $attr, mixed $value, \Closure $fail) {
                if ($value !== null && ! CategorieTarifaire::whereKey($value)->exists()) {
                    $fail('Cette catégorie tarifaire n\'existe pas.');
                }
            }],

            // Identifiants marocains : l'ICE fait légalement 15 chiffres. Il
            // n'est exigé de PERSONNE — ni d'un particulier, qui n'en a pas, ni
            // d'une entreprise : des centaines de tiers existants n'en ont pas,
            // et l'exiger bloquerait la moindre modification de leur fiche.
            'ice' => ['nullable', 'digits:15'],
            'if_number' => ['nullable', 'string', 'max:20'],
            'rc' => ['nullable', 'string', 'max:20'],
            'patente' => ['nullable', 'string', 'max:20'],
            'cnss' => ['nullable', 'string', 'max:20'],

            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'country' => ['nullable', 'string', 'size:2'],

            // Livraison : « identique à la facturation » par défaut. Décochée,
            // il faut au moins une adresse — une livraison « ailleurs » sans
            // dire où ne mènerait le livreur nulle part. Cochée, les champs
            // sont vidés par TiersService : rien de caché ne doit ressortir sur
            // un bon de livraison le jour où l'on décoche.
            'livraison_identique' => ['boolean'],
            'adresse_livraison' => ['nullable', 'string', 'max:255', 'required_if_declined:livraison_identique'],
            'ville_livraison' => ['nullable', 'string', 'max:100'],
            'code_postal_livraison' => ['nullable', 'string', 'max:10'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Le message générique (« obligatoire quand livraison identique est
     * refusé ») parlait la langue du validateur, pas celle de l'écran : on dit
     * ce qu'il faut faire, dans la langue de l'utilisateur.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'adresse_livraison.required_if_declined' => __("Indiquez l'adresse de livraison, ou cochez « Livraison à l'adresse de facturation »."),
        ];
    }
}
