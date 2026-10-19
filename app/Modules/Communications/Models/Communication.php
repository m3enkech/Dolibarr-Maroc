<?php

namespace App\Modules\Communications\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un message préparé pour un client et remis à un canal d'envoi.
 *
 * Avec le canal `whatsapp` (lien click-to-chat), la ligne atteste l'OUVERTURE
 * de WhatsApp avec le message prêt, pas sa remise : voir la migration.
 */
#[Fillable([
    'canal', 'type', 'document_vente_id', 'paiement_id', 'relance_id', 'tiers_id',
    'user_id', 'auteur_nom', 'niveau', 'destinataire', 'langue', 'message', 'cle',
])]
class Communication extends Model
{
    use BelongsToTenant;

    /** Une ligne de journal ne se modifie pas. */
    public const UPDATED_AT = null;

    /** WhatsApp ouvert par l'utilisateur, message prêt (wa.me). */
    public const CANAL_WHATSAPP = 'whatsapp';

    /**
     * Ce que le serveur sait d'un envoi click-to-chat : WhatsApp a été ouvert
     * avec le message. Rien de plus — pas « remis », encore moins « lu ».
     */
    public const ETAT_WHATSAPP_OUVERT = 'whatsapp_ouvert';

    public const TYPE_RELANCE = 'relance';

    public const TYPE_FACTURE = 'facture';

    public const TYPE_DEVIS = 'devis';

    public const TYPE_PAIEMENT = 'paiement';

    public const TYPES = [self::TYPE_RELANCE, self::TYPE_FACTURE, self::TYPE_DEVIS, self::TYPE_PAIEMENT];

    /**
     * Les messages qui relèvent des ventes (`permission:ventes`). La relance
     * relève du recouvrement (`permission:relances`) : un rôle qui n'a pas ce
     * droit ne la prépare pas, ne la journalise pas, et ne la lit pas.
     */
    public const TYPES_VENTES = [self::TYPE_FACTURE, self::TYPE_DEVIS, self::TYPE_PAIEMENT];

    public const LANGUES = ['fr', 'ar'];

    protected $table = 'communications';

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

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
