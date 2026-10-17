<?php

namespace App\Modules\Catalogue\Http\Requests;

class UpdateProduitRequest extends StoreProduitRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['sell_price'] = ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999'];
        $rules['tva_rate'][0] = 'sometimes';

        // Le type est immuable après création (le code PR/SV en dépend).
        unset($rules['type']);

        // Le stock initial n'existe qu'à la création. Ensuite, un stock se
        // corrige par un mouvement ou un inventaire, qui laissent une trace
        // datée et motivée. Refusé plutôt qu'ignoré : un client qui croirait
        // avoir remis le stock à niveau doit apprendre que rien n'a bougé.
        $rules['stock_initial'] = ['prohibited'];
        $rules['entrepot_id'] = ['prohibited'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'stock_initial.prohibited' => __('Le stock d\'un article existant se corrige par un mouvement ou un inventaire.'),
            'entrepot_id.prohibited' => __('Le stock d\'un article existant se corrige par un mouvement ou un inventaire.'),
        ];
    }
}
