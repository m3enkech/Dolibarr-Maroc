<?php

namespace App\Modules\Communications\Services;

use App\Core\Format\NumeroWhatsApp;
use App\Modules\Tiers\Models\Contact;
use App\Modules\Tiers\Models\Tiers;

/**
 * Les numéros auxquels proposer d'écrire, pour un client.
 *
 * L'ORDRE EST LE CONSEIL. Un message WhatsApp va à une PERSONNE, sur son
 * mobile : les mobiles des contacts actifs d'abord (le contact principal en
 * tête), puis le téléphone de la fiche — souvent le standard —, puis le
 * téléphone des contacts. Le premier numéro qui n'est pas un fixe est
 * présélectionné ; s'il n'y a que des fixes, le premier d'entre eux.
 *
 * Dédoublonnés APRÈS normalisation : « 0661 00 00 00 » sur la fiche et
 * « +212 661000000 » sur le contact sont le même téléphone, et le proposer
 * deux fois ferait croire à deux interlocuteurs. Le premier libellé gagne,
 * c'est-à-dire le plus précis (le nom de la personne).
 *
 * Les numéros inexploitables ne disparaissent pas en silence : ils reviennent
 * à part, avec la raison du refus, pour que l'utilisateur corrige la fiche.
 */
class NumerosDuTiers
{
    /**
     * @return array{destinataires: list<array<string, mixed>>, ignores: list<array<string, string|null>>}
     */
    public function pour(?Tiers $tiers): array
    {
        if ($tiers === null) {
            return ['destinataires' => [], 'ignores' => []];
        }

        $contacts = Contact::query()
            ->where('tiers_id', $tiers->id)
            ->where('is_active', true)
            ->orderByDesc('is_principal')
            ->orderBy('nom')
            ->orderBy('id')
            ->get();

        $candidats = [];
        foreach ($contacts as $contact) {
            $candidats[] = [$contact->mobile, $contact->nom, 'contact_mobile', $contact->id];
        }
        $candidats[] = [$tiers->phone, $tiers->name, 'tiers_telephone', null];
        foreach ($contacts as $contact) {
            $candidats[] = [$contact->phone, $contact->nom, 'contact_telephone', $contact->id];
        }

        $destinataires = [];
        $ignores = [];
        $vus = [];

        foreach ($candidats as [$saisie, $nom, $source, $contactId]) {
            $libelle = $source === 'contact_mobile'
                ? __(':nom · mobile', ['nom' => $nom])
                : __(':nom · téléphone', ['nom' => $nom]);

            // Une case peut porter deux numéros (« 06… / 05… ») : chacun est
            // proposé. Une case vide n'en donne aucun, sans rien signaler.
            foreach (NumeroWhatsApp::plusieurs($saisie) as $numero) {
                if (! $numero->valide()) {
                    $ignores[] = ['libelle' => $libelle, 'saisie' => $numero->saisie, 'raison' => __($numero->raison)];

                    continue;
                }

                if (isset($vus[$numero->numero])) {
                    continue;
                }
                $vus[$numero->numero] = true;

                $destinataires[] = [
                    'numero' => $numero->numero,
                    'affichage' => $numero->affichage(),
                    'libelle' => $libelle,
                    'nom' => $nom,
                    'source' => $source,
                    'contact_id' => $contactId,
                    'nature' => $numero->nature,
                    'avertissement' => $numero->avertissement !== null ? __($numero->avertissement) : null,
                    'selectionne' => false,
                ];
            }
        }

        // Le premier qui n'est pas un fixe ; à défaut, le premier tout court.
        $choisi = array_key_first(array_filter(
            $destinataires,
            fn (array $d) => $d['nature'] !== NumeroWhatsApp::NATURE_FIXE,
        )) ?? array_key_first($destinataires);

        if ($choisi !== null) {
            $destinataires[$choisi]['selectionne'] = true;
        }

        return ['destinataires' => $destinataires, 'ignores' => $ignores];
    }
}
