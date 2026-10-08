<?php

namespace App\Modules\Tiers\Services;

use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\SoldeOuvertureService;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Relevé de compte d'un tiers sur une période : le solde reporté au premier
 * jour, chaque mouvement du compte avec le solde cumulé, et le solde arrêté au
 * dernier jour — le « Statement » de Zoho Books, tiré du GRAND LIVRE.
 *
 * MÊME PÉRIMÈTRE QU'EncoursService, et c'est voulu :
 *   - comptes : la liste d'EncoursService elle-même (collectif clients MAPPÉ
 *     + 3425 effets à recevoir), lue chez lui et non recopiée ;
 *   - lignes portant ce tiers, journal des à-nouveaux EXCLU, de la même façon
 *     et pour la même raison : la clôture y reporte le solde clients en une
 *     ligne agrégée sans tiers, et les lignes des exercices clôturés restent
 *     au grand livre — compter les deux doublerait le passé. Un relevé tiré
 *     « depuis l'origine » est ainsi continu d'un exercice à l'autre ;
 *   - lettrées ou non : un relevé montre la facture ET son règlement. La
 *     somme est la même que celle des seules lignes non lettrées, puisqu'un
 *     groupe lettré est ÉQUILIBRÉ ET D'UN SEUL TIERS (LettrageService refuse
 *     le reste). Équilibré par compte ne suffisait pas : un groupe qui mêle
 *     deux tiers, ou un tiers et une ligne SANS tiers (l'à-nouveau global de
 *     la clôture, la balance d'ouverture d'OuvertureService), s'annule sur le
 *     compte mais pas chez le client — la liste disait 0, le relevé 5 000.
 * D'où l'égalité éprouvée par les tests : solde arrêté à aujourd'hui = solde
 * signé d'EncoursService. Elle tient tant qu'aucune ligne n'est datée
 * d'avance (EncoursService n'a pas de date, le relevé s'arrête à « au ») et
 * qu'aucun groupe lettré AVANT cette règle ne mêle deux tiers — ce que
 * `compta:verifier-lettrage` recense, en lecture seule.
 *
 * Pour un FOURNISSEUR, le même calcul sur son compte : collectif fournisseurs
 * mappé + 4415 effets à payer (le pendant de 3425, EffetService), et le solde
 * se lit dans l'autre sens — positif quand c'est NOUS qui devons.
 *
 * Les montants sont cumulés en CENTIMES entiers : des milliers d'additions de
 * flottants finissent par décaler le dernier chiffre du solde.
 */
class ReleveService
{
    public const COMPTE_CLIENT = SoldeOuvertureService::COMPTE_CLIENT;

    public const COMPTE_FOURNISSEUR = SoldeOuvertureService::COMPTE_FOURNISSEUR;

    /**
     * Au-delà, les lignes ne sont pas servies — soldes et totaux, si. Le
     * « Client comptoir » de la caisse reçoit une facture et un règlement par
     * ticket, des dizaines de milliers par an : les charger en mémoire faisait
     * tomber PHP (VueEnsembleService l'a appris), et un PDF de mille pages
     * n'est lu par personne. Deux mille lignes, c'est une quarantaine de pages.
     */
    public const LIGNES_MAX = 2000;

    /** Effets à payer (CGNC 4415), le pendant fournisseur de 3425. */
    private const CODE_EFFETS_A_PAYER = '4415';

    private const DEVISE = 'MAD';

    public function __construct(
        private readonly EncoursService $encours,
        private readonly ComptaService $compta,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pour(Tiers $tiers, string $compte, CarbonImmutable $du, CarbonImmutable $au): array
    {
        $fournisseur = $compte === self::COMPTE_FOURNISSEUR;
        $comptes = $fournisseur ? $this->comptesFournisseur() : $this->encours->comptesCreance();
        // Client : ce qu'il doit (débit − crédit). Fournisseur : ce qu'on lui
        // doit (crédit − débit). Positif = le sens « normal » du compte.
        $signe = $fournisseur ? -1 : 1;

        // Bornes en intervalle semi-ouvert [du, au + 1 jour[ : la date d'une
        // écriture se lit « 2026-10-08 » sous PostgreSQL mais peut porter une
        // heure sous SQLite, où « 2026-10-08 00:00:00 » <= « 2026-10-08 »
        // est FAUX en comparaison de chaînes — la dernière journée sautait.
        $debut = $du->toDateString();
        $finExclue = $au->addDay()->toDateString();

        $avant = $this->totaux((clone $this->base($tiers, $comptes))->where('ecritures.date_ecriture', '<', $debut));
        $soldeInitial = $signe * ($avant['debit'] - $avant['credit']);

        $periode = (clone $this->base($tiers, $comptes))
            ->where('ecritures.date_ecriture', '>=', $debut)
            ->where('ecritures.date_ecriture', '<', $finExclue);

        $totaux = $this->totaux(clone $periode);
        $nombre = (clone $periode)->toBase()->count();
        $soldeFinal = $soldeInitial + $signe * ($totaux['debit'] - $totaux['credit']);

        $trop = $nombre > static::LIGNES_MAX;

        return [
            'compte' => $compte,
            'comptes' => Compte::whereIn('id', $comptes)->orderBy('code')->pluck('code')->all(),
            'devise' => self::DEVISE,
            'du' => $debut,
            'au' => $au->toDateString(),
            // Le solde reporté est celui du SOIR de la veille : les lignes du
            // jour « du » sont dans la période. L'écrire « solde au {du} » à
            // côté de « solde au {au} » donnait, pour du = au, deux soldes
            // différents à la même date — sur un document envoyé au client.
            'report_au' => $du->subDay()->toDateString(),
            'solde_initial' => $this->montant($soldeInitial),
            'total_debit' => $this->montant($totaux['debit']),
            'total_credit' => $this->montant($totaux['credit']),
            'solde_final' => $this->montant($soldeFinal),
            'nb_lignes' => $nombre,
            'trop_de_lignes' => $trop,
            'lignes' => $trop ? [] : $this->lignes($periode, $soldeInitial, $signe),
        ];
    }

    /**
     * Ce que le compte du tiers porte DÉJÀ au grand livre — le périmètre du
     * relevé, à-nouveaux exclus : combien de lignes, et la date de la
     * première. Le solde d'ouverture s'en sert pour proposer sa date (la
     * veille de ce premier mouvement) et pour prévenir qu'il s'AJOUTE à eux.
     *
     * @return array{nombre: int, premier: ?string}
     */
    public function mouvements(Tiers $tiers, string $compte): array
    {
        $comptes = $compte === self::COMPTE_FOURNISSEUR ? $this->comptesFournisseur() : $this->encours->comptesCreance();

        $ligne = $this->base($tiers, $comptes)->toBase()
            ->selectRaw('COUNT(*) AS nombre, MIN(ecritures.date_ecriture) AS premier')
            ->first();

        return [
            'nombre' => (int) ($ligne->nombre ?? 0),
            // Dix caractères : une date nue, que la base rende ou non une heure.
            'premier' => $ligne?->premier !== null ? substr((string) $ligne->premier, 0, 10) : null,
        ];
    }

    /**
     * Le compte que montre le relevé quand on ne le précise pas : la même
     * règle que le solde d'ouverture (fournisseurs pour un fournisseur pur).
     */
    public static function compteParDefaut(Tiers $tiers): string
    {
        return SoldeOuvertureService::compteParDefaut($tiers);
    }

    /**
     * Les lignes du tiers sur ses comptes, hors à-nouveaux.
     *
     * Partie d'Ecriture et JOINTE aux lignes : `ecriture_lignes` n'a pas
     * d'entreprise, c'est le scope du modèle Ecriture (`ecritures.tenant_id`)
     * qui sépare nos lignes de celles d'une autre entreprise — et il reste
     * appliqué par `toBase()`, qui ne retire que l'habillage en modèles.
     *
     * @param  array<int, int>  $comptes
     */
    private function base(Tiers $tiers, array $comptes): Builder
    {
        return Ecriture::query()
            ->join('ecriture_lignes', 'ecriture_lignes.ecriture_id', '=', 'ecritures.id')
            ->where('ecriture_lignes.tiers_id', $tiers->id)
            ->whereIn('ecriture_lignes.compte_id', $comptes === [] ? [0] : $comptes)
            ->where('ecritures.journal', '!=', Ecriture::JOURNAL_A_NOUVEAUX);
    }

    /** @return array{debit: int, credit: int} en centimes */
    private function totaux(Builder $requete): array
    {
        $ligne = $requete->toBase()
            ->selectRaw('COALESCE(SUM(ecriture_lignes.debit), 0) AS debit, COALESCE(SUM(ecriture_lignes.credit), 0) AS credit')
            ->first();

        return [
            'debit' => (int) round((float) ($ligne->debit ?? 0) * 100),
            'credit' => (int) round((float) ($ligne->credit ?? 0) * 100),
        ];
    }

    /**
     * Les mouvements de la période dans l'ordre du grand livre — date, puis
     * écriture (une facture précède le règlement saisi après elle le même
     * jour), puis ligne —, chacun avec le solde APRÈS lui.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lignes(Builder $periode, int $soldeInitial, int $signe): array
    {
        $lignes = $periode->toBase()
            ->select([
                'ecritures.id AS ecriture_id', 'ecritures.numero', 'ecritures.journal', 'ecritures.date_ecriture',
                'ecritures.libelle AS libelle_ecriture', 'ecritures.reference', 'ecritures.document_vente_id',
                'ecriture_lignes.id AS ligne_id', 'ecriture_lignes.libelle AS libelle_ligne',
                'ecriture_lignes.debit', 'ecriture_lignes.credit',
            ])
            ->orderBy('ecritures.date_ecriture')
            ->orderBy('ecritures.id')
            ->orderBy('ecriture_lignes.id')
            ->get();

        // Les pièces de vente en UNE requête : leur code et leur type, pour le
        // lien. Corbeille comprise — l'écriture survit à la pièce, son code
        // doit rester lisible sur le relevé.
        $documents = DocumentVente::withTrashed()
            ->whereIn('id', $lignes->pluck('document_vente_id')->filter()->unique()->values())
            ->get(['id', 'code', 'type'])
            ->keyBy('id');

        $solde = $soldeInitial;
        $resultat = [];

        foreach ($lignes as $ligne) {
            $debit = (int) round((float) $ligne->debit * 100);
            $credit = (int) round((float) $ligne->credit * 100);
            $solde += $signe * ($debit - $credit);

            $document = $ligne->document_vente_id !== null ? $documents->get($ligne->document_vente_id) : null;

            $resultat[] = [
                // Les dix premiers caractères : « 2026-10-08 » partout, que la
                // base rende une date nue ou une date et une heure.
                'date' => substr((string) $ligne->date_ecriture, 0, 10),
                'numero' => $ligne->numero,
                'journal' => $ligne->journal,
                // La pièce que reconnaît le tiers : le code de la pièce de
                // vente, sinon la référence de l'écriture (facture fournisseur,
                // solde d'ouverture), à défaut son numéro au journal.
                'piece' => $document?->code ?? $ligne->reference ?? $ligne->numero,
                'document' => $document !== null ? ['id' => $document->id, 'type' => $document->type] : null,
                'libelle' => filled($ligne->libelle_ligne) ? $ligne->libelle_ligne : $ligne->libelle_ecriture,
                'debit' => $this->montant($debit),
                'credit' => $this->montant($credit),
                'solde' => $this->montant($solde),
            ];
        }

        return $resultat;
    }

    /** @return array<int, int> */
    private function comptesFournisseur(): array
    {
        $ids = [$this->compta->compteParDefaut('fournisseurs')->id];

        $effets = Compte::where('code', self::CODE_EFFETS_A_PAYER)->first();
        if ($effets !== null) {
            $ids[] = $effets->id;
        }

        return $ids;
    }

    /** Centimes → « 1234.50 ». `+ 0` : un zéro négatif s'écrirait « -0.00 ». */
    private function montant(int $centimes): string
    {
        return number_format($centimes / 100 + 0.0, 2, '.', '');
    }
}
