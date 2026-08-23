<?php

namespace App\Modules\Catalogue\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conditionnement d'un article (carton, palette…). `quantite_base` exprime le
 * colis en unité de stock : un « Carton de 12 » vaut 12.
 */
#[Fillable(['produit_id', 'nom', 'quantite_base', 'barcode', 'is_default'])]
class ProduitConditionnement extends Model
{
    use BelongsToTenant;

    protected $table = 'produit_conditionnements';

    protected function casts(): array
    {
        return [
            'quantite_base' => 'decimal:3',
            'is_default' => 'boolean',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /** Libellé de vente : « 5 × Carton de 12 (60 pièce) ». */
    public function libelle(float $nombreColis, ?string $unite = null): string
    {
        return sprintf(
            '%s × %s (%s %s)',
            rtrim(rtrim(number_format($nombreColis, 3, ',', ' '), '0'), ','),
            $this->nom,
            rtrim(rtrim(number_format($nombreColis * (float) $this->quantite_base, 3, ',', ' '), '0'), ','),
            $unite ?: 'unité',
        );
    }
}
