<?php

namespace App\Modules\Catalogue\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Catalogue\Models\KitComposant;
use App\Modules\Catalogue\Models\Produit;
use Illuminate\Support\Facades\DB;

class ProduitService
{
    private const PREFIXES = [
        Produit::TYPE_PRODUCT => 'PR',
        Produit::TYPE_SERVICE => 'SV',
        Produit::TYPE_KIT => 'KT',
    ];

    public function __construct(private SequenceService $sequences) {}

    public function create(array $data): Produit
    {
        return DB::transaction(function () use ($data) {
            // PR produit physique, SV service, KT kit.
            $type = $data['type'] ?? Produit::TYPE_PRODUCT;
            $data['code'] = $this->sequences->next(self::PREFIXES[$type] ?? 'PR');

            $composants = $data['composants'] ?? null;
            unset($data['composants']);

            $produit = Produit::create($data);

            if ($produit->isKit() && $composants !== null) {
                $this->syncComposants($produit, $composants);
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
