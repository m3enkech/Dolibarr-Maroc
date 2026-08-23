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
        private \App\Modules\Tiers\Services\EncoursService $encours,
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

    public function ouvrirSession(float $fondCaisse, ?int $entrepotId = null, ?string $note = null): PosSession
    {
        if ($this->sessionOuverte() !== null) {
            throw ValidationException::withMessages([
                'session' => 'Une session de caisse est déjà ouverte. Fermez-la avant d\'en ouvrir une autre.',
            ]);
        }

        return PosSession::create([
            'user_id' => auth()->id(),
            'entrepot_id' => $entrepotId,
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
    public function vendre(
        PosSession $session,
        array $lignes,
        array $paiements,
        ?int $tiersId = null,
        ?string $clientUuid = null,
        bool $venteCredit = false,
    ): DocumentVente
    {
        $this->assertOuverte($session);

        // Idempotence : une vente déjà enregistrée (même client_uuid, rejouée par
        // la file d'attente hors-ligne après une coupure) renvoie la facture
        // existante au lieu d'en créer un doublon.
        if ($clientUuid !== null) {
            $existant = DocumentVente::where('client_uuid', $clientUuid)->first();
            if ($existant !== null) {
                return $existant->fresh(['lignes', 'tiers', 'paiements']);
            }
        }

        return DB::transaction(function () use ($session, $lignes, $paiements, $tiersId, $clientUuid, $venteCredit) {
            // Le crédit engage un client nommé : verrouillé le temps du contrôle
            // d'encours, pour que deux caisses ne le dépassent pas simultanément.
            $client = $tiersId !== null
                ? Tiers::whereKey($tiersId)->lockForUpdate()->first()
                : null;

            $document = $this->ventes->create([
                'type' => DocumentVente::TYPE_FACTURE,
                'tiers_id' => $client?->id ?? $this->clientComptoir()->id,
                'lignes' => $lignes,
            ]);

            $totalEncaisse = round(array_sum(array_map(fn ($p) => (float) $p['montant'], $paiements)), 2);
            $totalTicket = (float) $document->total_ttc;
            $reste = round($totalTicket - $totalEncaisse, 2);

            if ($reste > 0.009) {
                // Un solde n'est jamais implicite : sans demande explicite de
                // crédit, l'écart reste une faute de frappe du caissier.
                if (! $venteCredit) {
                    throw ValidationException::withMessages([
                        'paiements' => sprintf(
                            'Le total encaissé (%.2f MAD) ne correspond pas au total du ticket (%.2f MAD).',
                            $totalEncaisse,
                            $totalTicket,
                        ),
                    ]);
                }

                $this->assertCreditAutorise($client, $reste);
            }

            if ($reste < -0.009) {
                throw ValidationException::withMessages([
                    'paiements' => sprintf(
                        'Le total encaissé (%.2f MAD) dépasse le total du ticket (%.2f MAD).',
                        $totalEncaisse,
                        $totalTicket,
                    ),
                ]);
            }

            // La vente sort du stock de l'entrepôt rattaché à la caisse.
            $document->update([
                'pos_session_id' => $session->id,
                'entrepot_id' => $session->entrepot_id,
                'client_uuid' => $clientUuid,
                // Échéance du crédit : délai accordé au client, sinon le jour même.
                'date_echeance' => $reste > 0.009
                    ? now()->addDays((int) ($client?->delai_paiement_jours ?? 0))->toDateString()
                    : null,
            ]);
            $document = $this->ventes->valider($document);

            foreach ($paiements as $paiement) {
                $this->ventes->ajouterPaiement($document, [
                    'montant' => $paiement['montant'],
                    'mode' => $paiement['mode'],
                    'reference' => $paiement['reference'] ?? null,
                    // Rattache l'argent à la session qui l'a encaissé, et non à
                    // celle qui a émis le ticket (règlement d'un crédit plus tard).
                    'pos_session_id' => $session->id,
                ]);
            }

            return $document->fresh(['lignes', 'tiers', 'paiements']);
        });
    }

    /**
     * Un crédit ne s'accorde qu'à un client identifié, et dans la limite de son
     * encours autorisé. Le client de passage ne peut rien devoir : il est
     * anonyme et partagé par toutes les ventes au comptoir.
     */
    private function assertCreditAutorise(?Tiers $client, float $montantCredit): void
    {
        if ($client === null || $client->id === $this->clientComptoir()->id) {
            throw ValidationException::withMessages([
                'tiers_id' => 'Une vente à crédit exige un client identifié : sélectionnez-le avant d\'encaisser.',
            ]);
        }

        $controle = $this->encours->verifier($client, $montantCredit);

        if (! $controle['autorise']) {
            throw ValidationException::withMessages([
                'tiers_id' => sprintf(
                    'Plafond de crédit dépassé pour %s : encours %.2f MAD, plafond %.2f MAD, dépassement %.2f MAD.',
                    $client->name,
                    $controle['encours'],
                    $controle['plafond'],
                    $controle['depassement'],
                ),
            ]);
        }
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
        $ventes = DocumentVente::where('pos_session_id', $session->id)->with('lignes')->get();

        // Total des remises accordées (base HT) : prix brut − montant HT net, par ligne.
        $totalRemises = round($ventes->flatMap->lignes->sum(
            fn ($ligne) => round((float) $ligne->quantite * (float) $ligne->prix_unitaire - (float) $ligne->montant_ht, 2),
        ), 2);

        // L'argent est rattaché à la session qui l'a ENCAISSÉ : le règlement
        // d'un crédit accordé un autre jour appartient à la session du jour,
        // pas à celle qui a émis le ticket.
        $paiements = Paiement::where('pos_session_id', $session->id)->get();

        $parMode = collect(Paiement::MODES)
            ->mapWithKeys(fn (string $mode) => [
                $mode => round((float) $paiements->where('mode', $mode)->sum('montant'), 2),
            ])
            ->filter(fn (float $montant) => $montant > 0)
            ->map(fn (float $montant) => number_format($montant, 2, '.', ''));

        $especes = (float) $paiements->where('mode', 'especes')->sum('montant');

        // Vendu ≠ encaissé dès qu'une vente part à crédit.
        //
        // Le crédit accordé est borné À CETTE SESSION : on ne retranche que ce
        // qui a été encaissé PENDANT la session sur ses propres tickets. Sans
        // cette borne, le règlement ultérieur d'un crédit ferait retomber le
        // chiffre à zéro et un Z réimprimé contredirait le Z de clôture.
        $totalVendu = round((float) $ventes->sum('total_ttc'), 2);
        $encaisseSurTickets = round((float) Paiement::whereIn('document_vente_id', $ventes->pluck('id'))
            ->where('pos_session_id', $session->id)
            ->sum('montant'), 2);
        $totalCredit = round($totalVendu - $encaisseSurTickets, 2);

        return [
            'tickets' => $ventes->count(),
            'total_ht' => number_format((float) $ventes->sum('total_ht'), 2, '.', ''),
            'total_tva' => number_format((float) $ventes->sum('total_tva'), 2, '.', ''),
            'total_ttc' => number_format($totalVendu, 2, '.', ''),
            'par_mode' => $parMode,
            'total_remises' => number_format($totalRemises, 2, '.', ''),
            // Encaissé pendant la session, tous tickets confondus (y compris le
            // règlement d'anciens crédits).
            'total_encaisse' => number_format(round((float) $paiements->sum('montant'), 2), 2, '.', ''),
            // Crédit accordé sur les ventes de cette session.
            'total_credit' => number_format(max(0, $totalCredit), 2, '.', ''),
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
