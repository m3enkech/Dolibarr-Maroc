<?php

namespace App\Modules\Compta\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de relevé bancaire. Accédée via son relevé parent (scope tenant via
 * BankStatement). `montant` signé côté titulaire : + = entrée, − = sortie.
 */
#[Fillable([
    'bank_statement_id', 'date_operation', 'libelle', 'reference',
    'montant', 'ecriture_ligne_id', 'rapproche_at',
])]
class BankStatementLine extends Model
{
    protected function casts(): array
    {
        return [
            'date_operation' => 'date:Y-m-d',
            'montant' => 'decimal:2',
            'rapproche_at' => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function ecritureLigne(): BelongsTo
    {
        return $this->belongsTo(EcritureLigne::class);
    }

    public function estRapprochee(): bool
    {
        return $this->ecriture_ligne_id !== null;
    }
}
