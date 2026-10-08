<?php

namespace App\Modules\Tiers\Http\Resources;

use App\Modules\Tiers\Models\Commentaire;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Commentaire */
class CommentaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Tel qu'il a été saisi : c'est l'écran qui le rend comme du
            // texte. Le nettoyer ici (strip_tags…) mutilerait des notes
            // légitimes — « prix < 100 DH & livraison > 3 jours ».
            'contenu' => $this->contenu,
            'auteur' => [
                'id' => $this->user_id,
                'nom' => $this->nomAuteur(),
            ],
            'created_at' => $this->created_at,
            // Calculé ici, avec la règle du serveur, pour que l'écran ne
            // propose « Supprimer » qu'à qui le mènera au bout.
            'peut_supprimer' => $request->user() !== null && $this->resource->supprimablePar($request->user()),
        ];
    }
}
