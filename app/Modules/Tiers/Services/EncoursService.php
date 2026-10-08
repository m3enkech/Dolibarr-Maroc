<?php

namespace App\Modules\Tiers\Services;

use App\Modules\Compta\Models\Compte;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Tiers\Models\Tiers;

/**
 * Encours client : ce qu'un client doit réellement à l'entreprise.
 *
 * DÉFINITION RETENUE — solde non lettré du client sur les comptes de créance
 * (3421 Clients + 3425 Effets à recevoir), hors journal des à-nouveaux.
 *
 * Pourquoi le grand livre plutôt que la somme des « restes à payer » des
 * factures : quand une traite est tirée, la créance quitte 3421 pour 3425 et la
 * facture est lettrée — mais elle garde un reste à payer positif à vie, puisque
 * l'encaissement de l'effet ne crée pas de ligne de paiement. Compter les
 * documents produirait donc un encours fantôme permanent. Le grand livre, lui,
 * retombe seul à zéro quand l'effet est encaissé.
 *
 * Le journal AN est exclu : la clôture reporte le solde clients en une ligne
 * agrégée sans tiers_id, qui ne doit pas être comptée deux fois.
 */
class EncoursService
{
    /**
     * Effets à recevoir (CGNC 3425). Le compte clients, lui, n'est PAS codé en
     * dur : il est lu dans le mapping comptable du tenant, que l'entreprise
     * peut remapper. S'en tenir à « 3421 » rendrait le plafond silencieusement
     * inopérant pour toute entreprise ayant changé son compte collectif.
     */
    private const CODE_EFFETS_A_RECEVOIR = '3425';

    public function __construct(private ComptaService $compta) {}

    /**
     * Comptes portant la créance client : le compte collectif tel qu'il est
     * réellement mappé (c'est là que ComptaService écrit), plus les effets à
     * recevoir quand ils existent.
     *
     * Publique pour le relevé du client (ReleveService) : son solde de
     * clôture doit être CE solde, et le seul moyen sûr d'avoir le même
     * périmètre est de lire la même liste de comptes.
     *
     * @return array<int, int>
     */
    public function comptesCreance(): array
    {
        $ids = [$this->compta->compteParDefaut('clients')->id];

        $effets = Compte::where('code', self::CODE_EFFETS_A_RECEVOIR)->first();
        if ($effets !== null) {
            $ids[] = $effets->id;
        }

        return $ids;
    }

    /** Encours d'un client, en dirhams. */
    public function pour(Tiers $tiers): float
    {
        return $this->parTiers([$tiers->id])[$tiers->id] ?? 0.0;
    }

    /**
     * Encours de plusieurs clients en une seule requête.
     *
     * Plancher à zéro : c'est la question du PLAFOND (caisse, portail,
     * /encours) — un client créditeur n'a rien consommé de son crédit, il ne
     * s'en voit pas accorder davantage pour autant.
     *
     * @param  array<int, int>  $tiersIds
     * @return array<int, float> encours indexé par tiers_id
     */
    public function parTiers(array $tiersIds): array
    {
        return array_map(fn (float $solde) => max(0.0, $solde), $this->soldesSignes($tiersIds));
    }

    /**
     * Solde SIGNÉ de plusieurs clients, en une seule requête groupée : positif
     * quand le client nous doit, NÉGATIF quand c'est nous qui lui devons (avoir
     * non remboursé, trop-perçu).
     *
     * Même périmètre que parTiers — c'est la même requête, seul le plancher
     * diffère. La liste des tiers en a besoin parce qu'un « 0,00 » sur un
     * client créditeur de 1 200 DH ment par omission : on l'appellerait pour
     * relancer une dette qu'il n'a pas, et on oublierait de le rembourser.
     *
     * Un tiers sans aucune ligne ouverte est ABSENT du résultat, pas à zéro :
     * l'appelant décide si l'absence vaut « 0,00 » ou « sans objet ».
     *
     * @param  array<int, int>  $tiersIds
     * @return array<int, float> solde indexé par tiers_id
     */
    public function soldesSignes(array $tiersIds): array
    {
        if ($tiersIds === []) {
            return [];
        }

        $comptes = $this->comptesCreance();

        if ($comptes === []) {
            return [];
        }

        // whereHas('ecriture') applique le scope tenant : ecriture_lignes n'est
        // pas scopée elle-même, l'omettre agrégerait les clients d'autres
        // entreprises.
        $lignes = EcritureLigne::query()
            ->whereIn('tiers_id', $tiersIds)
            ->whereIn('compte_id', $comptes)
            ->whereNull('lettrage')
            ->whereHas('ecriture', fn ($q) => $q->where('journal', '!=', Ecriture::JOURNAL_A_NOUVEAUX))
            ->selectRaw('tiers_id, SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->groupBy('tiers_id')
            ->get();

        $soldes = [];
        foreach ($lignes as $ligne) {
            $solde = round((float) $ligne->total_debit - (float) $ligne->total_credit, 2);
            // Un compte soldé peut ressortir en -0.0 de l'arrondi, que
            // number_format écrit « -0.00 » : un client à jour affiché en négatif.
            $soldes[(int) $ligne->tiers_id] = abs($solde) < 0.005 ? 0.0 : $solde;
        }

        return $soldes;
    }

    /**
     * Le solde tel que la LISTE des tiers l'affiche — et l'export CSV, qui
     * doit dire la même chose : signé, et `null` quand il est sans objet.
     *
     * TOUS les tiers sont interrogés, pas seulement ceux cochés « client » :
     * rien n'interdit de facturer un tiers enregistré comme fournisseur (la
     * vente ne contrôle que son existence), ni de décocher « client » sur
     * quelqu'un qui doit encore de l'argent. Filtrer sur la case cachait alors
     * une dette que /encours, lui, affichait.
     *
     * Le drapeau ne sert qu'à lire l'ABSENCE de ligne ouverte : un client
     * confirmé sans écriture est réellement à zéro ; un fournisseur pur ou un
     * prospect sans écriture n'ont pas de compte client — « 0,00 » y
     * laisserait croire qu'on a vérifié, d'où `null`.
     *
     * @param  iterable<Tiers>  $tiers
     * @return array<int, ?float> solde indexé par id de tiers
     */
    public function soldesAffiches(iterable $tiers): array
    {
        $liste = collect($tiers);
        $soldes = $this->soldesSignes($liste->pluck('id')->all());

        return $liste->mapWithKeys(fn (Tiers $t) => [
            $t->id => $soldes[$t->id] ?? ($t->is_client && ! $t->is_prospect ? 0.0 : null),
        ])->all();
    }

    /**
     * Contrôle du plafond avant d'accorder un crédit.
     *
     * `$encoursConnu` : l'encours que l'appelant vient de lire par parTiers ou
     * soldesSignes (plancher à zéro appliqué), pour ne pas relire le grand
     * livre — la vue d'ensemble d'un tiers a déjà son solde en main. La règle
     * du plafond, elle, reste ici, unique pour la caisse, le portail et la fiche.
     *
     * @return array{autorise: bool, encours: float, plafond: ?float, disponible: ?float, depassement: float}
     */
    public function verifier(Tiers $tiers, float $montantCredit, ?float $encoursConnu = null): array
    {
        $encours = $encoursConnu ?? $this->pour($tiers);
        $plafond = $tiers->plafond_credit !== null ? (float) $tiers->plafond_credit : null;

        if ($plafond === null) {
            return [
                'autorise' => true, 'encours' => $encours, 'plafond' => null,
                'disponible' => null, 'depassement' => 0.0,
            ];
        }

        $apres = round($encours + $montantCredit, 2);
        $depassement = round(max(0, $apres - $plafond), 2);

        return [
            'autorise' => $depassement <= 0.009,
            'encours' => $encours,
            'plafond' => $plafond,
            'disponible' => round(max(0, $plafond - $encours), 2),
            'depassement' => $depassement,
        ];
    }
}
