<?php

namespace App\Modules\Catalogue\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Catalogue\Models\KitComposant;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Stock\Services\StockService;
use Illuminate\Support\Facades\DB;

class ProduitService
{
    private const PREFIXES = [
        Produit::TYPE_PRODUCT => 'PR',
        Produit::TYPE_SERVICE => 'SV',
        Produit::TYPE_KIT => 'KT',
    ];

    public function __construct(
        private SequenceService $sequences,
        private StockService $stock,
    ) {}

    public function create(array $data): Produit
    {
        return DB::transaction(function () use ($data) {
            // PR produit physique, SV service, KT kit. Une reprise peut imposer
            // la référence du logiciel d'origine (le SKU), que les équipes
            // connaissent par cœur ; aucune requête HTTP ne valide de `code`.
            $type = $data['type'] ?? Produit::TYPE_PRODUCT;
            $data['code'] ??= $this->sequences->next(self::PREFIXES[$type] ?? 'PR');

            $composants = $data['composants'] ?? null;
            $stockInitial = $data['stock_initial'] ?? null;
            $entrepotId = $data['entrepot_id'] ?? null;
            unset($data['composants'], $data['stock_initial'], $data['entrepot_id']);

            $produit = Produit::create($data);

            if ($produit->isKit() && $composants !== null) {
                $this->syncComposants($produit, $composants);
            }

            // Stock de départ (StoreProduitRequest l'a réservé aux produits et
            // à qui peut écrire le stock). Dans la MÊME transaction : une entrée
            // refusée ou en panne annule l'article, et la séquence — imbriquée
            // en point de sauvegarde — rend son numéro. Jamais un article créé
            // sans le stock qu'on croyait lui avoir donné.
            if ($stockInitial !== null) {
                $this->stock->entreeInitiale(
                    $produit,
                    (float) $stockInitial,
                    $entrepotId !== null ? (int) $entrepotId : null,
                );
            }

            return $produit->load('composants.composant');
        });
    }

    public function update(Produit $produit, array $data): Produit
    {
        return DB::transaction(function () use ($produit, $data) {
            // Le code est immuable ; le type aussi, car le code en dépend (PR/SV/KT).
            $composants = $data['composants'] ?? null;
            unset($data['code'], $data['type'], $data['composants']);

            $produit->update($data);

            if ($produit->isKit() && $composants !== null) {
                $this->syncComposants($produit, $composants);
            }

            return $produit->refresh()->load('composants.composant');
        });
    }

    /** Remplace la composition du kit (la liste envoyée fait foi). */
    private function syncComposants(Produit $kit, array $composants): void
    {
        KitComposant::where('kit_id', $kit->id)->delete();

        foreach ($composants as $composant) {
            KitComposant::create([
                'kit_id' => $kit->id,
                'composant_id' => $composant['produit_id'],
                'quantite' => $composant['quantite'],
            ]);
        }
    }
}
