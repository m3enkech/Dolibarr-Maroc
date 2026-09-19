<?php

namespace App\Modules\Tiers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Un interlocuteur chez un client ou un fournisseur. */
#[Fillable([
    'tiers_id', 'nom', 'fonction', 'email', 'phone', 'mobile',
    'notes', 'is_principal', 'is_active',
])]
class Contact extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_principal' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tiers(): BelongsTo
    {
        return $this->belongsTo(Tiers::class);
    }

    /** Ce qu'on affiche en une ligne : « Alami Karim — Directeur achats ». */
    public function libelle(): string
    {
        return $this->fonction ? "{$this->nom} — {$this->fonction}" : $this->nom;
    }
}
