<?php

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Models\Communication;
use App\Modules\Effets\Models\Effet;
use App\Modules\Relances\Models\Relance;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Prépare un message pour le client d'un document : à qui l'adresser, quoi
 * lui dire (en français et en arabe), quel lien joindre.
 *
 * Ne sait rien du transport. Le même résultat (MessagePrepare) sert le lien
 * wa.me d'aujourd'hui et servira l'API WhatsApp Business de demain.
 *
 * Les règles d'envoi vivent ICI, et pas dans les contrôleurs, parce que la
 * préparation ET la journalisation les appliquent : un message qu'on refuse de
 * préparer ne doit pas pouvoir être journalisé par un appel direct.
 */
class PreparationMessage
{
    public function __construct(
        private NumerosDuTiers $numeros,
        private LienPublicDocument $liens,
        private RedactionMessages $redaction,
    ) {}

    /**
     * Ce message peut-il partir pour ce document ? Lève une erreur 422 sinon.
     *
     * Un BROUILLON ne part jamais : tant qu'il n'est pas validé, le document
     * n'existe pas pour le client — il peut encore changer de montant, ou
     * disparaître. Même règle que le portail acheteur.
     */
    public function verifier(string $type, DocumentVente $document, ?Paiement $paiement = null, ?int $niveau = null): void
    {
        if (! in_array($type, Communication::TYPES, true)) {
            $this->refuser('type', __('Type de message inconnu.'));
        }

        $attendu = $type === Communication::TYPE_DEVIS ? DocumentVente::TYPE_DEVIS : DocumentVente::TYPE_FACTURE;

        if ($document->type !== $attendu) {
            $this->refuser('document', match ($type) {
                Communication::TYPE_RELANCE => __('Seule une facture peut être relancée.'),
                Communication::TYPE_PAIEMENT => __('Une confirmation de paiement porte sur une facture.'),
                Communication::TYPE_DEVIS => __('Ce document n\'est pas un devis.'),
                default => __('Ce document n\'est pas une facture.'),
            });
        }

        if ($document->isBrouillon()) {
            $this->refuser('document', __('Un document en brouillon ne s\'envoie pas : validez-le d\'abord.'));
        }

        if ($type === Communication::TYPE_RELANCE) {
            $this->verifierRelance($document, $niveau);
        }

        if ($type === Communication::TYPE_PAIEMENT) {
            if ($paiement === null) {
                $this->refuser('paiement_id', __('Indiquez le paiement à confirmer.'));
            }

            // Le paiement est déjà cherché dans le périmètre de l'entreprise ;
            // reste à s'assurer qu'il règle CE document et pas un autre.
            if ((int) $paiement->document_vente_id !== (int) $document->id) {
                $this->refuser('paiement_id', __('Ce paiement ne concerne pas ce document.'));
            }
        }
    }

    /**
     * Le paiement désigné par l'appelant, à condition qu'il règle CE document.
     *
     * Le même refus qu'il n'existe pas, qu'il règle une autre pièce ou qu'il
     * appartienne à une autre entreprise (le scope le rend alors introuvable) :
     * rien ne doit permettre de sonder les identifiants des autres.
     */
    public function paiementDe(DocumentVente $document, mixed $paiementId): ?Paiement
    {
        if ($paiementId === null || $paiementId === '') {
            return null;
        }

        $paiement = Paiement::query()->whereKey((int) $paiementId)->first();

        if ($paiement === null || (int) $paiement->document_vente_id !== (int) $document->id) {
            $this->refuser('paiement_id', __('Ce paiement ne concerne pas ce document.'));
        }

        return $paiement;
    }

    public function preparer(string $type, DocumentVente $document, ?Paiement $paiement = null, ?int $niveau = null): MessagePrepare
    {
        if ($type === Communication::TYPE_RELANCE) {
            $niveau ??= $this->niveauSuggere($document);
        }

        $this->verifier($type, $document, $paiement, $niveau);

        $document->loadMissing(['tiers', 'tenant']);
        $lien = $this->liens->generer($document);
        $contexte = $this->contexte($type, $document, $paiement, $niveau, $lien['url'], $lien['expire_le']);
        $numeros = $this->numeros->pour($document->tiers);

        $relance = $type === Communication::TYPE_RELANCE;

        return new MessagePrepare(
            type: $type,
            document: $document,
            paiement: $paiement,
            niveau: $relance ? $niveau : null,
            destinataires: $numeros['destinataires'],
            ignores: $numeros['ignores'],
            messages: $this->redaction->rediger($type, $contexte),
            lien: $lien['url'],
            lienExpireLe: $lien['expire_le'],
            contexte: [
                'document' => $contexte['resume'],
                'niveau_label' => $relance ? Relance::NIVEAUX[$niveau] : null,
                'niveaux' => $relance ? $this->niveaux($document) : null,
            ],
        );
    }

    /**
     * Les niveaux de relance et ceux que cette facture admet, pour que l'écran
     * grise d'avance ce que le serveur refuserait.
     *
     * @return list<array{niveau: int, label: string, possible: bool, suggere: bool}>
     */
    public function niveaux(DocumentVente $facture): array
    {
        $echue = $this->estEchue($facture);
        $suggere = $this->niveauSuggere($facture);

        return array_map(fn (int $niveau) => [
            'niveau' => $niveau,
            'label' => Relance::NIVEAUX[$niveau],
            'possible' => $niveau === 1 || $echue,
            'suggere' => $niveau === $suggere,
        ], array_keys(Relance::NIVEAUX));
    }

    /**
     * Le niveau qui suit la dernière relance, plafonné à la mise en demeure.
     * Une facture pas encore échue ne reçoit qu'un rappel courtois, même si
     * on l'a déjà relancée avant l'échéance.
     */
    public function niveauSuggere(DocumentVente $facture): int
    {
        if (! $this->estEchue($facture)) {
            return 1;
        }

        $dernier = (int) Relance::where('document_vente_id', $facture->id)->max('niveau');

        return min(max(array_keys(Relance::NIVEAUX)), $dernier + 1);
    }

    private function verifierRelance(DocumentVente $facture, ?int $niveau): void
    {
        if ($niveau === null || ! array_key_exists($niveau, Relance::NIVEAUX)) {
            $this->refuser('niveau', __('Niveau de relance inconnu.'));
        }

        if ($facture->resteAPayer() <= 0.009) {
            $this->refuser('document', __('Cette facture est déjà soldée.'));
        }

        // Une traite tirée sur la facture a repris la créance : réclamer la
        // facture, c'est réclamer une somme dont le paiement est déjà organisé.
        // Même exclusion que la liste « à relancer ».
        if ($this->effetsActifs($facture)->isNotEmpty()) {
            $this->refuser('document', __('Cette facture est couverte par une traite en cours : c\'est l\'effet qui se suit.'));
        }

        // Une mise en demeure sur une somme qui n'est pas encore due n'a pas de
        // sens, et un « second rappel ferme » avant l'échéance froisse un client
        // qui n'est pas en retard.
        if ($niveau > 1 && ! $this->estEchue($facture)) {
            $this->refuser('niveau', __('Cette facture n\'est pas encore échue : seul un rappel courtois est possible.'));
        }
    }

    /**
     * Les traites vivantes tirées sur la pièce (en portefeuille, encaissées,
     * payées) — le même critère que la liste « à relancer » et le portail.
     *
     * @return Collection<int, Effet>
     */
    private function effetsActifs(DocumentVente $document): Collection
    {
        if ($document->type !== DocumentVente::TYPE_FACTURE) {
            return new Collection;
        }

        return Effet::query()
            ->where('type', Effet::TYPE_RECEVOIR)
            ->whereIn('statut', Effet::STATUTS_ACTIFS)
            ->where('document_vente_id', $document->id)
            ->orderBy('date_echeance')
            ->orderBy('id')
            ->get();
    }

    /** Échéance effective : la date d'échéance si elle est posée, sinon la date de pièce. */
    private function echeanceEffective(DocumentVente $document): ?Carbon
    {
        return ($document->date_echeance ?? $document->date_document)?->copy()->startOfDay();
    }

    /** Échue = l'échéance est PASSÉE ; le jour même, la facture est encore à temps. */
    private function estEchue(DocumentVente $document): bool
    {
        return $this->echeanceEffective($document)?->lt(now()->startOfDay()) === true;
    }

    /** @return array<string, mixed> */
    private function contexte(string $type, DocumentVente $document, ?Paiement $paiement, ?int $niveau, string $lien, Carbon $expireLe): array
    {
        $echeance = $this->echeanceEffective($document);
        $echue = $this->estEchue($document);
        $joursRetard = $echue ? (int) $echeance->diffInDays(now()->startOfDay()) : 0;
        $resteHorsEffets = max(0.0, $document->resteAPayer());
        $ttc = (float) $document->total_ttc;

        // Une traite ne crée aucun Paiement : la créance passe sur l'effet, et
        // resteAPayer() la compte encore, même une fois la banque payée. Un
        // message qui s'en tiendrait là réclamerait au client une somme qu'il
        // a déjà réglée par LCN. L'effet ENCAISSÉ (ou payé) vaut règlement ;
        // celui EN PORTEFEUILLE couvre la somme jusqu'à son échéance, et le
        // message le dit au lieu de demander de payer.
        $effets = $this->effetsActifs($document);
        $enPortefeuille = $effets->filter(fn (Effet $e) => $e->isPortefeuille());
        $reste = max(0.0, $resteHorsEffets - (float) $effets->sum(fn (Effet $e) => (float) $e->montant));
        $soldee = $reste <= 0.009 && $enPortefeuille->isEmpty();

        $resume = [
            'id' => $document->id,
            'type' => $document->type,
            'code' => $document->code,
            'statut' => $document->statut,
            'date_document' => $document->date_document?->toDateString(),
            'date_echeance' => $document->date_echeance?->toDateString(),
            'total_ttc' => number_format($ttc, 2, '.', ''),
            'reste_a_payer' => number_format($resteHorsEffets, 2, '.', ''),
            'tiers' => $document->tiers !== null
                ? ['id' => $document->tiers->id, 'nom' => $document->tiers->name]
                : null,
        ];

        if ($type === Communication::TYPE_RELANCE) {
            $resume['echue'] = $echue;
            $resume['jours_retard'] = $joursRetard;
        }

        return [
            'resume' => $resume,
            'entreprise' => (string) $document->tenant?->name,
            'code' => $document->code,
            'date' => $document->date_document?->format('d/m/Y'),
            'echeance' => $echeance?->format('d/m/Y'),
            'echeance_saisie' => $document->date_echeance?->format('d/m/Y'),
            'echue' => $echue,
            // Pour un devis, la date d'échéance est la fin de sa validité.
            'validite_depassee' => $document->date_echeance !== null && $echue,
            'jours_retard' => $joursRetard,
            'niveau' => $niveau,
            'ttc' => $ttc,
            'reste' => $reste,
            'soldee' => $soldee,
            'partiellement_reglee' => ! $soldee && $reste > 0.009 && $reste < $ttc - 0.009,
            'effets' => $enPortefeuille->map(fn (Effet $e) => [
                'code' => $e->code,
                'montant' => (float) $e->montant,
                'echeance' => $e->date_echeance?->format('d/m/Y'),
            ])->values()->all(),
            'lien' => $lien,
            'lien_expire' => $expireLe->format('d/m/Y'),
            'paiement' => $paiement !== null ? [
                'montant' => (float) $paiement->montant,
                'mode' => $paiement->mode,
                'reference' => $paiement->reference,
                'date' => $paiement->date_paiement?->format('d/m/Y'),
            ] : null,
        ];
    }

    private function refuser(string $champ, string $message): never
    {
        throw ValidationException::withMessages([$champ => $message]);
    }
}
