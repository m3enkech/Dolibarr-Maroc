<?php

namespace App\Modules\Tiers\Services;

use App\Modules\Tiers\Models\Contact;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Support\Facades\DB;

/**
 * Les interlocuteurs d'un tiers.
 *
 * DEUX RÈGLES, et elles vivent ICI et nulle part ailleurs.
 *
 * 1. UN SEUL CONTACT PRINCIPAL PAR TIERS. C'est lui que viseront les documents
 *    et les relances : deux principaux, et l'envoi devient un tirage au sort.
 *    La règle n'est pas posée en base — une contrainte unique partielle ne
 *    s'écrit pas pareil sur SQLite et sur PostgreSQL, et une contrainte qui
 *    diverge entre développement et production est pire que pas de contrainte.
 *
 * 2. LE PREMIER CONTACT D'UN TIERS EST PRINCIPAL D'OFFICE. Sans quoi un tiers
 *    se retrouve avec des contacts mais personne à qui écrire — et l'oubli ne
 *    se voit que le jour où une relance part dans le vide.
 */
class ContactService
{
    public function create(Tiers $tiers, array $data): Contact
    {
        return DB::transaction(function () use ($tiers, $data) {
            $premier = ! Contact::where('tiers_id', $tiers->id)->exists();

            $contact = Contact::create($data + [
                'tiers_id' => $tiers->id,
                'is_principal' => $premier,
            ]);

            if ($contact->is_principal) {
                $this->rendreUnique($contact);
            }

            return $contact;
        });
    }

    public function update(Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data) {
            // Le tiers d'un contact ne se change pas : le déplacer d'un client
            // à un autre emporterait son historique de correspondance.
            unset($data['tiers_id']);

            $contact->update($data);

            if ($contact->is_principal) {
                $this->rendreUnique($contact);
            }

            return $contact->refresh();
        });
    }

    public function delete(Contact $contact): void
    {
        DB::transaction(function () use ($contact) {
            $etaitPrincipal = $contact->is_principal;
            $tiersId = $contact->tiers_id;

            $contact->delete();

            // Le tiers ne doit pas rester sans interlocuteur désigné.
            if ($etaitPrincipal) {
                Contact::where('tiers_id', $tiersId)
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_principal' => true]);
            }
        });
    }

    /** Le contact désigné devient le seul principal de son tiers. */
    private function rendreUnique(Contact $contact): void
    {
        Contact::where('tiers_id', $contact->tiers_id)
            ->whereKeyNot($contact->id)
            ->where('is_principal', true)
            ->update(['is_principal' => false]);
    }
}
