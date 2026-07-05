<?php

namespace App\Modules\Achats\Models;

use App\Modules\Catalogue\Models\Produit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'produit_id', 'source_ligne_id', 'designation', 'quantite', 'quantite_recue',
    'prix_unitaire', 'remise_percent', 'tva_rate',
    'montant_ht', 'montant_tva', 'montant_ttc', 'position',
])]
class DocumentAchatLigne extends Model
{
    protected $table = 'document_achat_lignes';

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'quantite_recue' => 'decimal:3',
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
        return $this->belongsTo(DocumentAchat::class, 'document_achat_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function sourceLigne(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_ligne_id');
    }

    public function resteARecevoir(): float
    {
        return round((float) $this->quantite - (float) $this->quantite_recue, 3);
    }
}
