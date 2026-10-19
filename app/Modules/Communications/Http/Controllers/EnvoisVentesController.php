<?php

namespace App\Modules\Communications\Http\Controllers;

use App\Core\Auth\Roles;
use App\Http\Controllers\Controller;
use App\Modules\Communications\Http\Requests\EnregistrerEnvoiVenteRequest;
use App\Modules\Communications\Models\Communication;
use App\Modules\Communications\Services\JournalEnvois;
use App\Modules\Communications\Services\PreparationMessage;
use App\Modules\Communications\Transport\ClickToChat;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Envoi par WhatsApp d'une facture, d'un devis, ou de la confirmation d'un
 * paiement — sous `permission:ventes` : lecture pour lister, ÉCRITURE pour
 * préparer et journaliser.
 *
 * Le document arrive par la résolution automatique de la route, donc DANS le
 * périmètre de l'entreprise : celui d'une autre répond 404 avant d'arriver ici.
 */
class EnvoisVentesController extends Controller
{
    public function __construct(
        private PreparationMessage $preparation,
        private JournalEnvois $journal,
    ) {}

    /**
     * Le message prêt : numéros proposés, textes FR et AR, lien du PDF.
     *
     * Rien n'est écrit, mais le lien signé est un accès AU PORTEUR de trente
     * jours, que rien ne trace ni ne révoque : il survivrait au départ de qui
     * l'a obtenu, et montrerait la pièce À JOUR, pas l'état téléchargé ce
     * jour-là. Seul qui peut envoyer — donc journaliser — en a l'usage. La
     * route garde UNE permission (lecture, puisque GET) ; le niveau écriture
     * se vérifie ici.
     */
    public function preparer(Request $request, DocumentVente $document): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('ventes', Roles::WRITE),
            403,
            __("Vous n'avez pas les droits nécessaires sur ce module."),
        );

        $data = $request->validate([
            'type' => ['required', Rule::in(Communication::TYPES_VENTES)],
            'paiement_id' => ['nullable', 'integer'],
        ]);

        $paiement = $data['type'] === Communication::TYPE_PAIEMENT
            ? $this->preparation->paiementDe($document, $data['paiement_id'] ?? null)
            : null;

        $message = $this->preparation->preparer($data['type'], $document, $paiement);

        return response()->json([
            'data' => $message->toArray() + ['liens_whatsapp' => ClickToChat::liens($message)],
        ]);
    }

    /**
     * Journalise l'ouverture de WhatsApp avec le message final.
     *
     * Les règles de la préparation sont rejouées : un appel direct ne doit pas
     * pouvoir journaliser ce que la préparation aurait refusé (un brouillon,
     * le paiement d'une autre pièce).
     *
     * Un second appel portant la clé d'un geste déjà journalisé renvoie la
     * ligne existante (200) — AVANT de rejouer les règles : l'envoi a eu lieu,
     * même si la pièce a changé depuis.
     */
    public function enregistrer(EnregistrerEnvoiVenteRequest $request, DocumentVente $document): JsonResponse
    {
        $type = $request->validated('type');
        $cle = $request->cle();

        if (($deja = $this->journal->retrouver($cle, $type, $document)) !== null) {
            return $this->reponse($deja, 200);
        }

        $paiement = $type === Communication::TYPE_PAIEMENT
            ? $this->preparation->paiementDe($document, $request->validated('paiement_id'))
            : null;

        $this->preparation->verifier($type, $document, $paiement);

        try {
            // Une transaction pour son point de sauvegarde : sur PostgreSQL,
            // une insertion refusée par l'index unique invaliderait sinon la
            // transaction englobante, s'il y en a une.
            $communication = DB::transaction(fn () => $this->journal->enregistrer(
                $type, $document, $paiement, null, $request->numero(), $request->validated('langue'),
                $request->validated('message'), $request->user(), null, $cle,
            ));
        } catch (UniqueConstraintViolationException $e) {
            // Deux appels du même geste arrivés ensemble : le premier a écrit.
            return $this->reponse($this->journal->retrouver($cle, $type, $document) ?? throw $e, 200);
        }

        return $this->reponse($communication, 201);
    }

    /**
     * Les envois du document. Les relances n'y figurent que pour qui a le
     * droit de les lire et l'entreprise qui a le module : c'est le contrôleur
     * qui filtre selon les droits, la route garde UNE seule permission.
     */
    public function lister(Request $request, DocumentVente $document): JsonResponse
    {
        $types = Communication::TYPES_VENTES;

        $user = $request->user();
        if ($user->hasPermission('relances', Roles::READ) && $user->tenant?->hasFeature('relances')) {
            $types[] = Communication::TYPE_RELANCE;
        }

        return response()->json(['data' => $this->journal->lister($document, $types)]);
    }

    /** La ligne du journal, et le lien tel qu'il a été journalisé. */
    private function reponse(Communication $communication, int $statut): JsonResponse
    {
        return response()->json([
            'data' => $this->journal->presenter($communication)
                + ['lien_whatsapp' => ClickToChat::lien($communication->destinataire, $communication->message)],
        ], $statut);
    }
}
