<?php

namespace App\Modules\Effets\Services;

use App\Core\Sequences\SequenceService;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Compta\Models\Ecriture;
use App\Modules\Compta\Models\EcritureLigne;
use App\Modules\Compta\Services\ComptaService;
use App\Modules\Compta\Services\LettrageService;
use App\Modules\Effets\Models\Effet;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Effets / traites (LCN). Un effet transfère la créance client (3421 → 3425
 * effets à recevoir) ou la dette fournisseur (4411 → 4415 effets à payer),
 * lettre la facture d'origine, puis se solde par la banque à l'échéance.
 */
class EffetService
{
    private const CLIENTS = '3421';
    private const EFFETS_RECEVOIR = '3425';
    private const FOURNISSEURS = '4411';
    private const EFFETS_PAYER = '4415';

    public function __construct(
        private SequenceService $sequences,
        private ComptaService $compta,
        private LettrageService $lettrage,
    ) {}

    /* ------------------------------------------------------------------ */
    /* Création                                                            */
    /* ------------------------------------------------------------------ */

    /** Effet à recevoir tiré d'une facture client (transfert 3421 → 3425). */
    public function creerARecevoir(DocumentVente $facture, string $dateEcheance): Effet
    {
        if ($facture->type !== DocumentVente::TYPE_FACTURE || $facture->isBrouillon()) {
            throw ValidationException::withMessages(['facture' => 'Seule une facture validée peut être tirée en effet.']);
        }

        $montant = $facture->resteAPayer();
        if ($montant <= 0.009) {
            throw ValidationException::withMessages(['facture' => 'Cette facture est déjà soldée.']);
        }

        return DB::transaction(function () use ($facture, $montant, $dateEcheance) {
            $code = $this->sequences->next('EFR');
            $clients = $this->compta->compteParDefaut('clients');
            $effets = $this->compta->compteParCode(self::EFFETS_RECEVOIR);

            $ecriture = $this->compta->ecrire(
                Ecriture::JOURNAL_DIVERS,
                now()->toDateString(),
                "Effet à recevoir {$code} — {$facture->tiers->name}",
                [
                    ['compte' => $effets, 'debit' => $montant, 'credit' => 0, 'tiers_id' => $facture->tiers_id],
                    ['compte' => $clients, 'debit' => 0, 'credit' => $montant, 'tiers_id' => $facture->tiers_id],
                ],
                reference: $code,
            );

            // Lettrage : la facture (débit 3421) est soldée par le crédit de l'effet.
            $lettrageCode = $this->lettrerFacture(
                $clients->id,
                EcritureLigne::query()
                    ->where('compte_id', $clients->id)
                    ->whereNull('lettrage')
                    ->whereHas('ecriture', fn ($q) => $q->where('document_vente_id', $facture->id))
                    ->pluck('id')->all(),
                $ecriture,
                $clients->id,
                credit: true,
            );

            return Effet::create([
                'tiers_id' => $facture->tiers_id,
                'document_vente_id' => $facture->id,
                'type' => Effet::TYPE_RECEVOIR,
                'code' => $code,
                'montant' => $montant,
                'date_creation' => now()->toDateString(),
                'date_echeance' => $dateEcheance,
                'statut' => Effet::STATUT_PORTEFEUILLE,
                'lettrage_code' => $lettrageCode,
            ]);
        });
    }

    /** Effet à payer accepté sur une facture fournisseur (transfert 4411 → 4412). */
    public function creerAPayer(DocumentAchat $facture, string $dateEcheance): Effet
    {
        if ($facture->type !== DocumentAchat::TYPE_FACTURE || $facture->isBrouillon()) {
            throw ValidationException::withMessages(['facture' => 'Seule une facture fournisseur validée peut être payée par effet.']);
        }

        $montant = $facture->resteAPayer();
        if ($montant <= 0.009) {
            throw ValidationException::withMessages(['facture' => 'Cette facture est déjà soldée.']);
        }

        return DB::transaction(function () use ($facture, $montant, $dateEcheance) {
            $code = $this->sequences->next('EFP');
            $fournisseurs = $this->compta->compteParDefaut('fournisseurs');
            $effets = $this->compta->compteParCode(self::EFFETS_PAYER);

            $ecriture = $this->compta->ecrire(
                Ecriture::JOURNAL_DIVERS,
                now()->toDateString(),
                "Effet à payer {$code} — {$facture->tiers->name}",
                [
                    ['compte' => $fournisseurs, 'debit' => $montant, 'credit' => 0, 'tiers_id' => $facture->tiers_id],
                    ['compte' => $effets, 'debit' => 0, 'credit' => $montant, 'tiers_id' => $facture->tiers_id],
                ],
                reference: $code,
            );

            // La facture fournisseur (crédit 4411) est soldée par le débit de l'effet.
            // Les écritures d'achat sont rattachées par référence (pas de FK document).
            $lettrageCode = $this->lettrerFacture(
                $fournisseurs->id,
                EcritureLigne::query()
                    ->where('compte_id', $fournisseurs->id)
                    ->whereNull('lettrage')
                    ->whereHas('ecriture', fn ($q) => $q->where('reference', $facture->code))
                    ->pluck('id')->all(),
                $ecriture,
                $fournisseurs->id,
                credit: false,
            );

            return Effet::create([
                'tiers_id' => $facture->tiers_id,
                'document_achat_id' => $facture->id,
                'type' => Effet::TYPE_PAYER,
                'code' => $code,
                'montant' => $montant,
                'date_creation' => now()->toDateString(),
                'date_echeance' => $dateEcheance,
                'statut' => Effet::STATUT_PORTEFEUILLE,
                'lettrage_code' => $lettrageCode,
            ]);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Règlement à l'échéance                                              */
    /* ------------------------------------------------------------------ */

    /** Encaissement d'un effet à recevoir (3425 → banque). */
    public function encaisser(Effet $effet, ?string $date = null): Effet
    {
        $this->assertPortefeuille($effet, Effet::TYPE_RECEVOIR);

        $this->compta->ecrire(
            Ecriture::JOURNAL_TRESORERIE,
            $date ?? now()->toDateString(),
            "Encaissement effet {$effet->code}",
            [
                ['compte' => $this->compta->compteParDefaut('banque'), 'debit' => (float) $effet->montant, 'credit' => 0],
                ['compte' => $this->compta->compteParCode(self::EFFETS_RECEVOIR), 'debit' => 0, 'credit' => (float) $effet->montant, 'tiers_id' => $effet->tiers_id],
            ],
            reference: $effet->code,
        );

        $effet->update(['statut' => Effet::STATUT_ENCAISSE, 'regle_at' => now()]);

        return $effet->refresh();
    }

    /** Paiement d'un effet à payer (banque → 4412). */
    public function payer(Effet $effet, ?string $date = null): Effet
    {
        $this->assertPortefeuille($effet, Effet::TYPE_PAYER);

        $this->compta->ecrire(
            Ecriture::JOURNAL_TRESORERIE,
            $date ?? now()->toDateString(),
            "Paiement effet {$effet->code}",
            [
                ['compte' => $this->compta->compteParCode(self::EFFETS_PAYER), 'debit' => (float) $effet->montant, 'credit' => 0, 'tiers_id' => $effet->tiers_id],
                ['compte' => $this->compta->compteParDefaut('banque'), 'debit' => 0, 'credit' => (float) $effet->montant],
            ],
            reference: $effet->code,
        );

        $effet->update(['statut' => Effet::STATUT_PAYE, 'regle_at' => now()]);

        return $effet->refresh();
    }

    /** Effet à recevoir impayé : la créance revient (3425 → 3421), facture à relancer de nouveau. */
    public function marquerImpaye(Effet $effet): Effet
    {
        $this->assertPortefeuille($effet, Effet::TYPE_RECEVOIR);

        $this->compta->ecrire(
            Ecriture::JOURNAL_DIVERS,
            now()->toDateString(),
            "Effet impayé {$effet->code} — {$effet->tiers->name}",
            [
                ['compte' => $this->compta->compteParDefaut('clients'), 'debit' => (float) $effet->montant, 'credit' => 0, 'tiers_id' => $effet->tiers_id],
                ['compte' => $this->compta->compteParCode(self::EFFETS_RECEVOIR), 'debit' => 0, 'credit' => (float) $effet->montant, 'tiers_id' => $effet->tiers_id],
            ],
            reference: $effet->code,
        );

        $effet->update(['statut' => Effet::STATUT_IMPAYE, 'regle_at' => now()]);

        return $effet->refresh();
    }

    /* ------------------------------------------------------------------ */

    private function assertPortefeuille(Effet $effet, string $type): void
    {
        if ($effet->type !== $type) {
            throw ValidationException::withMessages(['effet' => 'Type d\'effet incompatible avec cette opération.']);
        }
        if (! $effet->isPortefeuille()) {
            throw ValidationException::withMessages(['effet' => 'Cet effet n\'est plus en portefeuille.']);
        }
    }

    /**
     * Lettre les lignes de la facture (créance/dette d'origine) avec la ligne de
     * l'effet du même compte collectif. Renvoie le code de lettrage.
     */
    private function lettrerFacture(int $compteId, array $factureLigneIds, Ecriture $ecritureEffet, int $compteEffetId, bool $credit): ?string
    {
        $ligneEffet = EcritureLigne::where('ecriture_id', $ecritureEffet->id)
            ->where('compte_id', $compteEffetId)
            ->where($credit ? 'credit' : 'debit', '>', 0)
            ->value('id');

        $ids = array_values(array_unique([...$factureLigneIds, $ligneEffet]));

        if (count($ids) < 2 || $ligneEffet === null) {
            return null; // rien à rapprocher (ex. facture déjà lettrée)
        }

        return $this->lettrage->lettrer($ids)['code'] ?? null;
    }
}
