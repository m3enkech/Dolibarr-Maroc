<?php

namespace App\Modules\Tiers\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tiers\Http\Resources\CommentaireResource;
use App\Modules\Tiers\Models\Commentaire;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Le fil de commentaires d'un tiers.
 *
 * UNE garde, celle des routes du module : `permission:tiers`, en lecture pour
 * lire le fil, en écriture pour y ajouter ou en retirer. Ni le CRM ni aucun
 * autre module : un caissier qui lit les tiers lit aussi ce que l'équipe en
 * dit — « paie toujours en espèces », c'est à lui que ça sert.
 */
class CommentairesController extends Controller
{
    /** Une page du fil : de quoi remplir l'onglet, le reste à la demande. */
    private const PAR_PAGE_DEFAUT = 20;

    private const PAR_PAGE_MAX = 50;

    /**
     * Le plus récent d'abord, page par page au CURSEUR (l'id du dernier lu),
     * pas au numéro de page : le fil grandit par le haut, et un commentaire
     * publié par un collègue entre deux « plus anciens » décalait une page
     * numérotée d'un cran — le même commentaire revenait deux fois.
     */
    public function index(Request $request, Tiers $tiers): AnonymousResourceCollection
    {
        $parPage = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::PAR_PAGE_MAX],
        ])['per_page'] ?? self::PAR_PAGE_DEFAUT;

        $commentaires = $tiers->commentaires()
            ->with('auteur:id,name')
            ->orderByDesc('id')
            ->cursorPaginate((int) $parPage);

        return CommentaireResource::collection($commentaires);
    }

    public function store(Request $request, Tiers $tiers): JsonResponse
    {
        // Un seul retour à la ligne, « \n » : la longueur comptée ici est
        // alors celle que l'écran a comptée, et le texte se relit pareil sur
        // tous les postes.
        if (is_string($request->input('contenu'))) {
            $request->merge(['contenu' => str_replace(["\r\n", "\r"], "\n", $request->input('contenu'))]);
        }

        // `required` refuse aussi un commentaire fait d'espaces : le
        // middleware TrimStrings les a déjà réduits à rien.
        $data = $request->validate([
            'contenu' => ['required', 'string', 'max:'.Commentaire::LONGUEUR_MAX],
        ], [
            'contenu.required' => __('Le commentaire est vide.'),
            'contenu.max' => __('Le commentaire dépasse :max caractères.'),
        ]);

        $auteur = $request->user();

        $commentaire = $tiers->commentaires()->create([
            'user_id' => $auteur->id,
            'auteur_nom' => $auteur->name,
            'contenu' => $data['contenu'],
        ]);

        $commentaire->setRelation('auteur', $auteur);

        return response()->json(['data' => new CommentaireResource($commentaire)], 201);
    }

    /**
     * Retirer un commentaire : son auteur ou l'administrateur — la règle et
     * sa raison sont dans Commentaire::supprimablePar. Le TEXTE est effacé,
     * la trace reste (qui, quand, supprimé par qui) : voir la migration.
     */
    public function destroy(Request $request, Tiers $tiers, Commentaire $commentaire): JsonResponse
    {
        // Le tiers de l'URL doit être celui du commentaire : sans ce contrôle,
        // /tiers/1/commentaires/42 retirerait un commentaire du tiers 2.
        abort_unless((int) $commentaire->tiers_id === (int) $tiers->id, 404);

        $utilisateur = $request->user();

        abort_unless(
            $commentaire->supprimablePar($utilisateur),
            403,
            __("Seuls l'auteur d'un commentaire et l'administrateur peuvent le supprimer."),
        );

        // Deux temps dans UNE transaction : la suppression douce n'écrit que
        // `deleted_at` (SoftDeletes::runSoftDelete), le contenu vidé et la
        // signature doivent donc être enregistrés avant elle.
        DB::transaction(function () use ($commentaire, $utilisateur) {
            $commentaire->forceFill([
                'contenu' => '',
                'supprime_par_id' => $utilisateur->id,
                'supprime_par_nom' => $utilisateur->name,
            ])->save();

            $commentaire->delete();
        });

        return response()->json(null, 204);
    }
}
