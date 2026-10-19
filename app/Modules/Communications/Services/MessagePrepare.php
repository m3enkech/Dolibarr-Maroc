<?php

namespace App\Modules\Communications\Services;

use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Support\Carbon;

/**
 * Un message prêt à partir, indépendant du moyen de l'envoyer.
 *
 * C'est la frontière entre la préparation (qui écrire, quoi dire, quel lien
 * joindre) et le transport. Aujourd'hui le transport est le lien wa.me ouvert
 * par l'utilisateur (Transport\ClickToChat) ; demain l'API WhatsApp Business
 * recevra le même objet, avec le numéro et la langue choisis. Rien ici ne
 * connaît wa.me.
 */
final class MessagePrepare
{
    /**
     * @param  list<array<string, mixed>>  $destinataires
     * @param  list<array<string, string|null>>  $ignores
     * @param  array{fr: string, ar: string}  $messages
     * @param  array<string, mixed>  $contexte
     */
    public function __construct(
        public readonly string $type,
        public readonly DocumentVente $document,
        public readonly ?Paiement $paiement,
        public readonly ?int $niveau,
        public readonly array $destinataires,
        public readonly array $ignores,
        public readonly array $messages,
        public readonly string $lien,
        public readonly Carbon $lienExpireLe,
        public readonly array $contexte,
    ) {}

    /** Le numéro présélectionné, ou null s'il n'y en a aucun d'exploitable. */
    public function numeroSelectionne(): ?string
    {
        foreach ($this->destinataires as $destinataire) {
            if ($destinataire['selectionne']) {
                return $destinataire['numero'];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'document' => $this->contexte['document'],
            'paiement_id' => $this->paiement?->id,
            'niveau' => $this->niveau,
            'niveau_label' => $this->contexte['niveau_label'] ?? null,
            'niveaux' => $this->contexte['niveaux'] ?? null,
            'destinataires' => $this->destinataires,
            'numeros_ignores' => $this->ignores,
            'numero_selectionne' => $this->numeroSelectionne(),
            'messages' => $this->messages,
            'lien_document' => [
                'url' => $this->lien,
                'expire_le' => $this->lienExpireLe->toIso8601String(),
            ],
        ];
    }
}
