<?php

namespace App\Modules\Compta\Services;

use App\Models\User;
use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Models\Exercice;
use App\Modules\Tiers\Models\Tiers;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solde d'ouverture d'UN tiers : ce qu'il devait (ou ce qu'on lui devait) le
 * jour où l'on a commencé à tenir son compte ici — le « Opening balance » de
 * Zoho Books, saisi depuis la fiche du tiers.
 *
 * JOURNAL OD, JAMAIS AN. EncoursService, la liste des tiers, la vue d'ensemble
 * et le relevé excluent le journal des à-nouveaux : la clôture y reporte le
 * solde clients en une ligne agrégée, sans tiers, qui ferait doublon. Un solde
 * d'ouverture passé en AN n'apparaîtrait donc NULLE PART — saisi, accepté, et
 * invisible. En OD, il porte le tiers et compte partout comme le reste.
 *
 * LE COMPTE DU TIERS : le collectif tel qu'il est MAPPÉ (clients, ou
 * fournisseurs pour un fournisseur), là où ComptaService écrit les factures et
 * où EncoursService lit le solde — jamais « 3421 » en dur.
 *
 * LA CONTREPARTIE : un compte TRANSITOIRE OU D'ATTENTE du CGNC — 3497
 * (débiteur, à l'actif) quand elle est au débit, 4497 (créditeur, au passif)
 * quand elle est au crédit. Pourquoi pas un compte définitif :
 *   - le report à nouveau (1161/1169) toucherait les capitaux propres au nom
 *     d'un seul client, alors que la créance d'ouverture a pour vraie
 *     contrepartie TOUT le bilan d'ouverture (banque, capital, dettes…) ;
 *   - la reprise d'ouverture du projet (OuvertureService) importe ce bilan en
 *     AN, en une écriture équilibrée, compte collectif clients compris mais
 *     SANS tiers : une contrepartie définitive ferait compter la créance deux
 *     fois. Le compte d'attente rend l'écart VISIBLE : le comptable le solde
 *     contre le collectif quand la balance d'ouverture arrive, ou contre le
 *     compte qui convient — un compte d'attente doit être nul à la clôture ;
 *   - la reprise Zoho n'a pas eu ce besoin (elle a repris les FACTURES
 *     elles-mêmes, chacune avec son écriture de vente) : rien d'existant à
 *     réutiliser.
 * Un compte par sens, et non un seul : le CGNC range les attentes débitrices à
 * l'actif (349) et les créditrices au passif (449), et les états de synthèse
 * classent par classe de compte.
 *
 * UN SEUL PAR TIERS : la marque `solde_ouverture_tiers_id` de l'écriture et
 * son index unique. Le contrôle préalable donne le message ; l'index arrête
 * le double clic que le contrôle laisserait passer.
 *
 * LA DATE : la veille du premier mouvement du tiers, proposée par
 * `dateProposee()`. Un solde d'ouverture dit ce qui était dû AVANT ce qu'on
 * tient ici : daté après, il manque à tous les relevés antérieurs ; et le
 * solde ACTUEL saisi comme ouverture compte deux fois ce que le grand livre
 * porte déjà. L'écran le dit avant l'envoi (`contexte()`).
 *
 * LA NUMÉROTATION : le numéro OD vient du compteur de l'année, donc de
 * l'ordre de SAISIE, et une ouverture est antidatée par nature. Une OD de la
 * même année datée APRÈS elle porte alors un numéro plus petit : la série
 * n'est plus chronologique. On ne renumérote PAS d'office — changer les
 * numéros d'autres écritures, peut-être déjà imprimées, est un geste que
 * `compta:renumeroter` fait confirmer —, on le SIGNALE (`odPosterieures()`).
 */
class SoldeOuvertureService
{
    public const COMPTE_CLIENT = 'client';

    public const COMPTE_FOURNISSEUR = 'fournisseur';

    public const COMPTES = [self::COMPTE_CLIENT, self::COMPTE_FOURNISSEUR];

    public const SENS = ['debit', 'credit'];

    /** CGNC 3497 — Comptes transitoires ou d'attente, débiteurs (actif). */
    public const ATTENTE_DEBITEUR = '3497';

    /** CGNC 4497 — Comptes transitoires ou d'attente, créditeurs (passif). */
    public const ATTENTE_CREDITEUR = '4497';

    public function __construct(private readonly ComptaService $compta) {}

    /**
     * Le compte que vise un solde d'ouverture quand l'appelant ne le dit pas :
     * fournisseurs pour un fournisseur PUR, clients sinon — la même règle que
     * la fiche, où un tiers à la fois client et fournisseur garde tout ce
     * qu'a un client.
     */
    public static function compteParDefaut(Tiers $tiers): string
    {
        return $tiers->is_supplier && ! $tiers->is_client && ! $tiers->is_prospect
            ? self::COMPTE_FOURNISSEUR
            : self::COMPTE_CLIENT;
    }

    /**
     * Saisit le solde d'ouverture.
     *
     * @param  array{montant: float|int|string, sens: string, date: string, compte?: string|null}  $data
     */
    public function saisir(Tiers $tiers, array $data): Ecriture
    {
        $compte = $data['compte'] ?? self::compteParDefaut($tiers);

        // Un compte que le tiers n'a pas : refusé plutôt que corrigé. Inscrire
        // une dette fournisseur sur un pur client, c'est ouvrir un compte que
        // la fiche n'affiche nulle part.
        if ($compte === self::COMPTE_FOURNISSEUR && ! $tiers->is_supplier) {
            throw ValidationException::withMessages([
                'compte' => __("Ce tiers n'est pas fournisseur : son solde d'ouverture se saisit au compte client."),
            ]);
        }
        if ($compte === self::COMPTE_CLIENT && ! $tiers->is_client && ! $tiers->is_prospect) {
            throw ValidationException::withMessages([
                'compte' => __("Ce tiers n'est pas client : son solde d'ouverture se saisit au compte fournisseur."),
            ]);
        }

        if ($this->ecriture($tiers) !== null) {
            throw $this->dejaSaisi();
        }

        $montant = round((float) $data['montant'], 2);
        $auDebit = $data['sens'] === 'debit';

        $collectif = $this->compta->compteParDefaut($compte === self::COMPTE_FOURNISSEUR ? 'fournisseurs' : 'clients');
        // Le compte d'attente prend le sens OPPOSÉ à celui du tiers.
        $attente = $this->compta->compteParCode($auDebit ? self::ATTENTE_CREDITEUR : self::ATTENTE_DEBITEUR);

        try {
            return DB::transaction(function () use ($tiers, $data, $montant, $auDebit, $collectif, $attente) {
                // La saisie manuelle du comptable (journal OD) : partie double,
                // verrou des exercices clôturés et numérotation y sont garantis,
                // et nulle part ailleurs.
                $ecriture = $this->compta->ecritureManuelle([
                    'date_ecriture' => $data['date'],
                    'libelle' => "Solde d'ouverture — {$tiers->name}",
                    'reference' => "OUV-{$tiers->code}",
                    'lignes' => [
                        [
                            'compte_id' => $collectif->id,
                            'tiers_id' => $tiers->id,
                            'libelle' => "Solde d'ouverture",
                            'debit' => $auDebit ? $montant : 0,
                            'credit' => $auDebit ? 0 : $montant,
                        ],
                        [
                            'compte_id' => $attente->id,
                            'libelle' => "Solde d'ouverture {$tiers->code} — à régulariser",
                            'debit' => $auDebit ? 0 : $montant,
                            'credit' => $auDebit ? $montant : 0,
                        ],
                    ],
                ]);

                // Hors `fillable` à dessein : seule cette saisie pose la marque.
                $ecriture->forceFill(['solde_ouverture_tiers_id' => $tiers->id])->save();

                return $ecriture;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Deux saisies simultanées : la seconde bute sur l'index unique de
            // la MARQUE, et la transaction emporte son écriture avec elle.
            // Seulement celle-là : la transaction porte aussi l'index
            // (tenant_id, numero) des écritures, et un compteur OD en retard
            // sur la série (RenumerotationService décrit le cas) annonçait
            // alors « déjà saisi » pour un tiers qui n'en avait aucun. Le
            // nom de la colonne (SQLite) et celui de l'index (PostgreSQL)
            // contiennent tous deux `solde_ouverture_tiers`.
            if (! str_contains($e->getMessage(), 'solde_ouverture_tiers')) {
                throw $e;
            }

            throw $this->dejaSaisi();
        }
    }

    /**
     * Les OD de la même année datées APRÈS celle-ci : elles portent un numéro
     * plus petit, la série OD n'est plus chronologique (voir l'en-tête).
     * Bornes en intervalle semi-ouvert, comme le relevé : SQLite peut garder
     * une heure dans la date, et la comparer en chaîne à « 2026-10-08 »
     * faussait la journée.
     */
    public function odPosterieures(Ecriture $ecriture): int
    {
        $date = CarbonImmutable::parse($ecriture->date_ecriture->format('Y-m-d'));

        return Ecriture::query()
            ->where('journal', Ecriture::JOURNAL_DIVERS)
            ->whereKeyNot($ecriture->id)
            ->where('date_ecriture', '>=', $date->addDay()->toDateString())
            ->where('date_ecriture', '<', $date->addYear()->startOfYear()->toDateString())
            ->count();
    }

    /**
     * La date qu'on propose : la veille du premier mouvement du compte s'il en
     * a, sinon le 1er janvier de l'année. Jamais dans un exercice clôturé
     * (l'écriture y serait refusée) ni dans le futur (le contrôleur aussi).
     */
    public function dateProposee(?string $premierMouvement): string
    {
        $aujourdhui = CarbonImmutable::today();
        $date = $premierMouvement !== null
            ? CarbonImmutable::parse($premierMouvement)->subDay()
            : $aujourdhui->startOfYear();

        $derniereCloture = Exercice::max('annee');
        if ($derniereCloture !== null) {
            $premierJourOuvert = CarbonImmutable::create((int) $derniereCloture + 1, 1, 1);
            if ($date->lessThan($premierJourOuvert)) {
                $date = $premierJourOuvert;
            }
        }

        return $date->greaterThan($aujourdhui) ? $aujourdhui->toDateString() : $date->toDateString();
    }

    /**
     * Les comptes que le tiers A, celui par défaut d'abord — la règle même de
     * `saisir()`, pour que l'écran ne propose rien qu'elle refuserait.
     *
     * @return list<string>
     */
    public static function comptesPossibles(Tiers $tiers): array
    {
        $comptes = array_values(array_filter([
            $tiers->is_client || $tiers->is_prospect ? self::COMPTE_CLIENT : null,
            $tiers->is_supplier ? self::COMPTE_FOURNISSEUR : null,
        ]));
        $defaut = self::compteParDefaut($tiers);

        return in_array($defaut, $comptes, true)
            ? [$defaut, ...array_values(array_diff($comptes, [$defaut]))]
            : $comptes;
    }

    /**
     * Le solde d'ouverture tel qu'UN UTILISATEUR peut le lire, ou null.
     *
     * Celui d'un compte FOURNISSEUR dit ce qu'on doit à quelqu'un à qui l'on
     * achète : la même garde que le relevé fournisseur et que les achats de
     * la synthèse — le droit achats. Le droit compta aussi, qui lit le grand
     * livre où l'écriture figure en entier. Sans l'un ou l'autre (commercial,
     * caissier), la vue d'ensemble et le relevé client faisaient sortir le
     * chiffre que le relevé fournisseur refusait.
     *
     * @return array{numero: string, date: string, montant: string, sens: string, compte: string}|null
     */
    public function existantPour(Tiers $tiers, User $utilisateur): ?array
    {
        $existant = $this->existant($tiers);

        if ($existant === null || $existant['compte'] === self::COMPTE_CLIENT) {
            return $existant;
        }

        return $utilisateur->hasPermission('achats') || $utilisateur->hasPermission('compta') ? $existant : null;
    }

    /**
     * Le solde d'ouverture déjà saisi, tel qu'on l'affiche : null s'il n'y en
     * a pas. Le sens et le compte sont relus sur la LIGNE du tiers — la seule
     * qui dise ce qu'on a saisi —, le compte par sa classe (3 = clients,
     * 4 = fournisseurs), qui survit à un remappage du collectif.
     *
     * @return array{numero: string, date: string, montant: string, sens: string, compte: string}|null
     */
    public function existant(Tiers $tiers): ?array
    {
        $ecriture = $this->ecriture($tiers);

        if ($ecriture === null) {
            return null;
        }

        /** @var EcritureLigne|null $ligne */
        $ligne = $ecriture->lignes()->where('tiers_id', $tiers->id)->with('compte:id,classe')->first();

        if ($ligne === null) {
            return null;
        }

        $auDebit = (float) $ligne->debit > 0;

        return [
            'numero' => $ecriture->numero,
            'date' => $ecriture->date_ecriture->format('Y-m-d'),
            'montant' => number_format((float) ($auDebit ? $ligne->debit : $ligne->credit), 2, '.', ''),
            'sens' => $auDebit ? 'debit' : 'credit',
            'compte' => $ligne->compte instanceof Compte && $ligne->compte->classe === 4
                ? self::COMPTE_FOURNISSEUR
                : self::COMPTE_CLIENT,
        ];
    }

    /**
     * L'écriture marquée — scope d'entreprise compris, par le modèle Ecriture.
     * Protégée et non privée : un test la neutralise pour rejouer le second
     * clic d'une course, celui que le contrôle préalable ne voit pas.
     */
    protected function ecriture(Tiers $tiers): ?Ecriture
    {
        return Ecriture::query()->where('solde_ouverture_tiers_id', $tiers->id)->first();
    }

    private function dejaSaisi(): ValidationException
    {
        return ValidationException::withMessages([
            'solde_ouverture' => __("Un solde d'ouverture a déjà été saisi pour ce tiers. Pour le corriger, passez une écriture au journal des opérations diverses."),
        ]);
    }
}
