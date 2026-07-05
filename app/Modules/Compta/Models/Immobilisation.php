<?php

namespace App\Modules\Compta\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'document_achat_id', 'label', 'category', 'date_acquisition', 'valeur_acquisition',
    'duree_annees', 'compte_immo_id', 'compte_amort_id',
    'statut', 'date_cession', 'valeur_cession', 'tva_cession', 'notes',
])]
class Immobilisation extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUT_EN_SERVICE = 'en_service';
    public const STATUT_CEDE = 'cede';

    protected function casts(): array
    {
        return [
            'date_acquisition' => 'date:Y-m-d',
            'date_cession' => 'date:Y-m-d',
            'valeur_acquisition' => 'decimal:2',
            'valeur_cession' => 'decimal:2',
            'tva_cession' => 'decimal:2',
            'duree_annees' => 'integer',
        ];
    }

    public function documentAchat(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Achats\Models\DocumentAchat::class, 'document_achat_id');
    }

    public function compteImmo(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_immo_id');
    }

    public function compteAmort(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_amort_id');
    }

    public function dotations(): HasMany
    {
        return $this->hasMany(ImmobilisationDotation::class);
    }

    /** Cumul des dotations réellement comptabilisées. */
    public function cumulAmortissement(): float
    {
        return round((float) $this->dotations()->sum('montant'), 2);
    }

    /** Valeur nette d'amortissement = valeur brute − cumul comptabilisé. */
    public function vna(): float
    {
        return round((float) $this->valeur_acquisition - $this->cumulAmortissement(), 2);
    }
}
