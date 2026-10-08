<?php

namespace App\Modules\Tiers\Services;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Compta\Services\SoldeOuvertureService;
use App\Modules\Portail\Models\AcheteurTiers;
use App\Modules\Tiers\Models\Contact;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use Carbon\CarbonImmutable;

/**
 * La vue d'ensemble d'un tiers, façon Zoho Books, en UN SEUL appel : le
 * contact principal et l'état de ses accès au portail à gauche ; les
 * conditions de paiement, la limite de crédit, le compte client et les revenus
 * par mois à droite.
 *
 * Même modèle que le tableau de bord : la route n'a qu'UNE garde de domaine
 * (`tiers`, posée par le module), et chaque bloc qui relève d'un autre domaine
 * est calculé — et renvoyé — seulement si l'utilisateur y a droit. Un bloc
 * interdit est OMIS, jamais mis à zéro : « 0,00 » affirmerait un chiffre
 * qu'on n'a simplement pas montré.
 *
 * LA LIGNE DE PARTAGE : CE QUE LE CLIENT DOIT, CE QU'IL RAPPORTE. Ce qu'il
 * doit et ce qu'il peut encore prendre à crédit (`compte`, `credit`) va à
 * quiconque lit les tiers : la caisse le montre au caissier pour vendre à
 * crédit (/encours), la liste des tiers affiche le même solde à chaque
 * lecteur. Le retirer de la seule fiche ne protégeait rien et rendait l'écran
 * incohérent — la jauge à la caisse, pas sur la fiche ; le solde dans la
 * liste, pas à côté. Ce qu'il RAPPORTE (`revenus`) relève des ventes : omis
 * sans ce droit, comme le chiffre d'affaires de la synthèse.
 *
 * Les chiffres ne sont servis qu'à un tiers CLIENT. Un fournisseur pur n'a
 * pas de compte client : « créances 0,00 » et un graphique vide y laisseraient
 * croire qu'on a vérifié.
 */
class VueEnsembleService
{
    /** Périodes du graphique des revenus, telles qu'elles s'écrivent dans l'URL. */
    public const PERIODES = ['6m', '12m', 'annee'];

    public const PERIODE_DEFAUT = '6m';

    /**
     * Le compte client est tenu en dirhams : les pièces n'ont pas de devise à
     * elles (la facture électronique écrit MAD en dur). La colonne existe pour
     * ressembler au relevé de Zoho, pas pour prétendre au multidevise.
     */
    private const DEVISE = 'MAD';

    /** Une pièce compte dès qu'elle est émise : `paye` n'est qu'une `valide` soldée. */
    private const STATUTS_EMIS = [DocumentVente::STATUT_VALIDE, DocumentVente::STATUT_PAYE];

    public function __construct(
        private readonly EncoursService $encours,
        private readonly TenantContext $contexte,
        private readonly SoldeOuvertureService $ouverture,
    ) {}

    /**
     * @return array{data: array<string, mixed>, capacites: array{ventes: bool, portail: bool}}
     */
    public function pour(Tiers $tiers, User $user, string $periode, ?CarbonImmutable $maintenant = null): array
    {
        $maintenant ??= CarbonImmutable::now();

        // Les revenus sont du domaine des VENTES. Les accès au portail se
        // gèrent, eux, sous `tiers` (Adhésions portail, même garde côté API) :
        // la route l'exige déjà, mais la capacité est dite ici pour que
        // l'écran n'ait pas à le deviner, et qu'un futur domaine « portail »
        // n'ait qu'une ligne à changer.
        $peutVentes = $user->hasPermission('ventes');
        $peutPortail = $user->hasPermission('tiers');

        $solde = $this->encours->soldesSignes([$tiers->id])[$tiers->id] ?? null;
        $client = $this->estClient($tiers, $solde);

        $data = [
            'client' => $client,
            'contact_principal' => $this->contactPrincipal($tiers),
            // Rendu tel quel, et null N'EST PAS 0. Zéro est une condition
            // convenue : payable à réception. Null veut dire « non renseigné »
            // — c'est le cas de tous les tiers repris de Zoho, dont les
            // factures portent pourtant des échéances à 30 ou 60 jours. La
            // caisse retombe sur le jour même pour dater un ticket (PosService),
            // ce qui ne fait pas de ce repli une condition commerciale.
            'delai_paiement_jours' => $tiers->delai_paiement_jours !== null ? (int) $tiers->delai_paiement_jours : null,
            // Le solde d'ouverture déjà saisi, ou null : la carte du compte
            // propose de le saisir quand il manque. Celui d'un compte CLIENT se
            // lit avec les tiers, comme le reste du compte ; celui d'un compte
            // FOURNISSEUR exige le droit achats (ou compta), comme le relevé
            // fournisseur — sans quoi le commercial lisait ici le chiffre que
            // ce relevé lui refuse. La règle est dans le service.
            'solde_ouverture' => $this->ouverture->existantPour($tiers, $user),
        ];

        if ($peutPortail) {
            $data['portail'] = $this->portail($tiers);
        }

        if ($client) {
            $data['credit'] = $this->credit($tiers, $solde);
            $data['compte'] = [
                'devise' => self::DEVISE,
                // Le même solde signé que la liste des tiers : ce que la ligne
                // affiche à gauche, la fiche le répète à droite, au centime.
                'creances' => $this->montant(max(0.0, $solde ?? 0.0)),
                'credits' => $this->montant(max(0.0, -($solde ?? 0.0))),
            ];
        }

        if ($client && $peutVentes) {
            $data['revenus'] = $this->revenus($tiers, $periode, $maintenant);
        }

        return [
            'data' => $data,
            'capacites' => ['ventes' => $peutVentes, 'portail' => $peutPortail],
        ];
    }

    /**
     * Client = coché client OU prospect, OU qui a une ligne ouverte au compte
     * clients, OU à qui l'on a émis une facture ou un avoir.
     *
     * Le prospect compte par sa PROPRE case : le formulaire laisse cocher
     * « prospect » sans « client », et l'écran, qui suppose avant la réponse
     * qu'un prospect est un client à venir, posait la colonne chiffrée puis la
     * retirait à l'arrivée des données.
     *
     * La case seule ne suffit pas : rien n'interdit de facturer un tiers
     * enregistré comme fournisseur, ni de décocher « client » sur quelqu'un
     * qui doit encore — la liste affiche alors son solde, la fiche doit le
     * montrer aussi. Le solde seul non plus : une facture réglée et lettrée
     * sort du solde non lettré, et le client qui ne doit plus rien resterait
     * sans graphique de ses achats.
     */
    private function estClient(Tiers $tiers, ?float $solde): bool
    {
        return $tiers->is_client
            || $tiers->is_prospect
            || $solde !== null
            || DocumentVente::query()
                ->where('tiers_id', $tiers->id)
                ->whereIn('type', [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR])
                ->whereIn('statut', self::STATUTS_EMIS)
                ->exists();
    }

    /** @return array{id: int, nom: string, fonction: ?string, email: ?string, phone: ?string, mobile: ?string}|null */
    private function contactPrincipal(Tiers $tiers): ?array
    {
        /** @var Contact|null $contact */
        $contact = $tiers->contactPrincipal()->first(['id', 'nom', 'fonction', 'email', 'phone', 'mobile']);

        return $contact === null ? null : [
            'id' => $contact->id,
            'nom' => $contact->nom,
            'fonction' => $contact->fonction,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'mobile' => $contact->mobile,
        ];
    }

    /**
     * Les comptes acheteurs rattachés à ce tiers, quel que soit leur état.
     *
     * `acheteur_tiers` est une table TRAVERSANTE, sans scope d'entreprise : un
     * même acheteur y a une ligne par grossiste. Le filtre d'entreprise est
     * donc posé À LA MAIN, depuis le contexte de la requête, en plus du tiers
     * — c'est la seule chose qui sépare nos acheteurs de ceux d'un concurrent.
     * Sans contexte (ce qui ne devrait pas arriver derrière `tenant`), on ne
     * rend RIEN plutôt que tout.
     *
     * Plusieurs lignes possibles : l'unicité porte sur (acheteur, entreprise),
     * pas sur le tiers — un client peut avoir un compte par magasin.
     *
     * @return array<int, array<string, mixed>>
     */
    private function portail(Tiers $tiers): array
    {
        $entreprise = $this->contexte->id();

        if ($entreprise === null) {
            return [];
        }

        return AcheteurTiers::query()
            ->where('tenant_id', $entreprise)
            ->where('tiers_id', $tiers->id)
            ->with('acheteur:id,name,email')
            // `id` départage deux demandes de la même seconde, comme l'écran des adhésions.
            ->latest('demande_at')
            ->latest('id')
            ->get()
            ->map(fn (AcheteurTiers $r) => [
                'statut' => $r->statut,
                'acheteur' => ['name' => $r->acheteur?->name, 'email' => $r->acheteur?->email],
                'demande_at' => $r->demande_at?->toDateString(),
                'approuve_at' => $r->approuve_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Le plafond, consommé et restant — le contrôle de la caisse et du
     * portail, mot pour mot : c'est EncoursService qui décide, avec l'encours
     * déjà lu (plancher à zéro) pour ne pas relire le grand livre. Aucun
     * plafond : `null`, l'écran dit « Non limité ».
     *
     * @return array{plafond: string, encours: string, disponible: string}|null
     */
    private function credit(Tiers $tiers, ?float $solde): ?array
    {
        if ($tiers->plafond_credit === null) {
            return null;
        }

        $controle = $this->encours->verifier($tiers, 0, max(0.0, $solde ?? 0.0));

        return [
            'plafond' => $this->montant($controle['plafond']),
            'encours' => $this->montant($controle['encours']),
            'disponible' => $this->montant($controle['disponible']),
        ];
    }

    /**
     * Revenus du tiers par mois : MÊME définition que la série du tableau de
     * bord (DashboardService::serie12Mois) — factures moins avoirs émis, HORS
     * TAXES, au mois de la date de la pièce — mais pour ce seul tiers.
     *
     * Hors taxes et avoirs déduits : c'est ce qu'on a réellement vendu. Le
     * « chiffre d'affaires » TTC de la synthèse compte, lui, la TVA collectée
     * pour l'État et les factures annulées par avoir.
     *
     * AGRÉGÉ PAR JOUR EN SQL, REGROUPÉ PAR MOIS EN PHP. Pas une ligne par
     * pièce : « Client comptoir » reçoit une facture par ticket de caisse,
     * des dizaines de milliers par an, et autant d'objets Eloquent faisaient
     * sauter la limite mémoire de PHP — la vue d'ensemble entière tombait,
     * contact et portail compris. Pas de mois en SQL non plus : l'extraire
     * s'écrit `strftime` sous SQLite et `to_char` sous PostgreSQL. Un
     * GROUP BY sur la date brute se lit pareil partout et rend au plus deux
     * lignes par jour de la période. Les montants sont cumulés en CENTIMES
     * entiers : additionner des flottants des centaines de fois finit par
     * décaler le dernier chiffre.
     *
     * `nb_pieces` : factures et avoirs de la période. Un total nul ne dit pas
     * s'il n'y a rien eu ou si des avoirs ont tout annulé — l'écran ne doit
     * pas écrire « aucune vente » sous un mois où l'on a facturé.
     *
     * @return array{periode: string, du: string, au: string, serie: array<int, array{mois: string, ca: string}>, total: string, nb_pieces: int}
     */
    private function revenus(Tiers $tiers, string $periode, CarbonImmutable $maintenant): array
    {
        [$du, $au] = self::bornes($periode, $maintenant);

        // Un mois sans vente vaut 0 et garde sa barre : un trou dans l'axe
        // ferait croire à une donnée manquante.
        $centimes = [];
        for ($mois = $du; $mois->lessThanOrEqualTo($au); $mois = $mois->addMonthNoOverflow()) {
            $centimes[$mois->format('Y-m')] = 0;
        }

        // `toBase()` après les filtres : les scopes globaux (entreprise,
        // corbeille) sont appliqués, seul l'habillage en modèles saute.
        $jours = DocumentVente::query()
            ->where('tiers_id', $tiers->id)
            ->whereIn('type', [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR])
            ->whereIn('statut', self::STATUTS_EMIS)
            ->whereBetween('date_document', [$du->toDateString(), $au->toDateString()])
            ->toBase()
            ->selectRaw('type, date_document, SUM(total_ht) AS total, COUNT(*) AS nombre')
            ->groupBy('type', 'date_document')
            ->get();

        $pieces = 0;
        foreach ($jours as $jour) {
            // « 2026-10-03 » sous PostgreSQL, la chaîne stockée sous SQLite :
            // les sept premiers caractères sont le mois dans les deux cas.
            $cle = substr((string) $jour->date_document, 0, 7);
            $montant = (int) round((float) $jour->total * 100);

            // Les bornes collent aux mois : un jour lu est toujours dans la série.
            $centimes[$cle] += $jour->type === DocumentVente::TYPE_AVOIR ? -$montant : $montant;
            $pieces += (int) $jour->nombre;
        }

        $serie = [];
        foreach ($centimes as $mois => $valeur) {
            $serie[] = ['mois' => $mois, 'ca' => $this->montant($valeur / 100)];
        }

        return [
            'periode' => $periode,
            'du' => $du->toDateString(),
            'au' => $au->toDateString(),
            'serie' => $serie,
            'total' => $this->montant(array_sum($centimes) / 100),
            'nb_pieces' => $pieces,
        ];
    }

    /**
     * Les bornes d'une période, en mois ENTIERS : du premier jour du premier
     * mois au dernier jour du mois courant. « 6 derniers mois » en octobre,
     * c'est mai à octobre — le mois en cours compris, comme chez Zoho et comme
     * le tableau de bord.
     *
     * « annee » = l'année CIVILE en cours, de janvier à décembre : une pièce
     * datée d'avance en novembre y appartient déjà, et l'exercice comptable de
     * l'entreprise (que rien ne stocke à part) n'y change rien.
     *
     * Publique : le « facturé sur 12 mois » de la synthèse se borne ICI. Un
     * an glissant au jour près, sans borne haute, comptait sur la même fiche
     * des factures que le graphique « 12 derniers mois » ne montrait pas.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bornes(string $periode, CarbonImmutable $maintenant): array
    {
        return match ($periode) {
            'annee' => [$maintenant->startOfYear(), $maintenant->endOfYear()->startOfDay()],
            '12m' => [$maintenant->startOfMonth()->subMonthsNoOverflow(11), $maintenant->endOfMonth()->startOfDay()],
            default => [$maintenant->startOfMonth()->subMonthsNoOverflow(5), $maintenant->endOfMonth()->startOfDay()],
        };
    }

    private function montant(float|int|string|null $valeur): string
    {
        // `+ 0.0` : un -0.0 sorti d'une soustraction s'écrirait « -0.00 ».
        return number_format(round((float) $valeur, 2) + 0.0, 2, '.', '');
    }
}
