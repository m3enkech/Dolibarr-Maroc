<?php

namespace App\Modules\Compta\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Relevé bancaire importé, rapproché avec les écritures du compte de trésorerie.
 */
#[Fillable([
    'compte_id', 'libelle', 'date_debut', 'date_fin',
    'solde_initial', 'solde_final', 'statut',
])]
class BankStatement extends Model
{
    use BelongsToTenant;

    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_CLOTURE = 'cloture';

    protected function casts(): array
    {
        return [
            'date_debut' => 'date:Y-m-d',
            'date_fin' => 'date:Y-m-d',
            'solde_initial' => 'decimal:2',
            'solde_final' => 'decimal:2',
        ];
    }

    public function compte(): BelongsTo
    {
        return $this->belongsTo(Compte::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('date_operation')->orderBy('id');
    }
}
