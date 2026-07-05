<?php

namespace App\Modules\Ventes\Models;

use App\Modules\Catalogue\Models\Produit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne de document : pas de tenant_id propre — toujours accédée via son
 * document parent (jamais d'endpoint direct sur les lignes).
 */
#[Fillable([
    'produit_id', 'designation', 'quantite', 'prix_unitaire',
    'remise_percent', 'tva_rate', 'montant_ht', 'montant_tva', 'montant_ttc',
    'position',
])]
class DocumentVenteLigne extends Model
{
    protected $table = 'document_vente_lignes';

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'prix_unitaire' => 'decimal:2',
            'remise_percent' => 'decimal:2',
            'tva_rate' => 'decimal:2',
            'montant_ht' => 'decimal:2',
            'montant_tva' => 'decimal:2',
            'montant_ttc' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentVente::class, 'document_vente_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
