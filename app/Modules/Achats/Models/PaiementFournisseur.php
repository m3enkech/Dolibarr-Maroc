<?php

namespace App\Modules\Achats\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_achat_id', 'date_paiement', 'montant', 'mode', 'reference', 'note'])]
class PaiementFournisseur extends Model
{
    use BelongsToTenant;

    public const MODES = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    protected $table = 'paiements_fournisseur';

    protected function casts(): array
    {
        return [
            'date_paiement' => 'date:Y-m-d',
            'montant' => 'decimal:2',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentAchat::class, 'document_achat_id');
    }
}
