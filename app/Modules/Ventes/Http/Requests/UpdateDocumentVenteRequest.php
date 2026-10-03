<?php

namespace App\Modules\Ventes\Http\Requests;

class UpdateDocumentVenteRequest extends StoreDocumentVenteRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // Le type est fixé à la création ; les lignes sont optionnelles en
        // mise à jour (absentes = inchangées).
        unset($rules['type']);
        $rules['tiers_id'][0] = 'sometimes';
        $rules['lignes'] = ['sometimes', 'array', 'min:1'];

        // Sans règle, validated() retirerait ces deux clés en silence — c'est
        // ainsi qu'un bon de livraison retouché perdait sa ligne de commande.
        // Leur appartenance se juge avec le document, donc dans VenteService :
        // `id` doit désigner une ligne de CE brouillon (sinon ignoré), et
        // `source_ligne_id` une ligne de la commande dont il est issu (sinon 422).
        // À la création, rien à reprendre : ces règles n'existent qu'ici.
        $rules['lignes.*.id'] = ['nullable', 'integer'];
        $rules['lignes.*.source_ligne_id'] = ['nullable', 'integer'];

        return $rules;
    }
}
