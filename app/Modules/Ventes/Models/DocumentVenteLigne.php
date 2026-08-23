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
    'produit_id', 'conditionnement_id', 'quantite_colis', 'source_ligne_id',
    'designation', 'quantite', 'quantite_livree', 'prix_unitaire',
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
            'quantite_livree' => 'decimal:3',
            'quantite_colis' => 'decimal:3',
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

    public function conditionnement(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Catalogue\Models\ProduitConditionnement::class, 'conditionnement_id');
    }

    /** Ligne de commande dont cette ligne de bon de livraison est issue. */
    public function sourceLigne(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_ligne_id');
    }

    /** Quantité commandée qui reste à livrer (reliquat). */
    public function resteALivrer(): float
    {
        return round((float) $this->quantite - (float) $this->quantite_livree, 3);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }
}
