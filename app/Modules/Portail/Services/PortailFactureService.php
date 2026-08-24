<?php

namespace App\Modules\Portail\Services;

use App\Modules\Effets\Models\Effet;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\DocumentVenteLigne;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Factures et avoirs de l'acheteur.
 *
 * Trois règles tiennent ce service :
 *
 *  1. Un BROUILLON n'est jamais exposé. Tant que le grossiste ne l'a pas
 *     validée, une facture n'existe pas : il peut encore la corriger ou la
 *     supprimer. L'annoncer au client comme une somme due serait faux.
 *  2. L'acheteur ne voit QUE ses documents. Le scope d'entreprise laisserait
 *     passer ceux de tous les clients du grossiste : le filtre sur le compte
 *     client est explicite, comme pour les commandes.
 *  3. Une facture couverte par un effet actif n'est PAS impayée. Quand une
 *     traite est tirée, la créance passe sur l'effet mais la facture garde un
 *     reste à payer positif à vie. La compter dans « à payer » réclamerait au
 *     client une somme qu'il a déjà réglée. Même exclusion que RelanceService.
 */
class PortailFactureService
{
    /** Ce qu'un client a le droit de voir de sa comptabilité : ses pièces à lui. */
    private const TYPES = [DocumentVente::TYPE_FACTURE, DocumentVente::TYPE_AVOIR];

    /** @return array{data: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function lister(Tiers $client, int $page, int $parPage = 20): array
    {
        $documents = $this->base($client)
            ->with('lignes')
            ->latest('date_document')
            // `id` en second critère : deux factures du même jour auraient
            // sinon un ordre indéterminé d'une page à l'autre.
            ->latest('id')
            ->paginate($parPage, ['*'], 'page', $page);

        $pieces = collect($documents->items());
        $couverts = $this->couvertsParEffet($pieces);

        return [
            'data' => $pieces->map(fn (DocumentVente $d) => $this->resume($d, $couverts))->all(),
            'meta' => [
                'total' => $documents->total(),
                'page' => $documents->currentPage(),
                'dernier_page' => $documents->lastPage(),
            ],
        ];
    }

    /**
     * Ce que l'acheteur doit aujourd'hui, ventilé pour qu'aucune somme ne
     * disparaisse : à payer, dont en retard, plus ce qui est déjà sous traite
     * et ce qui lui reste à valoir en avoirs.
     *
     * @return array<string, string|int>
     */
    public function situation(Tiers $client): array
    {
        // Un document soldé passe au statut « payé » : se limiter aux documents
        // encore ouverts évite de charger tout l'historique pour une somme nulle.
        $ouverts = $this->base($client)->where('statut', DocumentVente::STATUT_VALIDE)->get();
        $couverts = $this->couvertsParEffet($ouverts);
        $aujourdhui = now()->startOfDay();

        $aPayer = 0.0;
        $enRetard = 0.0;
        $sousTraite = 0.0;
        $avoirs = 0.0;
        $nbOuvertes = 0;

        foreach ($ouverts as $document) {
            $reste = $document->resteAPayer();

            if ($reste <= 0.009) {
                continue;
            }

            if ($document->type === DocumentVente::TYPE_AVOIR) {
                $avoirs += $reste;

                continue;
            }

            if ($couverts->has($document->id)) {
                $sousTraite += $reste;

                continue;
            }

            $aPayer += $reste;
            $nbOuvertes++;

            if ($this->echeance($document)?->lt($aujourdhui) === true) {
                $enRetard += $reste;
            }
        }

        return [
            'a_payer' => $this->montant($aPayer),
            'en_retard' => $this->montant($enRetard),
            'sous_traite' => $this->montant($sousTraite),
            'avoirs_a_valoir' => $this->montant($avoirs),
            'nb_factures_ouvertes' => $nbOuvertes,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Tiers $client, int $documentId): array
    {
        $document = $this->trouver($client, $documentId, ['lignes', 'paiements', 'source']);

        return $this->resume($document, $this->couvertsParEffet(collect([$document]))) + [
            'notes' => $document->notes,
            'origine' => $document->source !== null
                ? ['code' => $document->source->code, 'type' => $document->source->type]
                : null,
            'total_ht' => $this->montant((float) $document->total_ht),
            'total_tva' => $this->montant((float) $document->total_tva),
            'lignes' => $document->lignes->map(fn (DocumentVenteLigne $l) => [
                'designation' => $l->designation,
                'quantite' => (float) $l->quantite,
                'prix_unitaire' => $this->montant((float) $l->prix_unitaire),
                'tva_rate' => (float) $l->tva_rate,
                'montant_ht' => $this->montant((float) $l->montant_ht),
                'montant_ttc' => $this->montant((float) $l->montant_ttc),
            ])->values()->all(),
            'paiements' => $document->paiements
                ->sortBy('date_paiement')
                ->map(fn (Paiement $p) => [
                    'date' => $p->date_paiement?->toDateString(),
                    'montant' => $this->montant((float) $p->montant),
                    'mode' => $p->mode,
                    'reference' => $p->reference,
                ])->values()->all(),
        ];
    }

    /**
     * Document destiné au PDF. Passe par le même filtre que le reste : une
     * pièce d'un autre client, ou encore en brouillon, n'existe pas ici.
     */
    public function document(Tiers $client, int $documentId): DocumentVente
    {
        return $this->trouver($client, $documentId);
    }

    /** Pièces de CE client, jamais un brouillon. */
    private function base(Tiers $client): Builder
    {
        return DocumentVente::query()
            ->where('tiers_id', $client->id)
            ->whereIn('type', self::TYPES)
            ->where('statut', '!=', DocumentVente::STATUT_BROUILLON);
    }

    /** @param  array<int, string>  $relations */
    private function trouver(Tiers $client, int $documentId, array $relations = []): DocumentVente
    {
        $document = $this->base($client)->with($relations)->find($documentId);

        abort_if($document === null, 404);

        return $document;
    }

    /**
     * Factures dont la créance a été transférée sur une traite encore vivante.
     *
     * @param  Collection<int, DocumentVente>  $documents
     * @return Collection<int, int>  document_vente_id => position (indexé pour `has()`)
     */
    private function couvertsParEffet(Collection $documents): Collection
    {
        $ids = $documents
            ->where('type', DocumentVente::TYPE_FACTURE)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return collect();
        }

        return Effet::query()
            ->where('type', Effet::TYPE_RECEVOIR)
            ->whereIn('statut', Effet::STATUTS_ACTIFS)
            ->whereIn('document_vente_id', $ids)
            ->pluck('document_vente_id')
            ->flip();
    }

    /**
     * @param  Collection<int, int>  $couverts
     * @return array<string, mixed>
     */
    private function resume(DocumentVente $document, Collection $couverts): array
    {
        $reste = $document->resteAPayer();
        $echeance = $this->echeance($document);
        $retard = $echeance !== null && $echeance->lt(now()->startOfDay());

        return [
            'id' => $document->id,
            'code' => $document->code,
            'type' => $document->type,
            'date' => $document->date_document?->toDateString(),
            'echeance' => $echeance?->toDateString(),
            'total_ttc' => $this->montant((float) $document->total_ttc),
            'montant_paye' => $this->montant($document->montantPaye()),
            'reste_a_payer' => $this->montant($reste),
            'etat' => $this->etat($document, $reste, $couverts->has($document->id), $retard),
            // Ne comptabiliser le retard que s'il reste quelque chose à régler :
            // une facture soldée en retard est une facture payée, point.
            'jours_retard' => $retard && $reste > 0.009 && ! $couverts->has($document->id)
                ? (int) $echeance->diffInDays(now()->startOfDay())
                : 0,
            'nb_lignes' => $document->relationLoaded('lignes') ? $document->lignes->count() : null,
        ];
    }

    /** Le vocabulaire de l'ERP ne parle pas à un épicier : celui-ci, oui. */
    private function etat(DocumentVente $document, float $reste, bool $couvert, bool $retard): string
    {
        if ($document->type === DocumentVente::TYPE_AVOIR) {
            return $reste <= 0.009 ? 'avoir_rembourse' : 'avoir_a_valoir';
        }

        return match (true) {
            $reste <= 0.009 => 'payee',
            $couvert => 'traite_en_cours',
            $retard => 'en_retard',
            default => 'a_payer',
        };
    }

    /** Échéance effective : la date d'échéance si elle est posée, sinon la date de pièce. */
    private function echeance(DocumentVente $document): ?Carbon
    {
        $date = $document->date_echeance ?? $document->date_document;

        return $date?->copy()->startOfDay();
    }

    private function montant(float $valeur): string
    {
        return number_format($valeur, 2, '.', '');
    }
}
