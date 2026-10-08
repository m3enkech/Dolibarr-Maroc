<?php

namespace App\Modules\Tiers\Models;

use App\Core\Auth\Roles;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un commentaire du fil d'un tiers. Texte BRUT : rien n'y est interprété, ni
 * à l'écriture ni à l'affichage — l'écran le rend comme du texte, avec ses
 * retours à la ligne.
 */
#[Fillable(['tiers_id', 'user_id', 'auteur_nom', 'contenu'])]
class Commentaire extends Model
{
    use BelongsToTenant, SoftDeletes;

    /**
     * Assez pour un compte rendu d'appel, pas pour y coller un contrat : le
     * fil se lit d'un coup d'œil, et une note de dix pages n'y serait plus lue.
     */
    public const LONGUEUR_MAX = 2000;

    protected $table = 'tiers_commentaires';

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Le nom à afficher : celui de l'auteur aujourd'hui, ou la copie prise à l'écriture s'il a quitté l'équipe. */
    public function nomAuteur(): string
    {
        return $this->auteur?->name ?? $this->auteur_nom;
    }

    /**
     * Supprimer : l'AUTEUR, ou l'ADMINISTRATEUR de l'entreprise — pas un
     * collègue, même manager. Un commentaire est la parole de quelqu'un ; si
     * un pair pouvait l'effacer, le fil ne prouverait plus rien (« je n'ai
     * jamais promis cette remise »). L'administrateur reste l'arbitre : une
     * note injurieuse, une donnée personnelle collée par erreur — d'où un
     * texte réellement EFFACÉ à la suppression, et son nom gardé à la place
     * (CommentairesController::destroy). Le superadmin passe, comme partout
     * (User::hasPermission).
     *
     * Le droit d'écrire sur les tiers est exigé aussi, ici et par la route
     * (`permission:tiers` en écriture sur DELETE) : un auteur repassé en
     * lecture seule ne supprime plus. Le répéter ici sert l'écran, qui lit
     * `peut_supprimer` pour proposer — ou non — le bouton.
     */
    public function supprimablePar(User $utilisateur): bool
    {
        if (! $utilisateur->hasPermission('tiers', Roles::WRITE)) {
            return false;
        }

        return $utilisateur->isAdmin()
            || $utilisateur->isSuperadmin()
            || ($this->user_id !== null && (int) $this->user_id === (int) $utilisateur->id);
    }
}
