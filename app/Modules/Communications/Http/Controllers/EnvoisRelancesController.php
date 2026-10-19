<?php

namespace App\Modules\Communications\Http\Controllers;

use App\Core\Auth\Roles;
use App\Http\Controllers\Controller;
use App\Modules\Communications\Http\Requests\EnregistrerEnvoiRelanceRequest;
use App\Modules\Communications\Models\Communication;
use App\Modules\Communications\Services\JournalEnvois;
use App\Modules\Communications\Services\PreparationMessage;
use App\Modules\Communications\Transport\ClickToChat;
use App\Modules\Relances\Models\Relance;
use App\Modules\Relances\Services\RelanceService;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Relance d'impayé par WhatsApp — sous `permission:relances`, et seulement si
 * l'entreprise a le module Relances, comme le reste du recouvrement.
 */
class EnvoisRelancesController extends Controller
{
    public function __construct(
        private PreparationMessage $preparation,
        private JournalEnvois $journal,
    ) {}

    /**
     * Sans niveau demandé, celui qui suit la dernière relance.
     *
     * L'écriture est exigée comme pour les ventes (voir
     * EnvoisVentesController::preparer) : le lien signé du PDF est un accès au
     * porteur, et seul qui peut relancer en a l'usage.
     */
    public function preparer(Request $request, DocumentVente $document): JsonResponse
    {
        $this->assertFeature($request);

        abort_unless(
            $request->user()->hasPermission('relances', Roles::WRITE),
            403,
            __("Vous n'avez pas les droits nécessaires sur ce module."),
        );

        $data = $request->validate([
            'niveau' => ['nullable', 'integer', Rule::in(array_keys(Relance::NIVEAUX))],
        ]);

        $message = $this->preparation->preparer(
            Communication::TYPE_RELANCE,
            $document,
            null,
            isset($data['niveau']) ? (int) $data['niveau'] : null,
        );

        return response()->json([
            'data' => $message->toArray() + ['liens_whatsapp' => ClickToChat::liens($message)],
        ]);
    }

    /**
     * Journalise la relance : la ligne du journal ET la Relance de
     * l'historique (canal `whatsapp`), dans UNE transaction. L'une sans
     * l'autre mentirait — une relance comptée sans trace du message, ou un
     * message envoyé que la liste « à relancer » ignorerait au tour suivant.
     *
     * La clé du geste rend l'appel rejouable : « Réessayer l'enregistrement »
     * après une réponse perdue retrouve la relance déjà écrite au lieu d'en
     * compter une seconde. Elle se cherche AVANT les règles : la relance a eu
     * lieu, même si la facture a été réglée depuis.
     */
    public function enregistrer(EnregistrerEnvoiRelanceRequest $request, DocumentVente $document, RelanceService $relances): JsonResponse
    {
        $this->assertFeature($request);

        $cle = $request->cle();

        if (($deja = $this->journal->retrouver($cle, Communication::TYPE_RELANCE, $document)) !== null) {
            return $this->reponse($deja, 200);
        }

        $niveau = (int) $request->validated('niveau');
        $this->preparation->verifier(Communication::TYPE_RELANCE, $document, null, $niveau);

        try {
            $communication = DB::transaction(function () use ($relances, $request, $document, $niveau, $cle) {
                $relance = $relances->enregistrer($document, $niveau, Relance::CANAL_WHATSAPP, $request->validated('note'));

                return $this->journal->enregistrer(
                    Communication::TYPE_RELANCE, $document, null, $niveau, $request->numero(),
                    $request->validated('langue'), $request->validated('message'), $request->user(), $relance->id, $cle,
                );
            });
        } catch (UniqueConstraintViolationException $e) {
            // Deux appels du même geste arrivés ensemble : la transaction du
            // second est annulée, sa Relance avec elle — reste celle du premier.
            return $this->reponse(
                $this->journal->retrouver($cle, Communication::TYPE_RELANCE, $document) ?? throw $e,
                200,
            );
        }

        return $this->reponse($communication, 201);
    }

    public function lister(Request $request, DocumentVente $document): JsonResponse
    {
        $this->assertFeature($request);

        return response()->json([
            'data' => $this->journal->lister($document, [Communication::TYPE_RELANCE]),
        ]);
    }

    /** La ligne du journal, le lien journalisé, et la Relance qu'elle a créée. */
    private function reponse(Communication $communication, int $statut): JsonResponse
    {
        $relance = $communication->relance_id !== null ? Relance::query()->find($communication->relance_id) : null;

        return response()->json([
            'data' => $this->journal->presenter($communication) + [
                'lien_whatsapp' => ClickToChat::lien($communication->destinataire, $communication->message),
                'relance' => $relance !== null ? [
                    'id' => $relance->id,
                    'niveau' => $relance->niveau,
                    'niveau_label' => Relance::NIVEAUX[$relance->niveau] ?? (string) $relance->niveau,
                    'canal' => $relance->canal,
                ] : null,
            ],
        ], $statut);
    }

    /** Même verrou que les autres routes du recouvrement (RelancesController). */
    private function assertFeature(Request $request): void
    {
        abort_unless(
            $request->user()->tenant->hasFeature('relances'),
            403,
            __('Le module Relances est désactivé dans les paramètres.'),
        );
    }
}
