<?php

namespace App\Modules\Ventes\Http\Requests;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Tarif du client pour les lignes d'une pièce en cours de saisie.
 *
 * Le cloisonnement passe par les modèles SCOPÉS (Tiers, Produit) : un
 * identifiant d'une autre entreprise leur est invisible, donc refusé. Une
 * règle `exists:` interrogerait la table nue et laisserait passer l'article
 * d'un autre grossiste — et avec lui son prix de vente.
 *
 * Articles et client sont lus UNE fois ici puis servis au contrôleur : un lot
 * de trente lignes coûte le même nombre de requêtes qu'une ligne.
 */
class PrixVenteRequest extends FormRequest
{
    private ?Tiers $client = null;

    /** @var Collection<int, Produit>|null */
    private ?Collection $produits = null;

    public function rules(): array
    {
        return [
            // Facultatif : avant le choix du client, le formulaire affiche déjà
            // le tarif par défaut — celui que prixPour() applique sans client.
            'tiers_id' => ['nullable', 'integer'],

            // Le plafond borne le travail d'une requête et la longueur de l'URL
            // (lot en GET) ; l'écran découpe au-delà de cinquante lignes.
            'lignes' => ['required', 'array', 'min:1', 'max:100'],
            'lignes.*.produit_id' => ['required', 'integer'],
            // En unité de stock, comme la quantité d'une ligne : c'est elle que
            // les paliers comparent, y compris pour une ligne vendue au carton.
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // Règles de forme en échec : pas la peine d'interroger la base.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $tiersId = $this->input('tiers_id');
            if ($tiersId !== null && $tiersId !== '') {
                $this->client = Tiers::query()->find((int) $tiersId);
                if ($this->client === null) {
                    $validator->errors()->add('tiers_id', __('Ce client n\'existe pas.'));
                }
            }

            $lignes = (array) $this->input('lignes', []);
            $this->produits = Produit::query()
                ->whereIn('id', collect($lignes)->pluck('produit_id')->map(fn ($id) => (int) $id)->unique()->values())
                ->get()
                ->keyBy('id');

            foreach ($lignes as $index => $ligne) {
                if (! $this->produits->has((int) $ligne['produit_id'])) {
                    $validator->errors()->add("lignes.{$index}.produit_id", __('Cet article est introuvable.'));
                }
            }
        }];
    }

    public function client(): ?Tiers
    {
        return $this->client;
    }

    /** @return Collection<int, Produit> articles demandés, indexés par identifiant */
    public function produits(): Collection
    {
        return $this->produits ?? new Collection;
    }
}
