<?php

namespace App\Modules\Dashboard\Services;

use App\Models\User;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Catalogue\Models\Produit;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\EtatsSyntheseService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\DocumentVenteLigne;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Support\Carbon;

/**
 * Agrège les indicateurs du tableau de bord en une passe, ADAPTÉS AU RÔLE :
 * un commercial voit son CA et ses ventes, mais pas le résultat comptable ni la
 * trésorerie ; un caissier voit un tableau minimal. Chaque bloc n'est calculé
 * (et renvoyé) que si l'utilisateur a le droit de lecture sur le domaine.
 */
class DashboardService
{
    /** Statuts d'un document de vente « comptabilisé » (hors brouillon). */
    private const STATUTS_VALIDES = ['valide', 'paye'];

    private const STATUTS_ACHAT = ['valide', 'recue_partielle', 'recue', 'paye'];

    public function __construct(
        private readonly EtatsSyntheseService $etats,
        private readonly ComptaService $compta,
    ) {}

    public function pour(User $user): array
    {
        $peutVentes = $user->hasPermission('ventes');
        $peutCompta = $user->hasPermission('compta');
        $peutAchats = $user->hasPermission('achats');
        $peutStock = $user->hasPermission('stock');

        $now = now();
        $debutMois = $now->copy()->startOfMonth();
        $finMois = $now->copy()->endOfMonth();
        $debutMoisPrec = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $finMoisPrec = $now->copy()->subMonthNoOverflow()->endOfMonth();
        $debutAnnee = $now->copy()->startOfYear();

        $kpis = [];
        $alertes = [];

        if ($peutVentes) {
            $caMois = $this->caNet($debutMois, $finMois);
            $caPrec = $this->caNet($debutMoisPrec, $finMoisPrec);
            $kpis['ca_mois'] = $this->kpi($caMois, $caPrec);
            $kpis['ca_annee'] = $this->kpi($this->caNet($debutAnnee, $finMois), null);

            $alertes['factures_echues'] = $this->facturesEchues();
            $alertes['devis_attente'] = ['count' => $this->compteDocuments('devis', ['valide'])];
        }

        if ($peutCompta) {
            $encMois = $this->encaissements($debutMois, $finMois);
            $encPrec = $this->encaissements($debutMoisPrec, $finMoisPrec);
            $kpis['encaissements_mois'] = $this->kpi($encMois, $encPrec);

            $synthese = $this->etats->calculer($finMois->format('Y-m-d'));
            $kpis['tresorerie'] = $this->kpi((float) $synthese['bilan']['tresorerie_actif'], null);
            $kpis['resultat'] = $this->kpi((float) $synthese['cpc']['resultat_net'], null);

            $creances = $this->compta->balanceAgee('clients');
            $dettes = $this->compta->balanceAgee('fournisseurs');
            $kpis['creances'] = [
                'total' => (float) $creances['totaux']['total'],
                'echu' => round((float) $creances['totaux']['total'] - (float) $creances['totaux']['t0_30'], 2),
            ];
            $kpis['dettes'] = ['total' => (float) $dettes['totaux']['total']];
        }

        if ($peutStock) {
            $alertes['stock_sous_seuil'] = ['count' => $this->produitsSousSeuil()];
        }

        return [
            'periode' => [
                'mois' => $now->format('Y-m'),
                'annee' => (int) $now->format('Y'),
            ],
            'capabilities' => [
                'ventes' => $peutVentes,
                'compta' => $peutCompta,
                'achats' => $peutAchats,
                'stock' => $peutStock,
            ],
            'kpis' => $kpis,
            'ventes_12_mois' => $peutVentes ? $this->serie12Mois($now, $peutAchats) : [],
            'repartition_ventes' => $peutVentes ? $this->repartitionVentes($debutAnnee, $finMois) : null,
            'top_clients' => $peutVentes ? $this->topClients($debutAnnee, $finMois) : [],
            'top_produits' => $peutVentes ? $this->topProduits($debutAnnee, $finMois) : [],
            'alertes' => $alertes,
        ];
    }

    /** CA net HT (factures − avoirs) sur une période, documents comptabilisés. */
    private function caNet(Carbon $debut, Carbon $fin): float
    {
        $base = fn (string $type) => DocumentVente::query()
            ->where('type', $type)
            ->whereIn('statut', self::STATUTS_VALIDES)
            ->whereBetween('date_document', [$debut->format('Y-m-d'), $fin->format('Y-m-d')])
            ->sum('total_ht');

        return round((float) $base('facture') - (float) $base('avoir'), 2);
    }

    /** Encaissements (paiements sur factures) sur une période. */
    private function encaissements(Carbon $debut, Carbon $fin): float
    {
        return round((float) Paiement::query()
            ->whereBetween('date_paiement', [$debut->format('Y-m-d'), $fin->format('Y-m-d')])
            ->whereHas('document', fn ($q) => $q->where('type', 'facture'))
            ->sum('montant'), 2);
    }

    /** Série CA (et achats si autorisé) sur les 12 derniers mois. */
    private function serie12Mois(Carbon $now, bool $peutAchats): array
    {
        $debut = $now->copy()->subMonths(11)->startOfMonth();

        $ventes = DocumentVente::query()
            ->whereIn('type', ['facture', 'avoir'])
            ->whereIn('statut', self::STATUTS_VALIDES)
            ->where('date_document', '>=', $debut->format('Y-m-d'))
            ->get(['type', 'date_document', 'total_ht']);

        $achats = $peutAchats
            ? DocumentAchat::query()
                ->where('type', 'facture')
                ->whereIn('statut', self::STATUTS_ACHAT)
                ->where('date_document', '>=', $debut->format('Y-m-d'))
                ->get(['date_document', 'total_ht'])
            : collect();

        $serie = [];
        for ($i = 0; $i < 12; $i++) {
            $mois = $debut->copy()->addMonths($i);
            $cle = $mois->format('Y-m');

            $ca = $ventes->where('type', 'facture')->filter(fn ($d) => $d->date_document->format('Y-m') === $cle)->sum('total_ht')
                - $ventes->where('type', 'avoir')->filter(fn ($d) => $d->date_document->format('Y-m') === $cle)->sum('total_ht');

            $ligne = ['mois' => $cle, 'ca' => round((float) $ca, 2)];
            if ($peutAchats) {
                $ligne['achats'] = round((float) $achats->filter(fn ($d) => $d->date_document->format('Y-m') === $cle)->sum('total_ht'), 2);
            }
            $serie[] = $ligne;
        }

        return $serie;
    }

    /** Répartition des documents de vente par type sur la période (hors brouillon). */
    private function repartitionVentes(Carbon $debut, Carbon $fin): array
    {
        return [
            'devis' => $this->compteDocuments('devis', null, $debut, $fin),
            'commandes' => $this->compteDocuments('commande', null, $debut, $fin),
            'factures' => $this->compteDocuments('facture', null, $debut, $fin),
        ];
    }

    private function compteDocuments(string $type, ?array $statuts = null, ?Carbon $debut = null, ?Carbon $fin = null): int
    {
        return DocumentVente::query()
            ->where('type', $type)
            ->when($statuts !== null, fn ($q) => $q->whereIn('statut', $statuts))
            ->when($statuts === null, fn ($q) => $q->where('statut', '!=', 'brouillon'))
            ->when($debut, fn ($q) => $q->whereBetween('date_document', [$debut->format('Y-m-d'), $fin->format('Y-m-d')]))
            ->count();
    }

    private function topClients(Carbon $debut, Carbon $fin): array
    {
        return DocumentVente::query()
            ->where('type', 'facture')
            ->whereIn('statut', self::STATUTS_VALIDES)
            ->whereBetween('date_document', [$debut->format('Y-m-d'), $fin->format('Y-m-d')])
            ->selectRaw('tiers_id, SUM(total_ht) as total')
            ->groupBy('tiers_id')
            ->orderByDesc('total')
            ->with('tiers:id,name,code')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'tiers_id' => $r->tiers_id,
                'name' => $r->tiers?->name ?? 'Client comptoir',
                'total' => round((float) $r->total, 2),
            ])
            ->all();
    }

    private function topProduits(Carbon $debut, Carbon $fin): array
    {
        return DocumentVenteLigne::query()
            ->whereNotNull('produit_id')
            ->whereHas('document', fn ($q) => $q
                ->where('type', 'facture')
                ->whereIn('statut', self::STATUTS_VALIDES)
                ->whereBetween('date_document', [$debut->format('Y-m-d'), $fin->format('Y-m-d')]))
            ->selectRaw('produit_id, SUM(montant_ht) as total, SUM(quantite) as quantite')
            ->groupBy('produit_id')
            ->orderByDesc('total')
            ->with('produit:id,name,code')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'produit_id' => $r->produit_id,
                'name' => $r->produit?->name ?? '—',
                'total' => round((float) $r->total, 2),
                'quantite' => round((float) $r->quantite, 3),
            ])
            ->all();
    }

    /** Factures échues et non soldées (statut valide, échéance passée). */
    private function facturesEchues(): array
    {
        $factures = DocumentVente::query()
            ->where('type', 'facture')
            ->where('statut', 'valide')
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', now()->format('Y-m-d'))
            ->get();

        $montant = $factures->sum(fn (DocumentVente $f) => $f->resteAPayer());

        return [
            'count' => $factures->filter(fn ($f) => $f->resteAPayer() > 0)->count(),
            'montant' => round((float) $montant, 2),
        ];
    }

    private function produitsSousSeuil(): int
    {
        return Produit::query()
            ->where('type', 'product')
            ->whereNotNull('stock_min')
            ->addSelect(['stock_quantite' => \App\Modules\Stock\Models\Stock::query()
                ->selectRaw('COALESCE(SUM(quantite), 0)')
                ->whereColumn('produit_id', 'produits.id'),
            ])
            ->get()
            ->filter(fn (Produit $p) => (float) $p->stock_quantite <= (float) $p->stock_min)
            ->count();
    }

    /** KPI avec variation vs période précédente (null = pas de comparaison). */
    private function kpi(float $valeur, ?float $precedent): array
    {
        $out = ['value' => round($valeur, 2)];

        if ($precedent !== null) {
            $out['previous'] = round($precedent, 2);
            $out['variation_pct'] = abs($precedent) > 0.005
                ? round((($valeur - $precedent) / abs($precedent)) * 100, 1)
                : null; // pas de base de comparaison
        }

        return $out;
    }
}
