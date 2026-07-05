<?php

namespace App\Modules\Compta\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'journal', 'numero', 'date_ecriture', 'libelle', 'reference',
    'document_vente_id', 'is_auto',
])]
class Ecriture extends Model
{
    use BelongsToTenant;

    public const JOURNAL_VENTES = 'VT';
    public const JOURNAL_ACHATS = 'AC';
    public const JOURNAL_TRESORERIE = 'BQ';
    public const JOURNAL_DIVERS = 'OD';
    public const JOURNAL_A_NOUVEAUX = 'AN';
    public const JOURNAUX = [self::JOURNAL_VENTES, self::JOURNAL_ACHATS, self::JOURNAL_TRESORERIE, self::JOURNAL_DIVERS, self::JOURNAL_A_NOUVEAUX];

    protected function casts(): array
    {
        return [
            'date_ecriture' => 'date:Y-m-d',
            'is_auto' => 'boolean',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(EcritureLigne::class);
    }
}
