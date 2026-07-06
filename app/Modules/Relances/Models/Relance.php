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
