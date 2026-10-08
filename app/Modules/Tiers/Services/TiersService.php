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

        return Tiers::create($this->livraison($data, true));
    }

    public function update(Tiers $tiers, array $data): Tiers
    {
        // Le code est immuable une fois attribué.
        unset($data['code']);
        $tiers->update($this->livraison($data, $tiers->livraison_identique));

        return $tiers->refresh();
    }

    /**
     * Une livraison « identique à la facturation » n'a pas d'adresse propre :
     * les champs sont VIDÉS, quel que soit ce qu'envoie l'appelant. Laissés en
     * place, ils dormiraient sous la case cochée et ressortiraient sur le bon
     * de livraison du jour où quelqu'un la décoche — une ancienne adresse que
     * personne n'a relue.
     *
     * Le drapeau qui compte est celui d'APRÈS la requête : envoyé, il décide ;
     * absent (une mise à jour partielle, `is_active` seul), c'est celui du
     * tiers — et une requête partielle qui n'y touche pas ne vide rien.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function livraison(array $data, bool $identiqueActuel): array
    {
        $identique = array_key_exists('livraison_identique', $data)
            ? (bool) $data['livraison_identique']
            : $identiqueActuel;

        $champs = ['adresse_livraison', 'ville_livraison', 'code_postal_livraison'];
        $touche = array_key_exists('livraison_identique', $data) || array_intersect($champs, array_keys($data)) !== [];

        if ($identique && $touche) {
            foreach ($champs as $champ) {
                $data[$champ] = null;
            }
        }

        return $data;
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
