<?php

namespace App\Modules\Catalogue\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Catalogue\Models\Produit;

class ProduitService
{
    public function __construct(private SequenceService $sequences) {}

    public function create(array $data): Produit
    {
        // PR pour un produit physique, SV pour un service.
        $prefix = ($data['type'] ?? 'product') === 'service' ? 'SV' : 'PR';
        $data['code'] = $this->sequences->next($prefix);

        return Produit::create($data);
    }

    public function update(Produit $produit, array $data): Produit
    {
        // Le code est immuable ; le type aussi, car le code en dépend (PR/SV).
        unset($data['code'], $data['type']);
        $produit->update($data);

        return $produit->refresh();
    }
}
