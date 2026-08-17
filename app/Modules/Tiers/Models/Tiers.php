<?php

namespace App\Modules\Tiers\Models;

use App\Core\Tenancy\BelongsToTenant;
use Database\Factories\TiersFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code', 'name', 'is_client', 'is_supplier', 'is_prospect', 'lead_source', 'converti_at',
    'ice', 'if_number', 'rc', 'patente', 'cnss',
    'address', 'city', 'postal_code', 'country',
    'phone', 'email', 'website', 'contact_name',
    'notes', 'is_active',
])]
class Tiers extends Model
{
    /** @use HasFactory<TiersFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** Origines de lead d'un prospect (colonne libre validée par Rule::in). */
    public const LEAD_SOURCES = [
        'site_web', 'salon', 'recommandation', 'appel_entrant',
        'prospection', 'reseaux_sociaux', 'autre',
    ];

    protected $table = 'tiers';

    protected function casts(): array
    {
        return [
            'is_client' => 'boolean',
            'is_supplier' => 'boolean',
            'is_prospect' => 'boolean',
            'is_active' => 'boolean',
            'converti_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TiersFactory
    {
        return TiersFactory::new();
    }
}
