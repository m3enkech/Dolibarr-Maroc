<?php

namespace App\Modules\Relances\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_vente_id', 'user_id', 'niveau', 'canal', 'note'])]
class Relance extends Model
{
    use BelongsToTenant;

    public const NIVEAUX = [
        1 => 'Rappel',
        2 => 'Relance ferme',
        3 => 'Mise en demeure',
    ];

    public const CANAL_WHATSAPP = 'whatsapp';

    /**
     * Les canaux d'une relance, en UN seul endroit : la validation, la
     * relance par WhatsApp et l'historique lisent cette liste, pour qu'un
     * canal ajouté ici soit accepté et affiché partout à la fois.
     *
     * `whatsapp` veut dire « WhatsApp ouvert avec le message prêt » : l'envoi
     * se fait sur le téléphone de l'utilisateur, le serveur ne le voit pas.
     */
    public const CANAUX = [
        'courrier' => 'Courrier',
        'email' => 'E-mail',
        'telephone' => 'Téléphone',
        self::CANAL_WHATSAPP => 'WhatsApp',
        'autre' => 'Autre',
    ];

    protected function casts(): array
    {
        return [
            'niveau' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentVente::class, 'document_vente_id');
    }
}
