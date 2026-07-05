<?php

namespace App\Modules\Catalogue\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Compta\Models\Compte;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'compte_vente_id', 'compte_achat_id',
    'is_immobilisation', 'compte_amortissement_id', 'duree_amortissement',
])]
class CategorieProduit extends Model
{
    use BelongsToTenant;

    protected $table = 'categories_produit';

    protected function casts(): array
    {
        return [
            'is_immobilisation' => 'boolean',
            'duree_amortissement' => 'integer',
        ];
    }

    public function compteVente(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_vente_id');
    }

    public function compteAchat(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_achat_id');
    }

    public function compteAmortissement(): BelongsTo
    {
        return $this->belongsTo(Compte::class, 'compte_amortissement_id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }
}
