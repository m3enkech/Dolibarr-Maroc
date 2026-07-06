<?php

namespace App\Modules\Effets\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Achats\Models\DocumentAchat;
use App\Modules\Tiers\Models\Tiers;
use App\Modules\Ventes\Models\DocumentVente;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tiers_id', 'document_vente_id', 'document_achat_id', 'type', 'code',
    'montant', 'date_creation', 'date_echeance', 'statut', 'lettrage_code', 'regle_at',
])]
class Effet extends Model
{
    use BelongsToTenant;

    public const TYPE_RECEVOIR = 'recevoir';
    public const TYPE_PAYER = 'payer';

    public const STATUT_PORTEFEUILLE = 'portefeuille';
    public const STATUT_ENCAISSE = 'encaisse';
    public const STATUT_PAYE = 'paye';
    public const STATUT_IMPAYE = 'impaye';

    /** Un effet actif « couvre » sa facture (elle n'est plus à relancer). */
    public const STATUTS_ACTIFS = [self::STATUT_PORTEFEUILLE, self::STATUT_ENCAISSE, self::STATUT_PAYE];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'date_creation' => 'date:Y-m-d',
            'date_echeance' => 'date:Y-m-d',
            'regle_at' => 'datetime',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function documentVente(): BelongsTo
    {
        return $this->belongsTo(DocumentVente::class);
    }

    public function documentAchat(): BelongsTo
    {
        return $this->belongsTo(DocumentAchat::class);
    }

    public function isRecevoir(): bool
    {
        return $this->type === self::TYPE_RECEVOIR;
    }

    public function isPortefeuille(): bool
    {
        return $this->statut === self::STATUT_PORTEFEUILLE;
    }
}
