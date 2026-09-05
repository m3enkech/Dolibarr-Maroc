<?php

namespace App\Modules\Achats\Http\Requests;

use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Passage de commande depuis l'écran de suivi.
 *
 * On n'accepte QUE des identifiants. Aucune quantité, aucun prix, aucun
 * fournisseur imposé : tout est recalculé côté serveur, faute de quoi un écran
 * resté ouvert depuis ce matin commanderait sur des chiffres périmés.
 */
class StoreCommandeReapproRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Le plafond n'est pas décoratif : il borne le calcul de réappro qui
            // suit, dont le coût croît avec le nombre de produits ET le volume
            // de mouvements de leurs quatre-vingt-dix derniers jours.
            'produit_ids' => ['required', 'array', 'min:1', 'max:100'],
            'produit_ids.*' => ['integer', 'distinct', function (string $attribut, mixed $valeur, \Closure $echec) {
                if (! Produit::query()->whereKey($valeur)->where('type', 'product')->exists()) {
                    $echec(__('Cet article est introuvable.'));
                }
            }],

            'entrepot_id' => ['nullable', 'integer', function (string $attribut, mixed $valeur, \Closure $echec) {
                if (! Entrepot::query()->whereKey($valeur)->where('is_active', true)->exists()) {
                    $echec(__('Ce dépôt est introuvable.'));
                }
            }],

            // Repli seulement : appliqué aux articles dont le fournisseur n'a
            // pas pu être déduit, jamais à la place d'un fournisseur connu.
            'fournisseur_id' => ['nullable', 'integer', function (string $attribut, mixed $valeur, \Closure $echec) {
                if (! Tiers::query()->whereKey($valeur)->where('is_supplier', true)->exists()) {
                    $echec(__('Ce fournisseur est introuvable.'));
                }
            }],

            'valider' => ['sometimes', 'boolean'],

            // Une commande créée deux fois est un vrai dégât d'exploitation.
            // L'écran engendre cette clé une fois par sélection : un second
            // envoi rejoue la même réponse au lieu de créer à nouveau.
            'cle_idempotence' => ['required', 'uuid'],
        ];
    }
}
