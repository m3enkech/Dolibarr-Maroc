<?php

namespace App\Modules\Integrations\Zoho;

use App\Core\Format\CleDeRapprochement;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Compta\Models\Exercice;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Stock\Services\StockService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Rapatrie les factures de vente de Zoho Books.
 *
 * C'est la reprise qui porte le chiffre d'affaires : quatre ans d'historique,
 * mille trois cents pièces, et une comptabilité qui doit tomber juste. D'où
 * sept partis pris, chacun payé d'une erreur possible.
 *
 * 1. LA FACTURE GARDE SON NUMÉRO. « MDK24-00110 » est imprimé sur le papier que
 *    le client détient et cité dans ses règlements ; renuméroter en « FA-0001 »
 *    rendrait l'archive introuvable le jour d'un contrôle. `VenteService`
 *    accepte donc un code imposé, réservé aux reprises.
 *
 * 2. LE MONTANT DE BOOKS FAIT FOI. Après écriture, le total du document est
 *    comparé à celui de Books. L'écart que l'ARRONDI DU PRIX UNITAIRE peut
 *    expliquer — Books porte cinq décimales, la colonne en stocke deux, et
 *    l'erreur se multiplie par la quantité — est absorbé sur la ligne qui l'a
 *    créé, et signalé au rapport. Tout écart AU-DELÀ de cette borne vient
 *    d'autre chose : la facture est alors annulée et listée. Un trou qu'on voit
 *    vaut mieux qu'un chiffre d'affaires faux qu'on ne voit pas — et comme
 *    l'import est rejouable, la pièce corrigée rentrera au passage suivant.
 *
 * 3. LA REMISE EST DÉDUITE DES MONTANTS, PAS LUE. Le champ `discount` de Books
 *    vaut tantôt un pourcentage, tantôt une somme, selon un réglage
 *    d'organisation. Le rapport entre le brut (quantité × prix) et le net
 *    (`item_total`), lui, ne dépend d'aucun réglage.
 *
 * 4. ON NE PASSE PAS PAR `valider()`. La validation d'une facture vivante
 *    déclenche des effets destinés au présent — sortie de stock, et demain
 *    télédéclaration ou relance. Une reprise doit écrire la comptabilité et
 *    RIEN D'AUTRE, sauf demande explicite. L'import appelle donc lui-même ce
 *    qu'il veut déclencher, et le dit.
 *
 * 5. LE STOCK NE BOUGE PAS PAR DÉFAUT. Books ne suivait aucun stock : sortir
 *    quatre ans de ventes sans le moindre achat en regard enfoncerait chaque
 *    article à des milliers d'unités négatives. Le stock de départ s'établit par
 *    un inventaire, à la date du jour — pas en rejouant l'histoire.
 *
 * 6. LES RÈGLEMENTS SUIVENT LES FACTURES. Sans eux, mille trois cents factures
 *    soldées depuis des années s'afficheraient comme impayées et la balance âgée
 *    ne voudrait plus rien dire.
 *
 * 7. UNE FACTURE DÉJÀ REPRISE NE COÛTE MÊME PAS SON APPEL RÉSEAU. Le détail
 *    d'une facture demande une requête ; on ne la fait qu'après avoir vérifié
 *    que la pièce manque. Un import interrompu se relance sans tout refaire.
 */
class ImportFacturesZoho
{
    /** Ni un brouillon ni une facture annulée n'ont d'existence comptable. */
    private const STATUTS_IGNORES = ['draft', 'void'];

    /** Le mode de règlement n'est pas dans Books : tout passe en « autre ». */
    private const MODE_REGLEMENT = 'autre';

    /**
     * Clés des clients créés faute d'être dans les contacts, pour ne les
     * compter qu'une fois. En simulation l'annuaire ne peut pas les retenir
     * (leur création est annulée avec le reste), mais le rapport, lui, doit
     * annoncer un client créé et non un par facture.
     *
     * @var array<string, true>
     */
    private array $clientsCrees = [];

    public function __construct(
        private ZohoBooksClient $zoho,
        private VenteService $ventes,
        private TiersService $tiersService,
        private ComptaService $compta,
        private StockService $stock,
    ) {}

    /**
     * @param  array{simulation?: bool, avec_stock?: bool, avec_paiements?: bool, depuis?: string}  $options
     * @return array{importees: int, deja_presentes: int, ignorees: int, refusees: int,
     *               ca_ht: float, exercice_clos: ?int, details: list<array<string, string>>}
     */
    public function executer(array $options = [], ?callable $progression = null): array
    {
        $simulation = (bool) ($options['simulation'] ?? false);
        $avecStock = (bool) ($options['avec_stock'] ?? false);
        $avecPaiements = (bool) ($options['avec_paiements'] ?? true);

        // Le verrou de clôture refusera toute écriture datée de cette année ou
        // d'avant. On le lit AVANT d'ouvrir le robinet, pour pouvoir le dire
        // d'emblée plutôt qu'à la trois-centième facture.
        $clos = Exercice::max('annee');

        $rapport = [
            'importees' => 0, 'deja_presentes' => 0, 'ignorees' => 0, 'refusees' => 0,
            'ca_ht' => 0.0,
            'exercice_clos' => $clos !== null ? (int) $clos : null,
            'details' => [],
        ];

        // Le plan comptable est créé à la première écriture. En simulation, les
        // transactions sont annulées une à une : sans cette initialisation
        // préalable, il serait recréé mille trois cents fois. C'est la SEULE
        // chose qu'une simulation écrit — un plan de comptes vierge, que la
        // première facture réelle aurait créé de toute façon.
        $this->compta->initialiserPlanComptable();

        $index = $this->chargerIndex();
        $filtres = isset($options['depuis']) ? ['date_start' => $options['depuis']] : [];

        foreach ($this->zoho->factures($filtres) as $resume) {
            $resultat = $this->traiter($resume, $index, $simulation, $avecStock, $avecPaiements);

            $rapport[$resultat['issue']]++;
            $rapport['ca_ht'] = round($rapport['ca_ht'] + ($resultat['ht'] ?? 0.0), 2);
            $rapport['details'][] = $resultat['detail'];

            if ($progression !== null) {
                $progression($resultat['detail']);
            }
        }

        return $rapport;
    }

    /* ------------------------------------------------------------------ */

    /**
     * Les trois annuaires dont chaque facture a besoin, chargés une fois.
     *
     * @return array<string, \Illuminate\Support\Collection>
     */
    private function chargerIndex(): array
    {
        $tiers = Tiers::query()->get(['id', 'name', 'ice', 'source_systeme', 'source_id']);

        return [
            'factures' => DocumentVente::query()
                ->where('source_systeme', ImportTiersZoho::SOURCE)
                ->whereNotNull('source_id')
                ->pluck('source_id')
                ->flip(),

            'tiers_source' => $tiers
                ->where('source_systeme', ImportTiersZoho::SOURCE)
                ->whereNotNull('source_id')
                ->keyBy('source_id'),

            'tiers_ice' => $tiers
                ->filter(fn (Tiers $t) => filled($t->ice))
                ->keyBy(fn (Tiers $t) => $this->chiffres($t->ice)),

            // Un nom qui ne donne aucune clé exploitable n'entre pas : sinon
            // tous ces tiers se ramassent dans la case « » et le dernier écrase
            // les autres.
            'tiers_nom' => $tiers
                ->keyBy(fn (Tiers $t) => $this->normaliserNom($t->name))
                ->forget(''),

            'produits' => Produit::query()
                ->where('source_systeme', ImportTiersZoho::SOURCE)
                ->whereNotNull('source_id')
                ->get(['id', 'source_id'])
                ->keyBy('source_id'),
        ];
    }

    /**
     * @return array{issue: string, detail: array<string, string>, ht?: float}
     */
    private function traiter(array $resume, array &$index, bool $simulation, bool $avecStock, bool $avecPaiements): array
    {
        $zohoId = (string) ($resume['invoice_id'] ?? '');
        $numero = (string) ($resume['invoice_number'] ?? '');
        $statut = (string) ($resume['status'] ?? '');

        $detail = [
            'zoho_id' => $zohoId,
            'numero' => $numero,
            'date' => (string) ($resume['date'] ?? ''),
            'client' => (string) ($resume['customer_name'] ?? ''),
            'total' => (string) ($resume['total'] ?? ''),
        ];

        if (in_array($statut, self::STATUTS_IGNORES, true)) {
            return ['issue' => 'ignorees', 'detail' => $detail + [
                'action' => 'ignore',
                'raison' => $statut === 'void' ? 'facture annulée chez Books' : 'brouillon chez Books',
            ]];
        }

        // Avant de payer un appel réseau : l'a-t-on déjà ?
        if ($index['factures']->has($zohoId)) {
            return ['issue' => 'deja_presentes', 'detail' => $detail + [
                'action' => 'inchange', 'raison' => 'déjà reprise',
            ]];
        }

        try {
            $facture = $this->zoho->facture($zohoId);
        } catch (Throwable $e) {
            return ['issue' => 'refusees', 'detail' => $detail + [
                'action' => 'refuse', 'raison' => 'détail illisible : '.$e->getMessage(),
            ]];
        }

        try {
            return $this->ecrire($facture, $detail, $index, $simulation, $avecStock, $avecPaiements);
        } catch (Throwable $e) {
            // Une facture qui échoue ne doit pas emporter les mille suivantes :
            // sa transaction est déjà annulée, on note la cause et on continue.
            return ['issue' => 'refusees', 'detail' => $detail + [
                'action' => 'refuse',
                'raison' => Str::limit($this->messageLisible($e), 200),
            ]];
        }
    }

    /**
     * @return array{issue: string, detail: array<string, string>, ht: float}
     */
    private function ecrire(array $facture, array $detail, array &$index, bool $simulation, bool $avecStock, bool $avecPaiements): array
    {
        $numero = trim((string) ($facture['invoice_number'] ?? ''));
        $date = (string) ($facture['date'] ?? '');

        if ($numero === '' || $date === '') {
            throw new RuntimeException('facture sans numéro ou sans date');
        }

        $lignes = $this->lignes($facture, $index['produits']);

        if ($lignes === []) {
            throw new RuntimeException('facture sans aucune ligne exploitable');
        }

        $attendu = round((float) ($facture['total'] ?? 0), 2);
        $htEcrit = 0.0;
        $raison = [];

        // Ce qu'il faudra inscrire à l'annuaire SI la facture est écrite pour de
        // bon. Rien n'y entre depuis l'intérieur de la transaction : voir plus
        // bas, à l'endroit où on l'applique.
        $aIndexer = null;

        $ecriture = function () use (
            $facture, $numero, $date, $lignes, $attendu, &$index,
            $avecStock, $avecPaiements, &$htEcrit, &$raison, &$aIndexer
        ): void {
            [$tiers, $noteTiers, $aIndexer] = $this->tiersDeLaFacture($facture, $index);

            if ($noteTiers !== null) {
                $raison[] = $noteTiers;
            }

            $document = $this->ventes->create([
                'type' => DocumentVente::TYPE_FACTURE,
                'code' => $numero,
                'tiers_id' => $tiers->id,
                'date_document' => $date,
                'date_echeance' => ($facture['due_date'] ?? '') ?: null,
                // Le bon de commande du client : c'est la clé dont son service
                // comptable a besoin pour rapprocher la facture, et il figure
                // sur le PDF.
                'reference_client' => Str::limit($this->bonDeCommande($facture), 60, '') ?: null,
                'notes' => $this->notes($facture),
                'source_systeme' => ImportTiersZoho::SOURCE,
                'source_id' => (string) ($facture['invoice_id'] ?? ''),
                'lignes' => $lignes,
            ]);

            $obtenu = round((float) $document->total_ttc, 2);
            $ecart = round($attendu - $obtenu, 2);
            $budget = $this->budgetDArrondi($lignes);

            if (abs($ecart) > $budget) {
                throw new RuntimeException(sprintf(
                    'total incohérent : %.2f chez Books, %.2f une fois les lignes reprises '
                    .'(écart de %+.2f, au-delà des %.2f que l\'arrondi peut expliquer)',
                    $attendu,
                    $obtenu,
                    $ecart,
                    $budget,
                ));
            }

            if (abs($ecart) > 0.004) {
                $this->absorberEcart($document, $ecart);
                $raison[] = sprintf('écart d\'arrondi de %+.2f absorbé', $ecart);
            }

            $htEcrit = round((float) $document->total_ht, 2);

            // Validation explicite : le statut, la date de validation, puis les
            // seules conséquences voulues. Voir le parti pris n° 4.
            $document->update([
                'statut' => DocumentVente::STATUT_VALIDE,
                'validated_at' => Carbon::parse($date),
            ]);

            $this->compta->ecrireVente($document->fresh(['lignes.produit.categorieProduit', 'tiers']));

            if ($avecStock) {
                $this->stock->sortieVente($document->fresh(['lignes']));
            }

            $regle = round((float) ($facture['payment_made'] ?? 0), 2);

            if ($avecPaiements && $regle > 0.005) {
                $this->reglement($document, $facture, $regle, $raison);
            }
        };

        if ($simulation) {
            // La simulation emprunte EXACTEMENT le chemin réel, puis l'annule :
            // c'est le seul moyen qu'elle annonce ce qui se passera vraiment,
            // arrondis et refus compris.
            try {
                DB::transaction(function () use ($ecriture) {
                    $ecriture();

                    throw new SimulationTerminee;
                });
            } catch (SimulationTerminee) {
                // Annulation voulue.
            }
        } else {
            DB::transaction($ecriture);

            // APRÈS le commit, et seulement après.
            //
            // L'annuaire vit en mémoire ; le rollback d'une transaction ne le
            // touche pas. Y inscrire un tiers depuis l'INTÉRIEUR de la
            // transaction, c'est garder son identifiant alors que sa ligne a
            // disparu — et un refus est ici chose courante : exercice clôturé,
            // total incohérent. Les factures suivantes du même client
            // pointeraient alors sur une ligne morte : sur PostgreSQL, violation
            // de clé étrangère en cascade ; sur SQLite, pire encore, le rowid
            // est réattribué et la créance part chez un AUTRE client, sans un
            // mot au rapport.
            if ($aIndexer !== null) {
                $this->indexer($index, $aIndexer);
            }

            $index['factures']->put((string) ($facture['invoice_id'] ?? ''), true);
        }

        return [
            'issue' => 'importees',
            'ht' => $htEcrit,
            'detail' => $detail + [
                'action' => 'importe',
                'raison' => $raison === [] ? 'reprise' : implode(' ; ', $raison),
            ],
        ];
    }

    /**
     * Les lignes du document, dans l'ordre de la facture, frais et ajustements
     * compris — sans quoi le total ne tomberait jamais juste.
     *
     * @return list<array<string, mixed>>
     */
    private function lignes(array $facture, $produits): array
    {
        $lignes = [];

        foreach ($facture['line_items'] ?? [] as $item) {
            $quantite = round((float) ($item['quantity'] ?? 0), 3);
            $prix = round((float) ($item['rate'] ?? 0), 2);

            if (abs($quantite) < 0.0005) {
                continue; // ligne de commentaire : aucun montant à reprendre
            }

            $brut = round($quantite * $prix, 2);
            $net = round((float) ($item['item_total'] ?? $brut), 2);

            // Voir le parti pris n° 3 : la remise se déduit, elle ne se lit pas.
            $remise = $brut > 0.005 ? round((1 - $net / $brut) * 100, 2) : 0.0;

            if ($remise < 0 || $remise > 100) {
                $remise = 0.0; // hors barème : l'écart de total le signalera
            }

            $lignes[] = [
                'produit_id' => $produits->get((string) ($item['item_id'] ?? ''))?->id,
                'designation' => $this->designation($item),
                'quantite' => $quantite,
                'prix_unitaire' => $prix,
                'remise_percent' => $remise,
                'tva_rate' => round((float) ($item['tax_percentage'] ?? 0), 2),
            ];
        }

        $port = round((float) ($facture['shipping_charge_exclusive_of_tax'] ?? $facture['shipping_charge'] ?? 0), 2);

        if (abs($port) > 0.005) {
            $lignes[] = $this->ligneForfaitaire('Frais de port', $port, (float) ($facture['shipping_charge_tax_percentage'] ?: 0));
        }

        foreach (['adjustment' => $facture['adjustment_description'] ?? 'Ajustement', 'roundoff_value' => 'Arrondi'] as $champ => $libelle) {
            $montant = round((float) ($facture[$champ] ?? 0), 2);

            if (abs($montant) > 0.005) {
                $lignes[] = $this->ligneForfaitaire((string) ($libelle ?: 'Ajustement'), $montant, 0);
            }
        }

        return $lignes;
    }

    /** @return array<string, mixed> */
    private function ligneForfaitaire(string $libelle, float $montant, float $tva): array
    {
        return [
            'produit_id' => null,
            'designation' => $libelle,
            'quantite' => 1,
            'prix_unitaire' => $montant,
            'remise_percent' => 0,
            'tva_rate' => $tva,
        ];
    }

    private function designation(array $item): string
    {
        $nom = trim((string) ($item['name'] ?? ''));

        if ($nom === '') {
            $nom = trim((string) ($item['description'] ?? '')) ?: 'Article';
        }

        return Str::limit($nom, 250, '');
    }

    /**
     * Le client de la facture : son identifiant Zoho d'abord, son ICE ensuite,
     * son nom en dernier recours. Introuvable, il est créé — une facture sans
     * client ne s'écrit pas, et l'écarter creuserait un trou dans le CA.
     *
     * Ne touche PAS à l'annuaire : elle rend ce qu'il faudrait y inscrire, et
     * c'est l'appelant qui l'inscrit une fois la transaction validée.
     *
     * @return array{0: Tiers, 1: ?string, 2: ?array{tiers: Tiers, zoho_id: string, ice: string, nom: string}}
     */
    private function tiersDeLaFacture(array $facture, array &$index): array
    {
        $zohoId = (string) ($facture['customer_id'] ?? '');
        $ice = $this->chiffres((string) ($facture['cf_ice'] ?? ''));
        $nom = trim((string) ($facture['customer_name'] ?? '')) ?: 'Client repris de Zoho Books';
        $cleNom = $this->normaliserNom($nom);

        $trouve = ($zohoId !== '' ? $index['tiers_source']->get($zohoId) : null)
            ?? ($ice !== '' ? $index['tiers_ice']->get($ice) : null)
            ?? ($cleNom !== '' ? $index['tiers_nom']->get($cleNom) : null);

        if ($trouve !== null) {
            return [$trouve, null, null];
        }

        $adresse = $facture['billing_address'] ?? [];
        $pays = strtoupper(trim((string) ($adresse['country_code'] ?? '')));

        $tiers = $this->tiersService->create([
            'name' => Str::limit($nom, 250, ''),
            'is_client' => true,
            'is_supplier' => false,
            'ice' => $ice !== '' ? Str::limit($ice, 15, '') : null,
            'address' => Str::limit(trim((string) ($adresse['address'] ?? '')), 250, '') ?: null,
            'city' => Str::limit(trim((string) ($adresse['city'] ?? '')), 100, '') ?: null,
            'postal_code' => Str::limit(trim((string) ($adresse['zip'] ?? '')), 10, '') ?: null,
            'country' => strlen($pays) === 2 ? $pays : 'MA',
            'notes' => 'Créé par la reprise Zoho Books : absent de la liste des contacts.',
            'source_systeme' => ImportTiersZoho::SOURCE,
            'source_id' => $zohoId ?: null,
        ]);

        $cle = $zohoId ?: ($ice ?: ($cleNom ?: $nom));
        $premiereFois = ! isset($this->clientsCrees[$cle]);
        $this->clientsCrees[$cle] = true;

        return [
            $tiers,
            $premiereFois ? 'client créé au passage (absent des contacts)' : null,
            ['tiers' => $tiers, 'zoho_id' => $zohoId, 'ice' => $ice, 'nom' => $cleNom],
        ];
    }

    /**
     * Inscrit à l'annuaire un tiers DÉJÀ COMMITÉ, pour que les factures
     * suivantes du même client le retrouvent au lieu d'en créer un deuxième.
     *
     * @param  array{tiers: Tiers, zoho_id: string, ice: string, nom: string}  $entree
     */
    private function indexer(array &$index, array $entree): void
    {
        if ($entree['zoho_id'] !== '') {
            $index['tiers_source']->put($entree['zoho_id'], $entree['tiers']);
        }
        if ($entree['ice'] !== '') {
            $index['tiers_ice']->put($entree['ice'], $entree['tiers']);
        }
        if ($entree['nom'] !== '') {
            $index['tiers_nom']->put($entree['nom'], $entree['tiers']);
        }
    }

    /** @param  list<string>  $raison */
    private function reglement(DocumentVente $document, array $facture, float $regle, array &$raison): void
    {
        $reste = $document->fresh()->resteAPayer();
        $montant = round(min($regle, $reste), 2);

        if ($montant <= 0.005) {
            return;
        }

        if ($montant < $regle - 0.005) {
            $raison[] = sprintf('règlement ramené à %.2f (reste dû)', $montant);
        }

        $this->ventes->ajouterPaiement($document->fresh(), [
            'date_paiement' => ($facture['last_payment_date'] ?? '') ?: (string) $facture['date'],
            'montant' => $montant,
            'mode' => self::MODE_REGLEMENT,
            'reference' => 'Reprise Zoho Books',
        ]);
    }

    private function notes(array $facture): string
    {
        $notes = 'Reprise Zoho Books — facture '.($facture['invoice_number'] ?? '?').'.';

        if ($commande = $this->bonDeCommande($facture)) {
            $notes .= ' Commande '.$commande.'.';
        }

        return $notes;
    }

    /**
     * Ce que l'arrondi du prix unitaire peut expliquer, en dirhams TTC.
     *
     * Books porte ses prix à cinq décimales — 0,93333 DH l'impression A4 —
     * quand `prix_unitaire` en stocke deux. L'erreur est d'un demi-centime au
     * plus PAR UNITÉ, donc elle se multiplie par la quantité : sur 1 500
     * impressions, elle atteint 7,50 DH. Ce n'est pas un défaut à corriger,
     * c'est la conséquence arithmétique d'un choix assumé.
     *
     * D'où ce budget, qui n'est pas une tolérance arbitraire mais la borne
     * exacte de ce que l'arrondi peut produire. En dessous, on absorbe et on le
     * dit ; au-dessus, l'écart vient d'autre chose — une ligne manquante, une
     * remise d'en-tête non modélisée — et la facture est refusée.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function budgetDArrondi(array $lignes): float
    {
        $budget = 0.02;

        foreach ($lignes as $ligne) {
            $ttc = 1 + (float) $ligne['tva_rate'] / 100;

            // Le demi-centime perdu sur le prix, multiplié par la quantité…
            $budget += 0.005 * abs((float) $ligne['quantite']) * $ttc;
            // …plus l'arrondi du montant de la ligne elle-même.
            $budget += 0.01 * $ttc;
        }

        return round($budget, 2);
    }

    /**
     * Fait tomber le document sur le montant de Books, au centime près.
     *
     * L'écart est porté par la ligne la PLUS GROSSE — c'est elle qui l'a créé,
     * l'erreur étant proportionnelle à la quantité — et réparti entre HT et TVA
     * selon le taux de cette ligne, pour que les deux tombent juste et pas
     * seulement leur somme.
     *
     * Pas de ligne « Arrondi » ajoutée : elle porterait un montant NÉGATIF une
     * fois sur deux, formerait son propre compte de vente, et l'écriture
     * partirait déséquilibrée. Corriger la ligne existante ne crée aucun de ces
     * problèmes — et `montant_ht` est de toute façon un montant FIGÉ à la
     * saisie, jamais un produit recalculé.
     */
    private function absorberEcart(DocumentVente $document, float $ecart): void
    {
        $document->load('lignes');

        $ligne = $document->lignes->sortByDesc(fn ($l) => abs((float) $l->montant_ttc))->first();

        if ($ligne === null) {
            return;
        }

        $deltaHt = round($ecart / (1 + (float) $ligne->tva_rate / 100), 2);
        $deltaTva = round($ecart - $deltaHt, 2);

        $ligne->update([
            'montant_ht' => round((float) $ligne->montant_ht + $deltaHt, 2),
            'montant_tva' => round((float) $ligne->montant_tva + $deltaTva, 2),
            'montant_ttc' => round((float) $ligne->montant_ttc + $ecart, 2),
        ]);

        $document->load('lignes');

        $document->update([
            'total_ht' => round($document->lignes->sum(fn ($l) => (float) $l->montant_ht), 2),
            'total_tva' => round($document->lignes->sum(fn ($l) => (float) $l->montant_tva), 2),
            'total_ttc' => round($document->lignes->sum(fn ($l) => (float) $l->montant_ttc), 2),
        ]);
    }

    /** La commande d'origine chez Books, que le client cite dans ses règlements. */
    private function bonDeCommande(array $facture): string
    {
        return trim((string) (($facture['salesorder_number'] ?? '') ?: ($facture['reference_number'] ?? '')));
    }

    /**
     * Les messages de validation de Laravel sont enfouis dans un tableau de
     * tableaux ; on les ressort, sinon le journal ne dirait que
     * « The given data was invalid ».
     */
    private function messageLisible(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return implode(' ', array_merge(...array_values($e->errors())));
        }

        return $e->getMessage();
    }

    private function chiffres(?string $valeur): string
    {
        return preg_replace('/\D+/', '', (string) $valeur) ?? '';
    }

    private function normaliserNom(?string $nom): string
    {
        return CleDeRapprochement::nom($nom);
    }
}
