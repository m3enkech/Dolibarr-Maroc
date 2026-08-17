<?php

namespace App\Modules\Tiers\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Tiers\Models\Tiers;

class TiersService
{
    public function __construct(private SequenceService $sequences) {}

    public function create(array $data): Tiers
    {
        // CL pour un client, FR pour un fournisseur pur.
        $prefix = ($data['is_client'] ?? true) ? 'CL' : 'FR';
        $data['code'] = $this->sequences->next($prefix);

        return Tiers::create($data);
    }

    public function update(Tiers $tiers, array $data): Tiers
    {
        // Le code est immuable une fois attribué.
        unset($data['code']);
        $tiers->update($data);

        return $tiers->refresh();
    }

    /**
     * Conversion prospect → client : on lève le flag et on horodate. Le tiers
     * conserve son code et tout son historique (devis, activités, opportunités).
     * Idempotent : reconvertir un client déjà converti ne change rien.
     */
    public function convertirEnClient(Tiers $tiers): Tiers
    {
        if (! $tiers->is_prospect) {
            return $tiers;
        }

        $tiers->update([
            'is_prospect' => false,
            'is_client' => true,
            'converti_at' => now(),
        ]);

        return $tiers->refresh();
    }
}
