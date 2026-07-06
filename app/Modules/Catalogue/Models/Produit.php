<?php

namespace App\Modules\Catalogue\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'description', 'type', 'categorie_produit_id',
    'sell_price', 'buy_price', 'tva_rate',
    'unit', 'stock_min', 'stock_reappro', 'barcode', 'is_active',
])]
class Produit extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TVA_RATES = [0, 7, 10, 14, 20];

    protected $table = 'produits';

    public function categorieProduit(): BelongsTo
    {
        return $this->belongsTo(CategorieProduit::class);
    }

    protected function casts(): array
    {
        return [
            'sell_price' => 'decimal:2',
            'buy_price' => 'decimal:2',
            'tva_rate' => 'decimal:2',
            'stock_min' => 'decimal:3',
            'stock_reappro' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** Prix TTC calculé, formaté comme les casts decimal:2 ("1200.00"). */
    protected function sellPriceTtc(): Attribute
    {
        return Attribute::get(
            fn () => number_format(
                round((float) $this->sell_price * (1 + (float) $this->tva_rate / 100), 2),
                2, '.', '',
            ),
        );
    }
}
