<?php

namespace App\Modules\Communications\Services;

use App\Core\Format\NumeroWhatsApp;
use App\Models\User;
use App\Modules\Communications\Models\Communication;
use App\Modules\Ventes\Models\DocumentVente;
use App\Modules\Ventes\Models\Paiement;
use Illuminate\Validation\ValidationException;

/**
 * Le journal des messages remis à WhatsApp.
 *
 * Le navigateur l'appelle AU MOMENT où il ouvre WhatsApp : l'envoi lui-même
 * se fait ensuite sur le téléphone de l'utilisateur, hors de portée du
 * serveur. Chaque ligne dit donc « WhatsApp ouvert, message prêt », jamais
 * « message remis » — et l'API le dit en toutes lettres (`etat_libelle`).
 *
 * L'auteur est passé EN PARAMÈTRE plutôt que lu dans la session : le jour où
 * l'API WhatsApp Business enverra depuis une tâche de fond, il n'y aura plus
 * d'utilisateur connecté à qui le demander.
 */
class JournalEnvois
{
    /** Au-delà, la fiche d'un document deviendrait illisible ; personne n'en est là. */
    private const LIMITE_LISTE = 100;

    public function enregistrer(
        string $type,
        DocumentVente $document,
        ?Paiement $paiement,
        ?int $niveau,
        ?NumeroWhatsApp $numero,
        string $langue,
        string $message,
        ?User $auteur,
        ?int $relanceId = null,
        ?string $cle = null,
    ): Communication {
        return Communication::create([
            'canal' => Communication::CANAL_WHATSAPP,
            'type' => $type,
            'document_vente_id' => $document->id,
            'paiement_id' => $paiement?->id,
            'relance_id' => $relanceId,
            'tiers_id' => $document->tiers_id,
            'user_id' => $auteur?->id,
            'auteur_nom' => $auteur?->name,
            'niveau' => $niveau,
            'destinataire' => $numero?->numero,
            'langue' => $langue,
            'message' => $message,
            'cle' => $cle,
        ]);
    }

    /**
     * La ligne déjà écrite pour ce geste, s'il y en a une.
     *
     * Une clé ne vaut que pour le message qui l'a tirée : retrouvée sur une
     * autre pièce ou un autre type, c'est un appel incohérent, et le rejouer
     * renverrait le journal d'un autre envoi. Le scope d'entreprise borne la
     * recherche : la clé d'une autre entreprise n'existe pas ici.
     */
    public function retrouver(?string $cle, string $type, DocumentVente $document): ?Communication
    {
        if ($cle === null) {
            return null;
        }

        $deja = Communication::query()->where('cle', $cle)->first();

        if ($deja !== null && ((int) $deja->document_vente_id !== (int) $document->id || $deja->type !== $type)) {
            throw ValidationException::withMessages([
                'cle' => __('Cette clé d\'envoi a déjà servi pour un autre message.'),
            ]);
        }

        return $deja;
    }

    /**
     * Les envois d'un document, du plus récent au plus ancien, limités aux
     * types demandés : chaque route ne montre que ce que sa permission couvre.
     *
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    public function lister(DocumentVente $document, array $types): array
    {
        return Communication::query()
            ->where('document_vente_id', $document->id)
            ->whereIn('type', $types)
            ->with('auteur:id,name')
            ->latest('id')
            ->limit(self::LIMITE_LISTE)
            ->get()
            ->map(fn (Communication $c) => $this->presenter($c))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function presenter(Communication $c): array
    {
        $numero = $c->destinataire !== null ? NumeroWhatsApp::depuis('+'.$c->destinataire) : null;

        return [
            'id' => $c->id,
            'canal' => $c->canal,
            'type' => $c->type,
            'niveau' => $c->niveau,
            'document_vente_id' => $c->document_vente_id,
            'paiement_id' => $c->paiement_id,
            'relance_id' => $c->relance_id,
            'tiers_id' => $c->tiers_id,
            'destinataire' => $c->destinataire,
            'destinataire_affichage' => $numero?->affichage(),
            'langue' => $c->langue,
            'message' => $c->message,
            // Le nom actuel tant que la personne fait partie de l'équipe ; la
            // copie prise à l'écriture une fois qu'elle l'a quittée.
            'auteur' => [
                'id' => $c->user_id,
                'nom' => $c->auteur?->name ?? $c->auteur_nom,
            ],
            'etat' => Communication::ETAT_WHATSAPP_OUVERT,
            'etat_libelle' => __('WhatsApp ouvert avec le message prêt — envoi non confirmé'),
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
