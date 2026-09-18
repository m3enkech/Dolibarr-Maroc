<?php

namespace App\Modules\Integrations\Zoho;

use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;

/**
 * Rapatrie les clients et les fournisseurs de Zoho Books dans les tiers Dolibarr.
 *
 * CINQ PARTIS PRIS.
 *
 * 0. ON RAPPROCHE D'ABORD SUR L'IDENTIFIANT ZOHO, quand on le connaît déjà.
 *    Les deux heuristiques qui suivent ne servent qu'au PREMIER passage : dès
 *    qu'un tiers porte son `source_id`, le rejeu ne devine plus rien, il
 *    reconnaît. C'est aussi ce qui permettra aux factures de retrouver leur
 *    client sans repasser par l'ICE.
 *
 *    Un partenaire présent chez Books en client ET en fournisseur donne deux
 *    contacts pour un seul tiers : le tiers garde le PREMIER identifiant vu,
 *    celui du contact CLIENT (les clients sont parcourus d'abord) — c'est
 *    exactement celui que les factures référencent.
 *
 * 1. ON RAPPROCHE SUR L'ICE, PAS SUR LE NOM. L'ICE est l'identifiant officiel
 *    d'une entreprise marocaine : il est unique et stable. Rapprocher sur le nom
 *    créerait un doublon à la première faute de frappe, à la première abréviation
 *    (« SARL » contre « S.A.R.L. »), au premier accent. Les contacts sans ICE
 *    retombent sur le nom normalisé, faute de mieux — et c'est dit dans le rapport.
 *
 * 2. UN CONTACT CLIENT ET FOURNISSEUR NE FAIT QU'UN SEUL TIERS. Books tient deux
 *    fiches distinctes pour le même ICE selon le rôle ; Dolibarr porte les deux
 *    drapeaux sur une seule fiche. On lève donc `is_client` ou `is_supplier` sur
 *    le tiers existant au lieu d'en créer un second.
 *
 * 3. ON N'ÉCRASE JAMAIS UNE DONNÉE EXISTANTE. Un tiers déjà saisi dans Dolibarr
 *    a pu être corrigé à la main ; Books n'a pas autorité dessus. On ne remplit
 *    que les champs VIDES, et on lève les drapeaux de rôle. L'import est donc
 *    rejouable sans rien abîmer.
 *
 * 4. RIEN N'EST ÉCARTÉ EN SILENCE. Tout contact non importé revient dans le
 *    rapport avec sa raison.
 */
class ImportTiersZoho
{
    /** Tient la place d'un tiers qui aurait été créé, en simulation. */
    private const MARQUE_SIMULATION = 'simule';

    /** Nom de la passerelle, inscrit sur chaque enregistrement repris. */
    public const SOURCE = 'zoho_books';

    public function __construct(
        private ZohoBooksClient $zoho,
        private TiersService $tiers,
    ) {}

    /**
     * @param  bool  $simulation  n'écrit rien, mais rend le même rapport
     * @return array{crees: int, mis_a_jour: int, inchanges: int, details: list<array<string, string>>}
     */
    public function executer(bool $simulation = false, ?callable $progression = null): array
    {
        $rapport = ['crees' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'details' => []];

        // Index des tiers existants, chargé UNE fois : une requête par contact
        // ferait mille requêtes pour un import de mille lignes.
        $parIce = Tiers::query()
            ->whereNotNull('ice')
            ->get(['id', 'ice'])
            ->keyBy(fn (Tiers $t) => $this->normaliserIce($t->ice));

        $parNom = Tiers::query()
            ->get(['id', 'name'])
            ->keyBy(fn (Tiers $t) => $this->normaliserNom($t->name));

        $parSource = Tiers::query()
            ->where('source_systeme', self::SOURCE)
            ->whereNotNull('source_id')
            ->get(['id', 'source_id'])
            ->keyBy('source_id');

        foreach (['customer' => 'is_client', 'vendor' => 'is_supplier'] as $type => $drapeau) {
            foreach ($this->zoho->contacts($type) as $contact) {
                $resultat = $this->traiter($contact, $drapeau, $parIce, $parNom, $parSource, $simulation);

                $rapport[$resultat['issue']]++;
                $rapport['details'][] = $resultat['detail'];

                if ($progression !== null) {
                    $progression($resultat['detail']);
                }
            }
        }

        return $rapport;
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{issue: string, detail: array<string, string>}
     */
    private function traiter(array $contact, string $drapeau, $parIce, $parNom, $parSource, bool $simulation): array
    {
        $nom = trim((string) ($contact['company_name'] ?: $contact['contact_name'] ?? ''));
        $ice = $this->normaliserIce($contact['cf_ice'] ?? null);
        $zohoId = (string) ($contact['contact_id'] ?? '');

        $detail = [
            'zoho_id' => $zohoId,
            'nom' => $nom,
            'ice' => $ice ?? '',
        ];

        if ($nom === '') {
            return ['issue' => 'inchanges', 'detail' => $detail + [
                'action' => 'ecarte',
                'raison' => 'contact sans nom exploitable',
            ]];
        }

        // L'identifiant Zoho d'abord : c'est une certitude, pas une ressemblance.
        $existant = ($zohoId !== '' ? $parSource->get($zohoId) : null)
            ?? ($ice !== null
                ? $parIce->get($ice)
                : $parNom->get($this->normaliserNom($nom)));

        // Rencontré plus tôt DANS CET IMPORT, alors qu'on ne l'a pas écrit :
        // c'est le cas d'un partenaire présent chez Books en client ET en
        // fournisseur. Le vrai import fusionnera les deux ; la simulation doit
        // l'annoncer, sans quoi elle promet des créations qui n'auront pas lieu.
        if ($existant === self::MARQUE_SIMULATION) {
            return ['issue' => 'mis_a_jour', 'detail' => $detail + [
                'action' => 'fusionne',
                'raison' => 'même partenaire déjà vu dans cet import (client et fournisseur)',
            ]];
        }

        if ($existant !== null) {
            $tiers = Tiers::find($existant->id);

            if ($tiers === null) {
                return ['issue' => 'inchanges', 'detail' => $detail + [
                    'action' => 'ecarte', 'raison' => 'tiers introuvable après rapprochement',
                ]];
            }

            $changements = $this->champsACompleter($tiers, $contact, $drapeau, $zohoId);

            if ($changements === []) {
                return ['issue' => 'inchanges', 'detail' => $detail + [
                    'action' => 'inchange',
                    'raison' => $this->clefDeRapprochement($tiers, $zohoId, $ice),
                ]];
            }

            if (! $simulation) {
                $this->tiers->update($tiers, $changements);
            }

            if (isset($changements['source_id'])) {
                $parSource->put($zohoId, $tiers);
            }

            return ['issue' => 'mis_a_jour', 'detail' => $detail + [
                'action' => 'complete',
                'raison' => implode(', ', array_keys($changements)),
            ]];
        }

        // Les index suivent DANS LES DEUX MODES, pour qu'un doublon interne à
        // l'import — le même ICE en client puis en fournisseur — soit rattrapé.
        // En simulation on n'a pas de tiers à y mettre : une marque suffit, et
        // c'est elle qui permet au rapport d'annoncer la fusion à venir.
        $cree = $simulation
            ? self::MARQUE_SIMULATION
            : $this->tiers->create($this->donneesDeCreation($contact, $nom, $ice, $drapeau));

        if ($ice !== null) {
            $parIce->put($ice, $cree);
        }
        $parNom->put($this->normaliserNom($nom), $cree);

        if ($zohoId !== '') {
            $parSource->put($zohoId, $cree);
        }

        return ['issue' => 'crees', 'detail' => $detail + [
            'action' => 'cree',
            'raison' => $ice !== null ? 'nouveau (ICE)' : 'nouveau, SANS ICE',
        ]];
    }

    /**
     * @return array<string, mixed>
     *
     * PAS D'ADRESSE ICI, et c'est délibéré : la liste de contacts de Zoho ne
     * porte AUCUNE adresse — `billing_address` n'existe que sur le détail d'un
     * contact, qu'il faudrait aller chercher un par un (566 appels de plus).
     * Les remplir depuis la liste ne produirait que des valeurs vides.
     *
     * `country` n'est pas transmis non plus : la colonne est NOT NULL avec
     * « MA » par défaut, et elle fait DEUX caractères. Books rend un nom complet
     * (« Maroc ») : SQLite l'accepterait en silence, PostgreSQL le refuserait —
     * le genre d'écart qui passe en développement et casse en production. Le
     * jour où l'on voudra les adresses, il faudra une table de correspondance
     * vers les codes ISO, pas une recopie.
     */
    private function donneesDeCreation(array $contact, string $nom, ?string $ice, string $drapeau): array
    {
        return [
            'name' => $nom,
            'is_client' => $drapeau === 'is_client',
            'is_supplier' => $drapeau === 'is_supplier',
            'ice' => $ice,
            'email' => $contact['email'] ?: null,
            'phone' => $contact['phone'] ?: ($contact['mobile'] ?: null),
            'website' => $contact['website'] ?: null,
            'contact_name' => trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? '')) ?: null,
            'notes' => 'Importé de Zoho Books (contact '.($contact['contact_id'] ?? '?').').',
            'is_active' => ($contact['status'] ?? 'active') === 'active',
            'source_systeme' => self::SOURCE,
            'source_id' => ($contact['contact_id'] ?? null) ?: null,
        ];
    }

    /** Ce qui a permis de retrouver le tiers — utile quand le rapport surprend. */
    private function clefDeRapprochement(Tiers $tiers, string $zohoId, ?string $ice): string
    {
        if ($zohoId !== '' && $tiers->source_id === $zohoId && $tiers->source_systeme === self::SOURCE) {
            return 'déjà présent (identifiant Zoho)';
        }

        return $ice !== null ? 'déjà présent (ICE)' : 'déjà présent (nom)';
    }

    /**
     * Ce qu'on peut compléter sans rien écraser.
     *
     * @return array<string, mixed>
     */
    private function champsACompleter(Tiers $tiers, array $contact, string $drapeau, string $zohoId): array
    {
        $changements = [];

        // Le rôle, lui, s'ajoute toujours : c'est tout l'intérêt de la fusion.
        if (! $tiers->{$drapeau}) {
            $changements[$drapeau] = true;
        }

        // Le tiers adopte son identifiant Zoho s'il n'en porte pas encore. Un
        // tiers qui en a déjà un le GARDE : le second contact d'un partenaire
        // client-et-fournisseur ne doit pas chasser le premier, sinon le tiers
        // change d'identité à chaque import et les factures ne le retrouvent
        // plus. La contrainte d'unicité l'interdirait de toute façon.
        if ($zohoId !== '' && blank($tiers->source_id)) {
            $changements['source_systeme'] = self::SOURCE;
            $changements['source_id'] = $zohoId;
        }

        // Mêmes champs qu'à la création : pas d'adresse, la liste Zoho n'en
        // porte pas.
        $candidats = [
            'ice' => $this->normaliserIce($contact['cf_ice'] ?? null),
            'email' => $contact['email'] ?: null,
            'phone' => $contact['phone'] ?: ($contact['mobile'] ?: null),
            'contact_name' => trim(($contact['first_name'] ?? '').' '.($contact['last_name'] ?? '')) ?: null,
        ];

        foreach ($candidats as $champ => $valeur) {
            if (blank($tiers->{$champ}) && filled($valeur)) {
                $changements[$champ] = $valeur;
            }
        }

        return $changements;
    }

    /** L'ICE se compare sur ses chiffres seuls : espaces et tirets varient. */
    private function normaliserIce(?string $ice): ?string
    {
        $chiffres = preg_replace('/\D+/', '', (string) $ice);

        return $chiffres === '' ? null : $chiffres;
    }

    /** Repli quand l'ICE manque : casse, accents et espaces neutralisés. */
    private function normaliserNom(?string $nom): string
    {
        $sansAccent = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $nom) ?: (string) $nom;

        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($sansAccent)) ?? '';
    }
}
