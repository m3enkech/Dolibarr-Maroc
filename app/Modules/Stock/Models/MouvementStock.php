<?php

namespace App\Modules\Stock\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Catalogue\Models\Produit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'produit_id', 'entrepot_id', 'document_vente_id', 'user_id',
    'type', 'quantite', 'quantite_apres', 'reference', 'note',
])]
class MouvementStock extends Model
{
    use BelongsToTenant;

    public const TYPE_ENTREE = 'entree';
    public const TYPE_SORTIE = 'sortie';
    public const TYPE_AJUSTEMENT = 'ajustement';
    public const TYPE_VENTE = 'vente';
    public const TYPE_ACHAT = 'achat';
    public const TYPE_TRANSFERT = 'transfert';
    public const TYPE_RETOUR = 'retour';

    protected $table = 'mouvements_stock';

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'quantite_apres' => 'decimal:3',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function entrepot(): BelongsTo
    {
        return $this->belongsTo(Entrepot::class);
    }
}
