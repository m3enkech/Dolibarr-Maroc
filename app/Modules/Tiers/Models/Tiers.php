<?php

namespace App\Modules\Tiers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Database\Factories\TiersFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'is_client', 'is_supplier',
    'ice', 'if_number', 'rc', 'patente', 'cnss',
    'address', 'city', 'postal_code', 'country',
    'phone', 'email', 'website', 'contact_name',
    'notes', 'is_active',
])]
class Tiers extends Model
{
    /** @use HasFactory<TiersFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $table = 'tiers';

    protected function casts(): array
    {
        return [
            'is_client' => 'boolean',
            'is_supplier' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): TiersFactory
    {
        return TiersFactory::new();
    }
}
