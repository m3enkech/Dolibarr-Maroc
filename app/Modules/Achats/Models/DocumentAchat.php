<?php

namespace App\Modules\Achats\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Stock\Models\Entrepot;
use App\Modules\Tiers\Models\Tiers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'type', 'code', 'statut', 'tiers_id', 'source_document_id', 'entrepot_id',
    'ref_fournisseur', 'date_document', 'date_echeance',
    'total_ht', 'total_tva', 'total_ttc', 'notes', 'validated_at',
])]
class DocumentAchat extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TYPE_COMMANDE = 'commande';
    public const TYPE_RECEPTION = 'reception';
    public const TYPE_FACTURE = 'facture';
    public const TYPES = [self::TYPE_COMMANDE, self::TYPE_RECEPTION, self::TYPE_FACTURE];

    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_VALIDE = 'valide';
    public const STATUT_RECUE_PARTIELLE = 'recue_partielle';
    public const STATUT_RECUE = 'recue';
    public const STATUT_PAYE = 'paye';

    protected $table = 'documents_achat';

    protected function casts(): array
    {
        return [
            'date_document' => 'date:Y-m-d',
            'date_echeance' => 'date:Y-m-d',
            'total_ht' => 'decimal:2',
            'total_tva' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'validated_at' => 'datetime',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    public function entrepot(): BelongsTo
    {
        return $this->belongsTo(Entrepot::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DocumentAchatLigne::class)->orderBy('position');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(PaiementFournisseur::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_document_id');
    }

    public function isBrouillon(): bool
    {
        return $this->statut === self::STATUT_BROUILLON;
    }

    public function montantPaye(): float
    {
        return (float) $this->paiements()->sum('montant');
    }

    public function resteAPayer(): float
    {
        return round((float) $this->total_ttc - $this->montantPaye(), 2);
    }
}
