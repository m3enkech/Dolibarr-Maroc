<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Opportunite;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;

/**
 * Statistiques commerciales du CRM : entonnoir du pipeline, taux de
 * transformation, performance par vendeur et origine des prospects.
 *
 * Le CA réel par vendeur n'est pas calculable : `documents_vente` ne porte pas
 * de vendeur. Les montants « par vendeur » sont donc ceux des opportunités
 * (montant_estime), qui, elles, portent bien un user_id.
 */
class CrmStatsService
{
    /** @return array<string, mixed> */
    public function stats(?string $depuis = null): array
    {
        $base = Opportunite::query()->when($depuis !== null, fn ($q) => $q->whereDate('created_at', '>=', $depuis));

        $opportunites = (clone $base)->get();

        $gagnees = $opportunites->where('statut', Opportunite::STATUT_GAGNEE);
        $perdues = $opportunites->where('statut', Opportunite::STATUT_PERDUE);
        $ouvertes = $opportunites->where('statut', Opportunite::STATUT_OUVERTE);
        $closes = $gagnees->count() + $perdues->count();

        return [
            'synthese' => [
                'total' => $opportunites->count(),
                'ouvertes' => $ouvertes->count(),
                'gagnees' => $gagnees->count(),
                'perdues' => $perdues->count(),
                // Taux de conversion = gagnées / (gagnées + perdues) : les affaires
                // encore ouvertes ne sont pas comptées, elles ne sont pas tranchées.
                'taux_conversion' => $closes > 0 ? round($gagnees->count() * 100 / $closes, 1) : 0,
                'montant_gagne' => $this->fmt($gagnees->sum('montant_estime')),
                'montant_perdu' => $this->fmt($perdues->sum('montant_estime')),
                'pipeline_ouvert' => $this->fmt($ouvertes->sum('montant_estime')),
                'forecast_pondere' => $this->fmt(
                    $ouvertes->sum(fn ($o) => (float) $o->montant_estime * $o->probabilite / 100),
                ),
                'panier_moyen_gagne' => $this->fmt(
                    $gagnees->count() > 0 ? $gagnees->sum('montant_estime') / $gagnees->count() : 0,
                ),
                'duree_moyenne_jours' => $this->dureeMoyenne($gagnees->merge($perdues)),
            ],
            'par_etape' => collect(Opportunite::ETAPES)->map(fn (string $etape) => [
                'etape' => $etape,
                'nombre' => $ouvertes->where('etape', $etape)->count(),
                'montant' => $this->fmt($ouvertes->where('etape', $etape)->sum('montant_estime')),
            ])->values(),
            'par_vendeur' => $this->parVendeur($opportunites),
            'par_source' => $this->parSource($depuis),
            'transformation' => $this->transformation($opportunites),
        ];
    }

    /** Performance par commercial (sur les opportunités qui lui sont affectées). */
    private function parVendeur($opportunites): array
    {
        $noms = User::whereIn('id', $opportunites->pluck('user_id')->filter()->unique())
            ->pluck('name', 'id');

        return $opportunites
            ->groupBy('user_id')
            ->map(function ($lot, $userId) use ($noms) {
                $gagnees = $lot->where('statut', Opportunite::STATUT_GAGNEE);
                $perdues = $lot->where('statut', Opportunite::STATUT_PERDUE);
                $closes = $gagnees->count() + $perdues->count();

                return [
                    'user_id' => $userId === '' ? null : (int) $userId,
                    'vendeur' => $noms[$userId] ?? 'Non affecté',
                    'total' => $lot->count(),
                    'gagnees' => $gagnees->count(),
                    'perdues' => $perdues->count(),
                    'ouvertes' => $lot->where('statut', Opportunite::STATUT_OUVERTE)->count(),
                    'taux_conversion' => $closes > 0 ? round($gagnees->count() * 100 / $closes, 1) : 0,
                    'montant_gagne' => $this->fmt($gagnees->sum('montant_estime')),
                    'pipeline' => $this->fmt($lot->where('statut', Opportunite::STATUT_OUVERTE)->sum('montant_estime')),
                ];
            })
            ->sortByDesc(fn ($v) => (float) $v['montant_gagne'])
            ->values()
            ->all();
    }

    /** Répartition des prospects et clients convertis par origine de lead. */
    private function parSource(?string $depuis): array
    {
        // Même bornage que le reste des statistiques, sinon l'écran mélange
        // une période filtrée et des origines calculées sur tout l'historique.
        $tiers = Tiers::query()
            ->whereNotNull('lead_source')
            ->when($depuis !== null, fn ($q) => $q->whereDate('created_at', '>=', $depuis))
            ->get(['lead_source', 'is_prospect', 'converti_at']);

        return collect(Tiers::LEAD_SOURCES)
            ->map(function (string $source) use ($tiers) {
                $lot = $tiers->where('lead_source', $source);

                return [
                    'source' => $source,
                    'total' => $lot->count(),
                    'prospects' => $lot->where('is_prospect', true)->count(),
                    'convertis' => $lot->filter(fn ($t) => $t->converti_at !== null)->count(),
                ];
            })
            ->filter(fn ($r) => $r['total'] > 0)
            ->values()
            ->all();
    }

    /** Entonnoir : opportunités → devis générés → factures issues de ces devis. */
    private function transformation($opportunites): array
    {
        $ids = $opportunites->pluck('id');

        $documents = DocumentVente::whereIn('opportunite_id', $ids)
            ->get(['opportunite_id', 'type', 'total_ttc']);

        $devis = $documents->where('type', DocumentVente::TYPE_DEVIS);
        $factures = $documents->where('type', DocumentVente::TYPE_FACTURE);
        $total = $opportunites->count();

        // Le taux compte les OPPORTUNITÉS ayant au moins un devis, pas les devis :
        // plusieurs devis sur une même affaire ne doivent pas dépasser 100 %.
        $avecDevis = $devis->pluck('opportunite_id')->unique()->count();

        return [
            'opportunites' => $total,
            'devis' => $devis->count(),
            'factures' => $factures->count(),
            'ca_facture' => $this->fmt($factures->sum('total_ttc')),
            'taux_devis' => $total > 0 ? round($avecDevis * 100 / $total, 1) : 0,
        ];
    }

    /** Durée moyenne (jours) entre création et clôture des affaires tranchées. */
    private function dureeMoyenne($closes): ?float
    {
        $durees = $closes
            ->filter(fn ($o) => $o->close_at !== null && $o->created_at !== null)
            ->map(fn ($o) => $o->created_at->diffInDays($o->close_at));

        return $durees->isEmpty() ? null : round($durees->avg(), 1);
    }

    private function fmt(float|int $montant): string
    {
        return number_format((float) $montant, 2, '.', '');
    }
}
