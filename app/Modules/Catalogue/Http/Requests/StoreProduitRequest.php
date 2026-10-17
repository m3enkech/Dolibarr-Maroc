<?php

namespace App\Modules\Catalogue\Http\Requests;

use App\Core\Auth\Roles;
use App\Modules\Catalogue\Models\CategorieProduit;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Models\Entrepot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProduitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(Produit::TYPES)],

            // Composition (kits uniquement) : des produits ou services, jamais un kit.
            'composants' => ['nullable', 'array'],
            'composants.*.produit_id' => ['required', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                $composant = Produit::find($value);
                if ($composant === null) {
                    $fail('Ce composant n\'existe pas.');
                } elseif ($composant->isKit()) {
                    $fail('Un kit ne peut pas contenir un autre kit.');
                }
            }],
            'composants.*.quantite' => ['required', 'numeric', 'gt:0'],
            'categorie_produit_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && ! CategorieProduit::whereKey($value)->exists()) {
                    $fail('Cette catégorie n\'existe pas.');
                }
            }],

            'sell_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'buy_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            // Seuls les taux de TVA marocains sont acceptés.
            'tva_rate' => ['required', 'numeric', Rule::in(Produit::TVA_RATES)],

            'unit' => ['nullable', 'string', 'max:20'],
            // Seuils de réapprovisionnement (produits stockables). stock_reappro
            // sert de quantité cible pour suggérer la commande ; s'il est fourni,
            // il doit être au moins égal au seuil d'alerte.
            'stock_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'stock_reappro' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'gte:stock_min'],
            'barcode' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],

            // Stock de départ, à la création seulement (UpdateProduitRequest le
            // refuse : ensuite, le stock se corrige par mouvement ou inventaire).
            //
            // Le droit d'écrire le stock est vérifié ICI, champ par champ : la
            // route n'exige que le catalogue, et les deux droits sont
            // indépendants dans la matrice des rôles. Le refus porte sur toute
            // la requête — jamais un article créé en ignorant la quantité en
            // silence, que l'utilisateur croirait en rayon.
            //
            // Plafond : 999 999 999, ce que tient la colonne (12 chiffres dont
            // 3 décimales). Au-delà, PostgreSQL rejetterait l'écriture en
            // erreur serveur au lieu d'un refus lisible.
            'stock_initial' => ['nullable', 'bail',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $this->user()?->hasPermission('stock', Roles::WRITE)) {
                        $fail(__('Vous n\'avez pas le droit d\'alimenter le stock.'));
                    }
                },
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($this->input('type') !== Produit::TYPE_PRODUCT) {
                        $fail(__('Seul un produit a un stock : un service ou un kit ne reçoit pas de stock initial.'));
                    }
                },
                'numeric', 'gt:0', 'max:999999999', 'decimal:0,3',
            ],
            // Le dépôt ne sert qu'à recevoir le stock initial : seul, il est
            // REFUSÉ plutôt qu'ignoré — un client qui croirait fixer le dépôt
            // habituel de l'article doit l'apprendre. Absent, c'est le dépôt
            // par défaut. Lu par le modèle (scopé) et non par Rule::exists, qui
            // accepterait le dépôt d'une autre entreprise. `bail` : un
            // identifiant non entier n'atteint pas la requête, que PostgreSQL
            // rejetterait en erreur serveur.
            'entrepot_id' => ['nullable', 'bail', 'integer', function (string $attribute, mixed $value, \Closure $fail) {
                if (! $this->filled('stock_initial')) {
                    $fail(__('Le dépôt ne sert qu\'au stock initial : indiquez une quantité, ou retirez le dépôt.'));

                    return;
                }

                $entrepot = Entrepot::query()->whereKey($value)->first(['id', 'is_active']);

                if ($entrepot === null) {
                    $fail(__('Ce dépôt est introuvable.'));
                } elseif (! $entrepot->is_active) {
                    $fail(__('Ce dépôt est désactivé : choisissez un dépôt actif.'));
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'tva_rate.in' => 'Le taux de TVA doit être un taux marocain valide : 0, 7, 10, 14 ou 20 %.',
            'stock_initial.numeric' => __('Le stock initial doit être un nombre.'),
            'stock_initial.gt' => __('Le stock initial doit être supérieur à zéro.'),
            'stock_initial.max' => __('Le stock initial ne peut pas dépasser 999 999 999.'),
            'stock_initial.decimal' => __('Le stock initial accepte au plus 3 décimales.'),
        ];
    }
}
