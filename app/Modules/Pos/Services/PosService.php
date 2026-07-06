<?php

namespace App\Modules\Pos\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Tiers\Services\TiersService;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use App\Modules\Ventes\Services\VenteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosService
{
    public function __construct(
        private VenteService $ventes,
        private TiersService $tiers,
        private SequenceService $sequences,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Sessions de caisse                                                  */
    /* ------------------------------------------------------------------ */

    /** Session ouverte du vendeur courant (une seule à la fois par vendeur). */
    public function sessionOuverte(): ?PosSession
    {
        return PosSession::where('user_id', auth()->id())
            ->where('statut', PosSession::STATUT_OUVERTE)
            ->latest('id')
            ->first();
    }

    public function ouvrirSession(float $fondCaisse, ?string $note = null): PosSession
    {
        if ($this->sessionOuverte() !== null) {
            throw ValidationException::withMessages([
                'session' => 'Une session de caisse est déjà ouverte. Fermez-la avant d\'en ouvrir une autre.',
            ]);
        }

        return PosSession::create([
            'user_id' => auth()->id(),
            'code' => $this->sequences->next('CS'),
            'statut' => PosSession::STATUT_OUVERTE,
            'fond_caisse' => $fondCaisse,
            'note' => $note,
            'opened_at' => now(),
        ]);
    }

    /**
     * Ferme la session : l'écart = espèces comptées − espèces théoriques
     * (fond de caisse + encaissements en espèces de la session).
     */
    public function fermerSession(PosSession $session, float $montantCompte, ?string $note = null): PosSession
    {
        $this->assertOuverte($session);

        $rapport = $this->rapport($session);

        $session->update([
            'statut' => PosSession::STATUT_FERMEE,
            'montant_compte' => $montantCompte,
            'ecart' => round($montantCompte - (float) $rapport['especes_theorique'], 2),
            'note' => $note ?? $session->note,
            'closed_at' => now(),
        ]);

        return $session->refresh();
    }

    /* ------------------------------------------------------------------ */
    /* Vente comptoir                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Vente POS en un geste : facture créée, validée et intégralement payée
     * dans la même transaction. Les événements FactureValidee et
     * PaiementEnregistre déclenchent la sortie de stock et les écritures
     * comptables exactement comme une facture classique.
     *
     * @param array<int, array<string, mixed>> $lignes
     * @param array<int, array{mode: string, montant: float|string, reference?: ?string}> $paiements
     */
    public function vendre(PosSession $session, array $lignes, array $paiements, ?int $tiersId = null): DocumentVente
    {
        $this->assertOuverte($session);

        return DB::transaction(function () use ($session, $lignes, $paiements, $tiersId) {
            $document = $this->ventes->create([
                'type' => DocumentVente::TYPE_FACTURE,
                'tiers_id' => $tiersId ?? $this->clientComptoir()->id,
                'lignes' => $lignes,
            ]);

            // Un ticket de caisse est payé en totalité, ni plus ni moins.
            $totalEncaisse = round(array_sum(array_map(fn ($p) => (float) $p['montant'], $paiements)), 2);
            $totalTicket = (float) $document->total_ttc;

            if (abs($totalEncaisse - $totalTicket) > 0.009) {
                throw ValidationException::withMessages([
                    'paiements' => sprintf(
                        'Le total encaissé (%.2f MAD) ne correspond pas au total du ticket (%.2f MAD).',
                        $totalEncaisse,
                        $totalTicket,
                    ),
                ]);
            }

            $document->update(['pos_session_id' => $session->id]);
            $document = $this->ventes->valider($document);

            foreach ($paiements as $paiement) {
                $this->ventes->ajouterPaiement($document, [
                    'montant' => $paiement['montant'],
                    'mode' => $paiement['mode'],
                    'reference' => $paiement['reference'] ?? null,
                ]);
            }

            return $document->fresh(['lignes', 'tiers', 'paiements']);
        });
    }

    /** Client de passage, créé à la volée (même principe que l'entrepôt par défaut). */
    public function clientComptoir(): Tiers
    {
        return Tiers::where('name', 'Client comptoir')->first()
            ?? $this->tiers->create([
                'name' => 'Client comptoir',
                'is_client' => true,
                'is_supplier' => false,
            ]);
    }

    /* ------------------------------------------------------------------ */
    /* Rapport de session (X en cours de journée, Z à la clôture)          */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    public function rapport(PosSession $session): array
    {
        $ventes = DocumentVente::where('pos_session_id', $session->id)->get();

        $paiements = Paiement::whereIn('document_vente_id', $ventes->pluck('id'))->get();

        $parMode = collect(Paiement::MODES)
            ->mapWithKeys(fn (string $mode) => [
                $mode => round((float) $paiements->where('mode', $mode)->sum('montant'), 2),
            ])
            ->filter(fn (float $montant) => $montant > 0)
            ->map(fn (float $montant) => number_format($montant, 2, '.', ''));

        $especes = (float) $paiements->where('mode', 'especes')->sum('montant');

        return [
            'tickets' => $ventes->count(),
            'total_ht' => number_format((float) $ventes->sum('total_ht'), 2, '.', ''),
            'total_tva' => number_format((float) $ventes->sum('total_tva'), 2, '.', ''),
            'total_ttc' => number_format((float) $ventes->sum('total_ttc'), 2, '.', ''),
            'par_mode' => $parMode,
            'fond_caisse' => number_format((float) $session->fond_caisse, 2, '.', ''),
            'especes_theorique' => number_format((float) $session->fond_caisse + $especes, 2, '.', ''),
        ];
    }

    private function assertOuverte(PosSession $session): void
    {
        if (! $session->isOuverte()) {
            throw ValidationException::withMessages([
                'session' => 'Cette session de caisse est fermée.',
            ]);
        }
    }
}
