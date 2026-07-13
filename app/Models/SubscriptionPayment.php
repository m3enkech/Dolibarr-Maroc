<?php

namespace App\Models;

use App\Core\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paiement d'abonnement encaissé par la plateforme (suivi manuel).
 * N'utilise pas le scope tenant : géré par le superadmin cross-tenant.
 */
#[Fillable([
    'tenant_id', 'amount', 'method', 'paid_at', 'period_start', 'period_end',
    'reference', 'note', 'recorded_by',
])]
class SubscriptionPayment extends Model
{
    public const METHODS = ['virement', 'cmi', 'cheque', 'especes', 'autre'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
