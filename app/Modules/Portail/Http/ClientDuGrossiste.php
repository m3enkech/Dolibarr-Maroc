<?php

namespace App\Modules\Portail\Http;

use App\Modules\Portail\Models\AcheteurTiers;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Http\Request;

/**
 * Compte client (Tiers) de l'acheteur chez le grossiste consulté.
 *
 * Le rattachement est posé par SetTenantPortail, qui a déjà vérifié qu'il est
 * approuvé. On ne lit donc JAMAIS l'identifiant du client dans la requête : il
 * vient du rattachement, sans quoi un acheteur pourrait désigner le compte d'un
 * autre et lire ses commandes comme ses factures.
 */
trait ClientDuGrossiste
{
    protected function client(Request $request): Tiers
    {
        /** @var AcheteurTiers $rattachement */
        $rattachement = $request->attributes->get('portail_rattachement');

        $client = Tiers::find($rattachement->tiers_id);

        abort_if($client === null, 403, __('Votre compte client n\'est plus disponible chez ce grossiste.'));

        return $client;
    }
}
